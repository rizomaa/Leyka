<?php if( !defined('WPINC') ) die;
/**
 * Leyka Gateways API
 **/

/**
 * Functions to register and deregister a gateway
 **/
function leyka_add_gateway($class_name) {
    leyka()->add_gateway($class_name);
}

function leyka_remove_gateway($class_name) {
    leyka()->remove_gateway($class_name);
}

function leyka_get_gateways() {
	return leyka()->get_gateways();
}

/**
 * @param mixed $activity True to select only active PMs, false for only non-active ones,
 * NULL for both types altogether.
 * @param $currency mixed
 * @return array
 */
function leyka_get_pm_list($activity = null, $currency = false, $sorted = true) {

    $pm_list = array();

    if($sorted) {

        $pm_order = explode('pm_order[]=', leyka_options()->opt('pm_order'));
        array_shift($pm_order);

        foreach($pm_order as $pm) {

            $pm = leyka_get_pm_by_id(str_replace(array('&amp;', '&'), '', $pm), true);
            if( !$pm ) {
                continue;
            }

            if( ( !$activity || $pm->active == $activity ) && ( !$currency || $pm->has_currency_support($currency) ) ) {
                $pm_list[] = $pm;
            }
        }

    } else {

        foreach(leyka()->get_gateways() as $gateway) { /** @var Leyka_Gateway $gateway */
            $pm_list = array_merge($pm_list, $gateway->get_payment_methods($activity, $currency));
        }
    }

    return apply_filters('leyka_active_pm_list', $pm_list, $activity, $currency);
}

/**
 * @param $pm_id string
 * @param $is_full_id boolean
 * @return Leyka_Payment_Method Or false, if no PM found.
 */
function leyka_get_pm_by_id($pm_id, $is_full_id = false) {

    $pm = false;
    if($is_full_id) {
		
		$id = explode('-', $pm_id);
        $gateway = leyka_get_gateway_by_id(reset($id)); // Otherwise error in PHP 5.4.0
        if( !$gateway ) {
            return false;
        }

        $pm = $gateway->get_payment_method_by_id(end($id));

    } else {

        foreach(leyka()->get_gateways() as $gateway) { /** @var Leyka_Gateway $gateway */

            $pm = $gateway->get_payment_method_by_id($pm_id);
            if($pm) {
                break;
            }
        }
    }

    return $pm;
}

/**
 * @param $gateway_id string
 * @return Leyka_Gateway
 */
function leyka_get_gateway_by_id($gateway_id) {

    foreach(leyka()->get_gateways() as $gateway) { /** @var Leyka_Gateway $gateway */

        if($gateway->id == $gateway_id)
            return $gateway;
    }
}

abstract class Leyka_Gateway {

    /** @var $_instance Leyka_Gateway Gateway is always a singleton */
    protected static $_instance;

	protected $_id = ''; // A unique string, as "quittance", "yandex" or "chronopay"
	protected $_title = ''; // A human-readable title of gateway, a "Bank quittances" or "Yandex.money"
    protected $_icon = ''; // A gateway icon URL. Must have 25px on a bigger side
    protected $_docs_link = ''; // A link to gateways user docs page
    protected $_admin_ui_column = 2; // 1 or 2. A number of the metaboxes columns, to which gateway belogns by default
    protected $_admin_ui_order = 100; // Default sorting index for gateway metabox in its column. Lower number = higher
    protected $_payment_methods = array(); // Supported PMs array
    protected $_options = array(); // Gateway configs

