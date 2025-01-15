<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Generic_Payment_Gateway_Template_Loader {
    public static function load($template_name, $args = array()) {
        // Extract variables from args array to make them available in template
        if (!empty($args) && is_array($args)) {
            extract($args);
        }

        $template_path = GENERIC_PAYMENT_GATEWAY_PATH . 'templates/' . $template_name . '.php';

        if (file_exists($template_path)) {
            include $template_path;
        }
    }
}
