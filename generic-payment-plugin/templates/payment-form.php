<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<div class="generic-payment-form" data-require-login="<?php echo esc_attr($require_login ? 'true' : 'false'); ?>">
    <?php if ($require_login): ?>
        <?php if (!$is_logged_in): ?>
            <?php _e('Please login to your payment account to continue:', 'generic-payment-gateway'); ?>
            <div class="form-row mt-5">
                <label for="gateway_username"><?php _e('Email', 'generic-payment-gateway'); ?></label>
                <input type="text" id="gateway_username" name="gateway_username" required>
            </div>
            <div class="form-row">
                <label for="gateway_password"><?php _e('Password', 'generic-payment-gateway'); ?></label>
                <input type="password" id="gateway_password" name="gateway_password" required>
            </div>
            <div class="form-row button-group">
                <button type="button" id="gateway-login-btn" class="button gateway-login-btn"><?php _e('Login', 'generic-payment-gateway'); ?></button>
                <a href="<?php echo esc_url($signup_url); ?>" target="_blank" class="signup-link"><?php _e('Or create an account', 'generic-payment-gateway'); ?></a>
            </div>
            <a href="<?php echo esc_url($reset_password_url); ?>" target="_blank" class="reset-password-link"><?php _e('Forgot password?', 'generic-payment-gateway'); ?></a>
            <div id="gateway-login-message"></div>
        <?php else: ?>
            <p><?php _e('You are logged in to your payment account.', 'generic-payment-gateway'); ?></p>
            <button type="button" id="gateway-logout-btn" class="button"><?php _e('Logout', 'generic-payment-gateway'); ?></button>
        <?php endif; ?>
    <?php endif; ?>
    
    <?php if (!$require_login || $is_logged_in): ?>
        <div class="payment-instructions">
            <p><?php _e('Next steps:', 'generic-payment-gateway'); ?></p>
            <ol>
                <li><?php _e('Click "Place Order" to proceed with your purchase', 'generic-payment-gateway'); ?></li>
                <li><?php _e('You will be redirected to the e-transfer checkout page in a new tab', 'generic-payment-gateway'); ?></li>
                <li><?php _e('Follow the instructions to complete your e-transfer payment', 'generic-payment-gateway'); ?></li>
                <li><?php _e('Please note: Orders will be automatically cancelled if payment is not received', 'generic-payment-gateway'); ?></li>
            </ol>
        </div>
    <?php endif; ?>
</div>