    protected function __construct() {

        // A gateway icon is an attribute that is persistent for all gateways, it's just changing values:
        $this->_icon = apply_filters(
            'leyka_icon_'.$this->_gateway_id,
            file_exists(LEYKA_PLUGIN_DIR."/gateways/{$this->_id}/icons/{$this->_id}.png") ?
                LEYKA_PLUGIN_BASE_URL."/gateways/{$this->_id}/icons/{$this->_id}.png" :
                '' /** @todo Set an URL to the anonymous gateway icon?? */
        );

        $this->_set_attributes(); // Initialize main Gateway's attributes

        $this->_set_options_defaults(); // Set configurable options in admin area

        $this->_set_gateway_pm_list(); // Initialize or restore Gateway's PMs list and all their options

        do_action('leyka_initialize_gateway', $this, $this->_id); // So one could change some of gateway's attributes

        // Set a gateway class method to process a service calls from gateway:
        add_action('leyka_service_call-'.$this->_id, array($this, '_handle_service_calls'));
        add_action('leyka_cancel_recurrents-'.$this->_id, array($this, 'cancel_recurrents'));
        add_action('leyka_do_recurring_donation-'.$this->_id, array($this, 'do_recurring_donation'));

        add_action("leyka_{$this->_id}_save_donation_data", array($this, 'save_donation_specific_data'));
        add_action("leyka_{$this->_id}_add_donation_specific_data", array($this, 'add_donation_specific_data'), 10, 2);

        add_filter('leyka_get_unknown_donation_field', array($this, 'get_specific_data_value'), 10, 3);
        add_action('leyka_set_unknown_donation_field', array($this, 'set_specific_data_value'), 10, 3);
    }

    final protected function __clone() {}

    public final static function get_instance() {

        if( !static::$_instance ) {

            static::$_instance = new static();
            static::$_instance->_initialize_options();

            add_action('leyka_enqueue_scripts', array(static::$_instance, 'enqueue_gateway_scripts'));

            add_action('leyka_payment_form_submission-'.static::$_instance->id, array(static::$_instance, 'process_form'), 10, 4);
            add_action('leyka_payment_form_submission-'.static::$_instance->id, array(static::$_instance, 'process_form_default'), 100, 4);
            add_action('leyka_log_donation-'.static::$_instance->id, array(static::$_instance, 'log_gateway_fields'));

            add_filter('leyka_submission_redirect_url-'.static::$_instance->id, array(static::$_instance, 'submission_redirect_url'), 10, 2);
            add_filter('leyka_submission_form_data-'.static::$_instance->id, array(static::$_instance, 'submission_form_data'), 10, 3);
        }

        return static::$_instance;
    }

    public function __get($param) {

        switch($param) {
            case 'id': return $this->_id;
            case 'title':
            case 'name':
            case 'label': return $this->_title;
            case 'docs':
            case 'docs_url':
            case 'docs_href':
            case 'docs_link': return $this->_docs_link ? $this->_docs_link : false;
            case 'admin_column':
            case 'admin_ui_column': return in_array($this->_admin_ui_column, array(1, 2)) ? $this->_admin_ui_column : 2;
            case 'admin_order':
            case 'admin_priority':
            case 'admin_ui_order':
            case 'admin_ui_priority': return (int)$this->_admin_ui_order;
            case 'icon': $icon = false;
                if($this->_icon) {
                    $icon = $this->_icon;
                } elseif(file_exists(LEYKA_PLUGIN_DIR."gateways/{$this->_id}/icons/{$this->_id}.png")) {
                    $icon = LEYKA_PLUGIN_BASE_URL."gateways/{$this->_id}/icons/{$this->_id}.png";
                }
                return $icon;
            default:
        }
    }

    public function get_options_names() {

        $option_names = array();
        foreach($this->_options as $option_name => $params) {
            $option_names[] = $option_name;
        }

        return $option_names;
    }

    /** Allocate gateway options, if needed */
    public function allocate_gateway_options($options) {

        $gateway_section_index = -1;
        foreach($options as $index => $option) {
            if( !empty($option['section']) && $option['section']['name'] == $this->_id ) {
                $gateway_section_index = $index;
                break;
            }
        }

        $gateway_options_names = $this->get_options_names();
        if($gateway_section_index < 0) {
            $options[] = array('section' => array(
                'name' => $this->_id,
                'title' => $this->_title,
                'is_default_collapsed' => false,
                'options' => $gateway_options_names
            ));
        } else {
            $options[$gateway_section_index]['section']['options'] = array_unique(array_merge(
                $gateway_options_names,
                $options[$gateway_section_index]['section']['options']
            ));
        }

        return $options;
    }

    /** Register a gateway in the plugin */
    public function add_gateway() {
        leyka()->add_gateway(self::get_instance());
    }

    /** Register a gateway's scripts in the plugin */
    public function enqueue_gateway_scripts() {
    }

