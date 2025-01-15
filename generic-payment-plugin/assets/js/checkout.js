jQuery(document).ready(function($) {    
    // Disable Place Order button initially if not logged in
    function togglePlaceOrderButton() {
        const isLoggedIn = sessionStorage.getItem('gateway_user_logged_in');
        const $placeOrderBtn = $('button#place_order');
        const requireLogin = $('.generic-payment-form').data('require-login');
        
        if ($('input[name="payment_method"]:checked').val() === 'generic_payment_gateway') {
            $placeOrderBtn.prop('disabled', requireLogin ? !isLoggedIn : false);
        } else {
            $placeOrderBtn.prop('disabled', false);
        }
    }

    // Handle payment method selection
    $('body').on('change', 'input[name="payment_method"]', function() {
        togglePlaceOrderButton();
    });

    // Handle login button click
    $(document).on('click', '#gateway-login-btn', function(e) {
        e.preventDefault();
        
        const username = $('#gateway_username').val();
        const password = $('#gateway_password').val();

        // Add your API call here to validate credentials
        $.ajax({
            url: genericGateway.ajaxUrl,
            type: 'POST',
            data: {
                action: 'gateway_login',
                nonce: genericGateway.nonce,
                username: username,
                password: password
            },
            success: function(response) {
                if (response.success) {
                    sessionStorage.setItem('gateway_user_logged_in', 'true');
                    $('#gateway-login-message').html('<p class="success">Login successful!</p>');
                    togglePlaceOrderButton();
                    location.reload();
                } else {
                    $('#gateway-login-message').html('<p class="error">Incorrect email or password.</p>');
                }
            },
            error: function(xhr, status, error) {
                $('#gateway-login-message').html('<p class="error">An error occurred. Please try again.</p>');
            }
        });
    });

    // Handle logout button click
    $(document).on('click', '#gateway-logout-btn', function(e) {
        e.preventDefault();
        console.log('Logout clicked');
        console.log('Nonce:', genericGateway.nonce);
        
        $.ajax({
            url: genericGateway.ajaxUrl,
            type: 'POST',
            data: {
                action: 'gateway_logout',
                nonce: genericGateway.nonce
            },
            success: function(response) {
                console.log('Full logout response:', response); // Log the complete response
                if (response.success) {
                    sessionStorage.removeItem('gateway_user_logged_in');
                    $('#gateway-login-message').html('<p class="success">' + response.data.message + '</p>');
                    togglePlaceOrderButton();
                    location.reload();
                } else {
                    console.error('Logout failed:', response.data?.debug || 'Unknown error');
                    $('#gateway-login-message').html('<p class="error">Logout failed: ' + (response.data?.message || 'Please try again.') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Ajax error:', {xhr, status, error});
                $('#gateway-login-message').html('<p class="error">Logout failed. Please try again.</p>');
            }
        });
    });

    // Initial check
    togglePlaceOrderButton();
});
