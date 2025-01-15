<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Generic Payment Gateway class for WooCommerce
 */
class WC_Generic_Payment_Gateway extends WC_Payment_Gateway {
    
    /**
     * Required settings for the gateway to function
     */
    private $required_settings = [
        'account_uuid' => 'Account UUID',
        'client_id' => 'Client ID',
        'client_secret' => 'Client Secret',
        'api_endpoint' => 'API Endpoint',
    ];

    /**
     * Constructor for the gateway.
     * 
     * Initializes the gateway's properties and sets up WordPress hooks.
     */
    public function __construct() {
        $this->id = 'generic_payment_gateway';
        $this->icon = GENERIC_PAYMENT_GATEWAY_URL . 'assets/images/icon.png';
        $this->has_fields = true;
        $this->method_title = __('Generic Payment Gateway', 'generic-payment-gateway');
        $this->method_description = __('Custom payment gateway description', 'generic-payment-gateway');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Define properties
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        // Add custom scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        // Add filter for AJAX response
        add_filter('woocommerce_payment_successful_result', array($this, 'modify_successful_payment_result'), 10, 2);

        // Replace the thankyou hook with the before_thankyou hook
        remove_action('woocommerce_thankyou_' . $this->id, array($this, 'add_payment_url_to_thankyou_page'));
        add_action('woocommerce_before_thankyou', array($this, 'add_payment_url_to_thankyou_page'));
    }

    /**
     * Initialize form fields for the gateway settings.
     * 
     * Sets up the admin configuration fields for the gateway.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'generic-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Generic Payment Gateway', 'generic-payment-gateway'),
                'default' => 'no'
            ),
            'require_login' => array(
                'title' => __('Require Login', 'generic-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Require customers to login before checkout', 'generic-payment-gateway'),
                'default' => 'no',
                'description' => __('If enabled, customers must login to their payment account before completing checkout.', 'generic-payment-gateway'),
            ),
            'title' => array(
                'title' => __('Title', 'generic-payment-gateway'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'generic-payment-gateway'),
                'default' => __('eTransfer', 'generic-payment-gateway'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'generic-payment-gateway'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'generic-payment-gateway'),
                'default' => __('Pay with eTransfer', 'generic-payment-gateway'),
                'desc_tip' => true,
            ),
            'account_uuid' => array(
                'title' => __('Account UUID', 'generic-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter your account UUID.', 'generic-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'client_id' => array(
                'title' => __('Client ID', 'generic-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter your client ID.', 'generic-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'client_secret' => array(
                'title' => __('Client Secret', 'generic-payment-gateway'),
                'type' => 'password',
                'description' => __('Enter your client secret.', 'generic-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
            ),
            'api_endpoint' => array(
                'title' => __('API Endpoint', 'generic-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter the API endpoint URL (e.g., https://api.example.com/v1/).', 'generic-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => 'https://',
            ),
        );
    }

    /**
     * Process the payment at checkout.
     * 
     * @param int $order_id The order ID to process payment for.
     * @return array Result array with success/redirect parameters.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        try {
            // Initialize API client with new credentials
            $api_client = new Generic_Payment_Gateway_API_Client(
                $this->get_option('client_id'),
                $this->get_option('client_secret'),
                $this->get_option('api_endpoint'),
                $this->get_option('account_uuid')
            );

            // Request e-transfer link
            $response = $api_client->request_etransfer_link($order);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            if (!isset($response['success']) || !$response['success'] || !isset($response['data']['url'])) {
                throw new Exception(__('Invalid response from payment gateway', 'generic-payment-gateway'));
            }

            // Update order status
            $order->update_status('pending', __('Awaiting e-transfer payment', 'generic-payment-gateway'));
            
            // Store payment details in order metadata
            $order->update_meta_data('_payment_url', $response['data']['url']);
            $order->update_meta_data('_transaction_reference', $response['data']['transaction']['reference']);
            $order->update_meta_data('_transaction_status', $response['data']['transaction']['status']);
            $order->update_meta_data('_transaction_date', $response['data']['transaction']['created_at']);
            
            // Set transaction ID in WooCommerce
            $order->set_transaction_id($response['data']['transaction']['reference']);
            
            $order->save();
            
            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Empty cart
            WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_order_received_url()
            );
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure',
                'messages' => $e->getMessage()
            );
        }
    }

    /**
     * Enqueue payment scripts and styles for the checkout page.
     */
    public function payment_scripts() {
        if (!is_checkout()) {
            return;
        }

        // Only load if this gateway is selected
        if ($this->enabled !== 'yes') {
            return;
        }

        $this->register_gateway_assets();

        // Enqueue checkout assets
        wp_enqueue_style('generic-payment-gateway-checkout');
        wp_enqueue_script('generic-payment-gateway-checkout');

        wp_localize_script('generic-payment-gateway-checkout', 'genericGateway', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('generic-payment-gateway')
        ));