    abstract protected function _set_attributes(); // Attributes are constant, like id, title, etc.
    abstract protected function _set_options_defaults(); // Options are admin configurable parameters
    abstract protected function _initialize_pm_list(); // PM list is specific for each Gateway

    // Handler for Gateway's service calls (activate the donations, etc.):
    abstract public function _handle_service_calls($call_type = '');

    /** Default behavior, may be substituted in descendants: */
    public function get_init_recurrent_donation($donation) {

        if(is_a($donation, 'Leyka_Donation')) {
            return $donation->init_recurring_donation_id;
        } elseif( !empty($donation) && (int)$donation > 0 ) {

            $donation = new Leyka_Donation($donation);

            return $donation->init_recurring_donation_id;

        } else {
            return false;
        }
    }

    // Handler for Gateway's procedure for stopping some recurrent donations subscription:
    public function cancel_recurrents(Leyka_Donation $donation) {
    }

    /**
     * Handler for Gateway's procedure for doing new rebill on recurring donations subscription.
     * @param Leyka_Donation $init_recurring_donation
     * @return mixed False if donation weren't made, new recurring Leyka_Donation object otherwise.
     */
    public function do_recurring_donation(Leyka_Donation $init_recurring_donation) {
        return false;
    }

    // Handler to use Gateway's responses in Leyka UI:
    abstract public function get_gateway_response_formatted(Leyka_Donation $donation);

    protected function _get_gateway_pm_list($pm_id = false) {

        return $pm_id ? array_keys($this->_payment_methods, $pm_id) : array_keys($this->_payment_methods);
    }

    protected function _set_gateway_pm_list() {

        $this->_initialize_pm_list();
        do_action('leyka_init_pm_list', $this);
    }

    protected function _initialize_options() {

        foreach($this->_options as $option_name => $params) {

            if( !leyka_options()->option_exists($option_name) ) {
                leyka_options()->add_option($option_name, $params['type'], $params);
            }
        }

        add_filter('leyka_payment_options_allocation', array($this, 'allocate_gateway_options'), 1, 1);
    }

    abstract public function process_form($gateway_id, $pm_id, $donation_id, $form_data);

    abstract public function submission_redirect_url($current_url, $pm_id);

    abstract public function submission_form_data($form_data_vars, $pm_id, $donation_id);

    abstract public function log_gateway_fields($donation_id);

    static public function process_form_default($gateway_id, $pm_id, $donation_id, $form_data) {

        if(empty($form_data['leyka_donation_amount']) || (float)$form_data['leyka_donation_amount'] <= 0) {

            $error = new WP_Error(
                'wrong_donation_amount',
                __('Donation amount must be specified to submit the form', 'leyka')
            );
            leyka()->add_payment_form_error($error);
        }

        $currency = $form_data['leyka_donation_currency'];
        if(empty($currency)) {

            $error = new WP_Error(
                'wrong_donation_currency',
                __('Wrong donation currency in submitted form data', 'leyka')
            );
            leyka()->add_payment_form_error($error);
        }

        if(
            !empty($form_data['top_'.$currency]) &&
            $form_data['leyka_donation_amount'] > $form_data['top_'.$currency]
        ) {
            $top_amount_allowed = $form_data['top_'.$currency];
            $error = new WP_Error(
                'donation_amount_too_great',
                sprintf(
                    __('Donation amount you entered is too great (maximum %s allowed)', 'leyka'),
                    $top_amount_allowed.' '.leyka_options()->opt("currency_{$currency}_label")
                )
            );
            leyka()->add_payment_form_error($error);
        }

        if(
            !empty($form_data['bottom_'.$currency]) &&
            $form_data['leyka_donation_amount'] < $form_data['bottom_'.$currency]
        ) {
            $bottom_amount_allowed = $form_data['bottom_'.$currency];
            $error = new WP_Error(
                'donation_amount_too_small',
                sprintf(
                    __('Donation amount you entered is too small (minimum %s allowed)', 'leyka'),
                    $bottom_amount_allowed.' '.leyka_options()->opt("currency_{$currency}_label")
                )
            );
            leyka()->add_payment_form_error($error);
        }

        if(empty($form_data['leyka_agree']) && leyka_options()->opt('agree_to_terms_needed')) {
            $error = new WP_Error('terms_not_agreed', __('You must agree to the terms of donation service', 'leyka'));
            leyka()->add_payment_form_error($error);
        }
    }

