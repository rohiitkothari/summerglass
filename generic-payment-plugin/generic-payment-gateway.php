<?php
/**
 * Plugin Name: Generic Payment Gateway for WooCommerce
 * Plugin URI: https://yourwebsite.com
 * Description: Custom payment gateway integration for WooCommerce
 * Version: 1.0.3
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: generic-payment-gateway
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Declare to woocommerce that we are compatible with HPOS
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Define plugin constants
define('GENERIC_PAYMENT_GATEWAY_VERSION', '1.0.2');
define('GENERIC_PAYMENT_GATEWAY_PATH', plugin_dir_path(__FILE__));
define('GENERIC_PAYMENT_GATEWAY_URL', plugin_dir_url(__FILE__));

// Main plugin class
class Generic_Payment_Gateway {
    public function __construct() {
        // Move session initialization to init hook
        add_action('init', array($this, 'init_woocommerce_session'));
        
        // Move plugins_loaded to a lower priority to ensure WooCommerce is loaded
        add_action('plugins_loaded', array($this, 'init'), 20);
        
        // AJAX handlers
        add_action('wp_ajax_gateway_login', array($this, 'handle_gateway_login'));
        add_action('wp_ajax_nopriv_gateway_login', array($this, 'handle_gateway_login'));
        add_action('wp_ajax_gateway_logout', array($this, 'handle_gateway_logout'));
        add_action('wp_ajax_nopriv_gateway_logout', array($this, 'handle_gateway_logout'));
        
        // Cron hooks
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('check_pending_transactions', array($this, 'check_pending_transactions'));
        
        // Register activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'schedule_transaction_check'));
        register_deactivation_hook(__FILE__, array($this, 'unschedule_transaction_check'));
    }

    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load text domain after init
        add_action('init', array($this, 'load_plugin_textdomain'));

        // Include required files
        $this->include_required_files();

        // Add the gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
    }

    public function load_plugin_textdomain() {
        load_plugin_textdomain(
            'generic-payment-gateway',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    private function include_required_files() {
        require_once GENERIC_PAYMENT_GATEWAY_PATH . 'includes/class-api-client.php';
        require_once GENERIC_PAYMENT_GATEWAY_PATH . 'includes/class-wc-payment-gateway.php';
        require_once GENERIC_PAYMENT_GATEWAY_PATH . 'includes/class-template-loader.php';
    }

    public function init_woocommerce_session() {
        if (WC()->session) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    public function add_gateway($gateways) {
        $gateways[] = 'WC_Generic_Payment_Gateway';
        return $gateways;
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('Generic Payment Gateway requires WooCommerce to be installed and active.', 'generic-payment-gateway'); ?></p>
        </div>
        <?php
    }

    public function handle_gateway_login() {
        try {
            // Check nonce
            if (!check_ajax_referer('generic-payment-gateway', 'nonce', false)) {
                wp_send_json_error([
                    'message' => 'Security check failed',
                    'debug' => 'Nonce verification failed'
                ]);
                return;
            }
            
            $email = sanitize_email($_POST['username']);
            $password = sanitize_text_field($_POST['password']);

            $gateway = new WC_Generic_Payment_Gateway();
            $api_client = new Generic_Payment_Gateway_API_Client(
                $gateway->get_option('client_id'),
                $gateway->get_option('client_secret'),
                $gateway->get_option('api_endpoint'),
                $gateway->get_option('account_uuid')
            );

            $response = $api_client->authenticate_user($email, $password);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    'message' => $response->get_error_message(),
                    'debug' => 'API client returned WP_Error'
                ]);
                return;
            }

            if (isset($response['success']) && $response['success']) {
                // Use WC() to access the session
                WC()->session->set('gateway_user_logged_in', true);
                wp_send_json_success([
                    'message' => $response['message']
                ]);
            } else {
                wp_send_json_error([
                    'message' => isset($response['message']) ? $response['message'] : 'Login failed',
                    'debug' => 'API returned success=false'
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'An error occurred',
                'debug' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the gateway logout AJAX request.
     */
    public function handle_gateway_logout() {
        try {
            // Verify nonce with more detailed error handling
            if (!check_ajax_referer('generic-payment-gateway', 'nonce', false)) {
                wp_send_json_error(array(
                    'message' => 'Security check failed',
                    'debug' => 'Nonce verification failed'
                ));
                return;
            }

            // Clear the session using WC()
            WC()->session->__unset('gateway_user_logged_in');
            
            wp_send_json_success(array(
                'message' => __('Logged out successfully', 'generic-payment-gateway')
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'An error occurred',
                'debug' => $e->getMessage()
            ));
        }
    }

    /**
     * Register custom cron schedule
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __('Every Minute', 'generic-payment-gateway')
        );
        return $schedules;
    }

    /**
     * Schedule the transaction check event
     */
    public function schedule_transaction_check() {
        if (!wp_next_scheduled('check_pending_transactions')) {
            wp_schedule_event(time(), 'every_minute', 'check_pending_transactions');
        }
    }

    /**
     * Unschedule the transaction check event
     */
    public function unschedule_transaction_check() {
        $timestamp = wp_next_scheduled('check_pending_transactions');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'check_pending_transactions');
        }
    }

    /**
     * Check pending transactions status
     */
    public function check_pending_transactions() {
        // Get orders with pending status and our payment method
        $orders = wc_get_orders(array(
            'status' => 'pending',
            'payment_method' => 'generic_payment_gateway',
            'limit' => -1
        ));

        if (empty($orders)) {
            return;
        }

        // Initialize API client
        $gateway = new WC_Generic_Payment_Gateway();
        $api_client = new Generic_Payment_Gateway_API_Client(
            $gateway->get_option('client_id'),
            $gateway->get_option('client_secret'),
            $gateway->get_option('api_endpoint'),
            $gateway->get_option('account_uuid')
        );

        $references = array();
        $order_map = array();

        // Collect transaction references and build order map
        foreach ($orders as $order) {
            $reference = $order->get_meta('_transaction_reference');
            if ($reference) {
                $references[] = $reference;
                $order_map[$reference] = $order;
            }
        }

        if (empty($references)) {
            return;
        }
        
        // Query transactions from API
        $transactions = $api_client->get_transactions($references);
        
        if (is_wp_error($transactions)) {
            return;
        }

        // Process transaction updates
        foreach ($transactions as $transaction) {
            $reference = $transaction['reference'];
            $status = $transaction['status'];
                        
            if (isset($order_map[$reference])) {
                $order = $order_map[$reference];
                $current_status = $order->get_meta('_transaction_status');

                // Only process if status has changed
                if ($current_status !== $status) {
                    $order->update_meta_data('_transaction_status', $status);
                    
                    if ($status === 'Approved') {
                        $order->payment_complete();
                        $order->add_order_note(__('Payment approved via e-Transfer.', 'generic-payment-gateway'));
                    } elseif ($status === 'Failed' || $status === 'Cancelled') {
                        $order->update_status('failed', __('Payment failed or cancelled.', 'generic-payment-gateway'));
                    }
                    
                    $order->save();
                }
            }
        }
    }
}

// Initialize the plugin
new Generic_Payment_Gateway(); 