        // Debug output
        error_log('Payment gateway scripts enqueued');
    }

    /**
     * Output payment fields on the checkout page.
     * 
     * Renders the payment form template with login status.
     */
    public function payment_fields() {
        $api_client = new Generic_Payment_Gateway_API_Client(
            $this->get_option('client_id'),
            $this->get_option('client_secret'),
            $this->get_option('api_endpoint'),
            $this->get_option('account_uuid')
        );
        
        Generic_Payment_Gateway_Template_Loader::load('payment-form', array(
            'is_logged_in' => $this->is_user_logged_in_to_gateway(),
            'require_login' => $this->get_option('require_login') === 'yes',
            'signup_url' => $api_client->get_base_url() . '/customer/register',
            'reset_password_url' => $api_client->get_base_url() . '/customer/password-reset/request'
        ));
    }

    /**
     * Check if user is logged in to the payment gateway.
     * 
     * @return bool True if user is logged in, false otherwise.
     */
    private function is_user_logged_in_to_gateway() {
        return WC()->session ? WC()->session->get('gateway_user_logged_in', false) : false;
    }

    /**
     * Validate the payment fields on the checkout page.
     * 
     * @return bool True if validation passes, false otherwise.
     */
    public function validate_fields() {
        if ($this->get_option('require_login') === 'yes' && !$this->is_user_logged_in_to_gateway()) {
            wc_add_notice(__('Please login to your payment account first.', 'generic-payment-gateway'), 'error');
            return false;
        }
        return true;
    }

    /**
     * Get the configuration for gateway assets.
     * 
     * @return array Array of script and style configurations.
     */
    private function get_gateway_assets() {
        return array(
            'styles' => array(
                'checkout' => array(
                    'src'     => 'css/checkout.css',
                    'deps'    => array(),
                    'version' => GENERIC_PAYMENT_GATEWAY_VERSION,
                ),
                'admin' => array(
                    'src'     => 'css/admin.css',
                    'deps'    => array(),
                    'version' => GENERIC_PAYMENT_GATEWAY_VERSION,
                ),
            ),
            'scripts' => array(
                'checkout' => array(
                    'src'     => 'js/checkout.js',
                    'deps'    => array('jquery'),
                    'version' => GENERIC_PAYMENT_GATEWAY_VERSION,
                    'in_footer' => true,
                ),
                'admin' => array(
                    'src'     => 'js/admin.js',
                    'deps'    => array('jquery'),
                    'version' => GENERIC_PAYMENT_GATEWAY_VERSION,
                    'in_footer' => true,
                ),
            ),
        );
    }

    /**
     * Register all gateway assets with WordPress.
     * 
     * Registers both scripts and styles for later enqueuing.
     */
    private function register_gateway_assets() {
        $assets = $this->get_gateway_assets();
        
        foreach ($assets['styles'] as $handle => $style) {
            wp_register_style(
                "generic-payment-gateway-{$handle}",
                GENERIC_PAYMENT_GATEWAY_URL . 'assets/' . $style['src'],
                $style['deps'],
                $style['version']
            );
        }
        
        foreach ($assets['scripts'] as $handle => $script) {
            wp_register_script(
                "generic-payment-gateway-{$handle}",
                GENERIC_PAYMENT_GATEWAY_URL . 'assets/' . $script['src'],
                $script['deps'],
                $script['version'],
                $script['in_footer']
            );
        }
    }

    /**
     * Enqueue admin scripts and styles.
     * 
     * Loads necessary assets on the WooCommerce settings page.
     */
    public function admin_scripts() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, array('woocommerce_page_wc-settings'))) {
            return;
        }

        $this->register_gateway_assets();
        
        wp_enqueue_style('generic-payment-gateway-admin');
        wp_enqueue_script('generic-payment-gateway-admin');
    }

    public function modify_successful_payment_result($result, $order_id) {
        if ($result['result'] === 'success' && isset($result['payment_url'])) {
            // Ensure the payment URL is passed to the frontend
            return array(
                'result' => 'success',
                'redirect' => $result['redirect'],
                'payment_url' => $result['payment_url']
            );
        }
        return $result;
    }

    public function add_payment_url_to_thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        // Only show for our payment method
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        $payment_url = $order->get_meta('_payment_url');
        
        if ($payment_url) {
            ?>
            <div class="payment-button-container">
                <p><?php _e('If a new tab did not open automatically, click the button below to complete your payment:', 'generic-payment-gateway'); ?></p>
                <button onclick="window.open('<?php echo esc_js($payment_url); ?>', '_blank')" class="button alt">
                    <?php _e('Complete Payment', 'generic-payment-gateway'); ?>
                </button>
            </div>
            <script>
                window.paymentUrl = '<?php echo esc_js($payment_url); ?>';
                window.open(window.paymentUrl, '_blank');
            </script>
            <?php
        }
    }

    /**
     * Check if this gateway is available for use
     *
     * @return bool
     */
    public function is_available() {
        $is_available = parent::is_available();

        if (!$is_available) {
            return false;
        }

        foreach ($this->required_settings as $setting => $label) {
            if (empty($this->get_option($setting))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Admin Panel Options
     */
    public function admin_options() {
        $missing_settings = [];

        foreach ($this->required_settings as $setting => $label) {
            if (empty($this->get_option($setting))) {
                $missing_settings[] = __($label, 'generic-payment-gateway');
            }
        }

        if (!empty($missing_settings)) {
            echo '<div class="notice notice-warning inline"><p>';
            echo sprintf(
                __('Warning: The following required settings are missing: %s', 'generic-payment-gateway'),
                implode(', ', $missing_settings)
            );
            echo '</p></div>';
        }

        parent::admin_options();
    }

    /**
     * Process admin options and clear OAuth token if credentials change.
     *
     * @return bool
     */
    public function process_admin_options() {
        $old_client_id = $this->get_option('client_id');
        $old_client_secret = $this->get_option('client_secret');
        $old_api_endpoint = $this->get_option('api_endpoint');
        
        $result = parent::process_admin_options();
        
        // Check if any of the API credentials have changed
        if ($old_client_id !== $this->get_option('client_id') ||
            $old_client_secret !== $this->get_option('client_secret') ||
            $old_api_endpoint !== $this->get_option('api_endpoint')) {
            
            // Delete the stored OAuth token
            delete_option('generic_payment_gateway_oauth_token');
        }
        
        return $result;
    }

}