    /**
     * @param Leyka_Payment_Method $pm New PM to add to a gateway.
     * @param bool $replace_if_exists True to replace an existing PM (if it exists). False by default.
     * @return bool True if PM was added/replaced, false otherwise.
     */
    public function add_payment_method(Leyka_Payment_Method $pm, $replace_if_exists = false) {

        if($pm->gateway_id != $this->_id) {
            return false;
        }

        if(empty($this->_payment_methods[$pm->id]) || !!$replace_if_exists) {
            $this->_payment_methods[$pm->id] = $pm;
            return true;
        }

        return false;
    }

    /** @param mixed $pm A PM object or it's ID to remove from gateway. */
    public function remove_payment_method($pm) {

        if(is_object($pm) && $pm instanceof Leyka_Payment_Method) {
            unset($this->_payment_methods[$pm->id]);
        } else if(strlen($pm) && !empty($this->_payment_methods[$pm])) {
            unset($this->_payment_methods[$pm->id]);
        }
    }

    /**
     * @param mixed $activity True to select only active PMs, false for only non-active ones,
     * NULL for both types altogether.
     * @param mixed $currency
     * @return array Of Leyka_Payment_Method objects.
     */
    public function get_payment_methods($activity = null, $currency = false) {

        $pm_list = array();
        foreach($this->_payment_methods as $pm_name => $pm) {

            /** @var $pm Leyka_Payment_Method */
            if((($activity || $activity === null) && $pm->is_active) || empty($activity)) {

                if(empty($currency)) {
                    $pm_list[] = $pm;
                } elseif($currency && $pm->has_currency_support($currency)) {
                    $pm_list[] = $pm;
                }
            }
        }

        return $pm_list;
    }

    /**
     * @param string $pm_id
     * @return Leyka_Payment_Method Object, or false if it's not found. 
     */
    public function get_payment_method_by_id($pm_id) {

        $pm_id = trim((string)$pm_id);
        return empty($this->_payment_methods[$pm_id]) ? false : $this->_payment_methods[$pm_id]; 
    }

    /** Get gateway specific donation fields for an "add/edit donation" page ("donation data" metabox). */
    public function display_donation_specific_data_fields($donation = false) {
    }

    /** For "leyka_get_unknown_donation_field" filter hook, to get gateway specific donation data values. */
    public function get_specific_data_value($value, $field_name, Leyka_Donation $donation) {
        return $value;
    }

    /** For "leyka_set_unknown_donation_field" action hook, to set gateway specific donation data values. */
    public function set_specific_data_value($field_name, $value, Leyka_Donation $donation) {
    }

    /** To save gateway specific fields when donation editing page is being saved */
    public function save_donation_specific_data(Leyka_Donation $donation) {
    }

    /** Action called when new donation (Leyka_Donation::add()) is being created to add gateway-specific fields. */
    public function add_donation_specific_data($donation_id, array $donation_params) {
    }
} //class end

/**
 * Class Leyka_Payment_Method
 */
abstract class Leyka_Payment_Method {

    /** @var $_instance Leyka_Payment_Method PM is always a singleton */
    protected static $_instance;

    protected $_id = '';
    protected $_gateway_id = '';
    protected $_active = true;
    protected $_label = '';
    protected $_label_backend = '';
    protected $_description = '';
    protected $_global_fields = array();
    protected $_support_global_fields = true;
    protected $_custom_fields = array();
    protected $_icons = array();
    protected $_submit_label = '';
    protected $_supported_currencies = array();
    protected $_default_currency = '';
    protected $_options = array();

    public final static function get_instance() {

        if(null == static::$_instance) {

            static::$_instance = new static();
            static::$_instance->_initialize_options();
        }

        return static::$_instance;
    }

    final protected function __clone() {}

    protected function __construct() {

        $this->_submit_label = leyka_options()->opt_safe('donation_submit_text');

        $this->_set_attributes();
        $this->_initialize_options();
        $this->_set_dynamic_attributes();
    }

