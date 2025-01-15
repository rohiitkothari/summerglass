<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * API Client for the payment gateway
 */
class Generic_Payment_Gateway_API_Client {
    private $client_id;
    private $client_secret;
    private $api_endpoint;
    private $account_uuid;
    private $access_token;
    private $token_expiry;
    private $token_option_name = 'generic_payment_gateway_oauth_token';

    /**
     * Constructor
     * 
     * @param string $client_id Client ID from gateway settings
     * @param string $client_secret Client Secret from gateway settings
     * @param string $api_endpoint API endpoint from gateway settings
     * @param string $account_uuid Account UUID from gateway settings
     */
    public function __construct($client_id, $client_secret, $api_endpoint, $account_uuid) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->api_endpoint = rtrim($api_endpoint, '/');
        $this->account_uuid = $account_uuid;
        $this->load_token();
    }

    /**
     * Load saved token from WordPress options
     */
    private function load_token() {
        $token_data = get_option($this->token_option_name);
        if ($token_data) {
            $this->access_token = $token_data['access_token'];
            $this->token_expiry = $token_data['expiry'];
        }
    }

    /**
     * Save token data to WordPress options
     * 
     * @param string $access_token The access token
     * @param int $expires_in Expiration time in seconds
     */
    private function save_token($access_token, $expires_in) {
        $this->access_token = $access_token;
        $this->token_expiry = time() + $expires_in;

        update_option($this->token_option_name, [
            'access_token' => $access_token,
            'expiry' => $this->token_expiry
        ]);
    }

    /**
     * Check if the current token is valid
     * 
     * @return bool True if token is valid, false otherwise
     */
    private function is_token_valid() {
        if (empty($this->access_token) || empty($this->token_expiry)) {
            return false;
        }

        return $this->token_expiry > time();
    }

    /**
     * Get the base URL from the API endpoint
     * 
     * @return string Base URL (e.g., http://127.0.0.1:8000)
     */
    public function get_base_url() {
        $parsed_url = parse_url($this->api_endpoint);
        return $parsed_url['scheme'] . '://' . $parsed_url['host'] . 
               (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
    }

    /**
     * Get a valid access token, requesting a new one if necessary
     * 
     * @return string|WP_Error Access token or WP_Error on failure
     */
    private function get_access_token() {
        if ($this->is_token_valid()) {
            return $this->access_token;
        }

        $base_url = $this->get_base_url();
        $endpoint = $base_url . '/oauth/token';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode([
                'grant_type' => 'client_credentials',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'scope' => '*'
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token']) || !isset($body['expires_in'])) {
            return new WP_Error('invalid_oauth_response', 'Invalid response from OAuth server');
        }

        $this->save_token($body['access_token'], $body['expires_in']);
        return $this->access_token;
    }

    /**
     * Get common headers for API requests
     * 
     * @return array|WP_Error Headers array or WP_Error on failure
     */
    private function get_headers() {
        $access_token = $this->get_access_token();
        
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        return [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Request e-transfer payment link
     * 
     * @param WC_Order $order WooCommerce order object
     * @return array|WP_Error Response from API or WP_Error on failure
     */
    public function request_etransfer_link(WC_Order $order) {
        $endpoint = $this->api_endpoint . '/payment-types/interac/e-transfers/request-etransfer-link';
        
        $headers = $this->get_headers();
        if (is_wp_error($headers)) {
            return $headers;
        }

        $body = [
            'account_uuid' => $this->account_uuid,
            'email' => $order->get_billing_email(),
            'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'amount' => (float) $order->get_total(),
            'currency' => 'CAD',
            'description' => sprintf('Order #%s', $order->get_order_number())
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_body = wp_remote_retrieve_body($response);
        
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_response', 'Invalid response from payment gateway: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Get transactions for specific references. Will iterate through all pages to ensure that all transactions are retrieved.
     * 
     * @param array $references Array of transaction references to look up
     * @param int $per_page Number of results per page (default 50)
     * @return array|WP_Error Array of transaction items or WP_Error on failure
     */
    public function get_transactions($references, $per_page = 50) {
        $headers = $this->get_headers();
        if (is_wp_error($headers)) {
            return $headers;
        }

        $all_items = [];
        $current_page = 1;

        do {
            // Build query parameters
            $query_params = [
                'account_uuid' => $this->account_uuid,
                'per_page' => $per_page,
                'page' => $current_page
            ];

            // Add references as array format
            foreach ($references as $reference) {
                $query_params['references[]'] = $reference;
            }

            $endpoint = $this->api_endpoint . '/transactions?' . http_build_query($query_params, '', '&');
            
            $response = wp_remote_get($endpoint, [
                'headers' => $headers,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $response_body = wp_remote_retrieve_body($response);

            $data = json_decode($response_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_response', 'Invalid response from payment gateway: ' . json_last_error_msg());
            }

            // Check if the response is valid and has items
            if (!isset($data['success']) || !$data['success'] || !isset($data['data']['items'])) {
                return new WP_Error('invalid_response', 'Invalid response structure from payment gateway');
            }

            // Add items from this page to our collection
            $all_items = array_merge($all_items, $data['data']['items']);

            // Check if there are more pages
            $pagination = $data['data']['pagination'];
            $has_more_pages = $pagination['current_page'] < $pagination['total_pages'];
            $current_page++;

        } while ($has_more_pages);

        return $all_items;
    }

    /**
     * Authenticate user with credentials
     * 
     * The endpoint is an unauthenticated endpoint, so we don't need to include the Authorization header.
     * 
     * @param string $email User's email
     * @param string $password User's password
     * @return array|WP_Error Response array or WP_Error on failure
     */
    public function authenticate_user($email, $password) {
        $base_url = $this->get_base_url();
        $endpoint = $base_url . '/api/v1/login';
        
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode([
                'email' => $email,
                'password' => $password
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_body = wp_remote_retrieve_body($response);
        
        $data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_response', 'Invalid response from login server: ' . json_last_error_msg());
        }

        return $data;
    }
}