    public function __get($param) {

        switch($param) {
            case 'id': $param = $this->_id; break;
            case 'full_id': $param = $this->_gateway_id.'-'.$this->_id; break;
            case 'gateway_id': $param = $this->_gateway_id; break;
            case 'active':
            case 'is_active': $param = $this->_active; break;
            case 'label':
            case 'title':
            case 'name':
                $param = leyka_options()->opt_safe($this->full_id.'_label');
                $param = apply_filters(
                    'leyka_get_pm_label',
                    $param && $param != $this->_label ? $param : $this->_label,
                    $this
                );
                break;
            case 'label_backend':
            case 'title_backend':
            case 'name_backend': $param = $this->_label_backend ? $this->_label_backend : $this->_label;
                break;
            case 'desc':
            case 'description': $param = html_entity_decode($this->_description); break;
            case 'has_global_fields': $param = $this->_support_global_fields; break;
//            case 'global_fields': $param = $this->_global_fields ? $this->_global_fields; break;
            case 'custom_fields': $param = $this->_custom_fields ? $this->_custom_fields : array(); break;
            case 'icons': $param = $this->_icons; break;
            case 'submit_label': $param = $this->_submit_label; break;
            case 'currencies': $param = $this->_supported_currencies; break;
            case 'default_currency': $param = $this->_default_currency; break;
            default:
//                trigger_error('Error: unknown param "'.$param.'"');
                $param = null;
        }

        return $param;
    }

    abstract protected function _set_attributes();

    /** To set some custom options-dependent attributes */
    protected function _set_dynamic_attributes() {}

    public function has_currency_support($currency = false) {

        if( !$currency ) {
            return true;
        } elseif(is_array($currency) && !array_diff($currency, $this->_supported_currencies)) {
            return true;
        } elseif(in_array($currency, $this->_supported_currencies)) {
            return true;
        } else {
            return false;
        }
    }

    abstract protected function _set_options_defaults();

    protected final function _add_options() {

        foreach($this->_options as $option_name => $params) {

            if( !leyka_options()->option_exists($option_name) ) {
                leyka_options()->add_option($option_name, $params['type'], $params);
            }
        }
    }

    protected function _initialize_options() {

        $this->_set_options_defaults();

        $this->_add_options();

        /** PM frontend label is a special persistent option, universal for each PM */
        if( !leyka_options()->option_exists($this->full_id.'_label') ) {

            leyka_options()->add_option($this->full_id.'_label', 'text', array(
                'value' => '',
                'default' => $this->_label,
                'title' => __('Payment method custom label', 'leyka'),
                'description' => __('A label for this payment method that will appear on all donation forms.', 'leyka'),
                'required' => false,
                'placeholder' => '',
                'validation_rules' => array(), // List of regexp?..
            ));
        }

        $custom_label = leyka_options()->opt_safe($this->full_id.'_label');
        $this->_label = $custom_label && $custom_label != $this->_label ?
            $custom_label : apply_filters('leyka_get_pm_label_original', $this->_label, $this);

        $this->_active = in_array($this->full_id, leyka_options()->opt('pm_available'));

        $this->_description = leyka_options()->opt_safe($this->full_id.'_description');

        add_filter('leyka_payment_options_allocation', array($this, 'allocate_pm_options'), 10, 1);
    }

    public function get_pm_options_names() {

        $option_names = array();
        foreach($this->_options as $option_name => $params) {
            $option_names[] = $option_name;
        }

        return $option_names;
    }

    /** Allocate gateway options, if needed */
    public function allocate_pm_options($options) {

        $gateway = leyka_get_gateway_by_id($this->_gateway_id); 
        $gateway_section_index = -1;

        foreach($options as $index => $option) {

            if( !empty($option['section']) && $option['section']['name'] == $gateway->id ) {
                $gateway_section_index = $index;
                break;
            }
        }

        $pm_options_names = $this->get_pm_options_names();
        $pm_options_names[] = $this->full_id.'_label';

        if($gateway_section_index < 0) {
            $options[] = array('section' => array(
                'name' => $gateway->id,
                'title' => $gateway->title,
                'is_default_collapsed' => false,
                'options' => $pm_options_names,
            ));
        } else {
            $options[$gateway_section_index]['section']['options'] = array_unique(array_merge(
                $pm_options_names,
                $options[$gateway_section_index]['section']['options']
            ));
        }

        return $options;
    }
} // Leyka_Payment_Method end