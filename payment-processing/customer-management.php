<?php
/**
 * Stripe Customer Management
 *
 * Manages Stripe Customer objects — the link between a payment
 * and the person making it. Every subscription and one-time payment
 * is tied to a Customer record for full audit traceability.
 *
 * Also handles the Customer Portal, which allows players to
 * self-service their payment methods and view invoices.
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Helper to get Stripe secret key from multiple sources
 *
 * Priority order:
 * 1. Docker secrets (production — most secure)
 * 2. PHP constants (wp-config.php)
 * 3. Environment variables
 *
 * This hierarchy ensures credentials are never hardcoded in source files.
 */
function newteam_get_stripe_secret_key() {
    // Try Docker secrets first (production environment)
    if (function_exists('newteam_read_docker_secret')) {
        $docker_key = newteam_read_docker_secret('stripe_secret_key');
        if ($docker_key) {
            return $docker_key;
        }
    }

    // Then constants (wp-config.php)
    if (defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY) {
        return STRIPE_SECRET_KEY;
    }

    // Then environment variables
    return getenv('STRIPE_SECRET_KEY') ?: ($_ENV['STRIPE_SECRET_KEY'] ?? '');
}

/**
 * Create Stripe Customer
 *
 * Creates a Customer object in Stripe with metadata for internal tracking.
 * The Customer ID becomes the link between the payment platform and
 * the system of record (Airtable).
 *
 * @param string $email Customer email
 * @param string $name Customer full name
 * @param array $metadata Additional metadata (player_type, registration type, etc.)
 * @return array|false Customer object or false on failure
 */
function newteam_create_stripe_customer($email, $name, $metadata = []) {
    $stripe_secret_key = newteam_get_stripe_secret_key();

    if (empty($stripe_secret_key)) {
        return false;
    }

    $body = [
        'email' => $email,
        'name' => $name
    ];

    foreach ($metadata as $key => $value) {
        $body['metadata[' . $key . ']'] = $value;
    }

    $response = wp_remote_post('https://api.stripe.com/v1/customers', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query($body),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('STRIPE CUSTOMER ERROR: ' . $response->get_error_message());
        return false;
    }

    $customer = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($customer['id'])) {
        return $customer;
    }

    error_log('STRIPE CUSTOMER ERROR: ' . json_encode($customer));
    return false;
}

/**
 * Create a Stripe Customer Portal session
 *
 * Generates a self-service portal URL where players can:
 * - Update their credit/debit card
 * - View payment history and invoices
 * - See upcoming payments
 *
 * @param string $customer_id Stripe Customer ID
 * @param string $return_url URL to redirect back to after portal session
 * @return array Success status with portal URL
 */
function newteam_create_customer_portal_session($customer_id, $return_url = null) {
    $stripe_secret_key = newteam_get_stripe_secret_key();

    if (empty($stripe_secret_key) || empty($customer_id)) {
        return ['success' => false, 'message' => 'Missing configuration'];
    }

    if (empty($return_url)) {
        $return_url = home_url('/season-registration/?portal_return=1');
    }

    $response = wp_remote_post('https://api.stripe.com/v1/billing_portal/sessions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'customer' => $customer_id,
            'return_url' => $return_url
        ]),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Portal creation failed'];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['url'])) {
        return ['success' => true, 'url' => $body['url']];
    }

    return ['success' => false, 'message' => $body['error']['message'] ?? 'Portal creation failed'];
}

/**
 * Look up customer by email and generate portal URL
 *
 * Searches Stripe for a customer matching the email, then creates
 * a portal session. Used by the admin tools dashboard.
 */
function newteam_get_portal_url_by_email($email) {
    $stripe_secret_key = newteam_get_stripe_secret_key();

    if (empty($stripe_secret_key)) {
        return ['success' => false, 'message' => 'Stripe not configured'];
    }

    $response = wp_remote_get('https://api.stripe.com/v1/customers?email=' . urlencode($email) . '&limit=1', [
        'headers' => ['Authorization' => 'Bearer ' . $stripe_secret_key],
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Customer lookup failed'];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body['data'])) {
        return ['success' => false, 'message' => 'No customer found with this email'];
    }

    $customer_id = $body['data'][0]['id'];
    return newteam_create_customer_portal_session($customer_id);
}

// AJAX handler for customer portal
add_action('wp_ajax_get_customer_portal', 'newteam_ajax_get_customer_portal');
add_action('wp_ajax_nopriv_get_customer_portal', 'newteam_ajax_get_customer_portal');

function newteam_ajax_get_customer_portal() {
    header('Content-Type: application/json');
    $email = sanitize_email($_POST['email'] ?? '');

    if (empty($email)) {
        wp_die(json_encode(['success' => false, 'message' => 'Email required']));
    }

    $result = newteam_get_portal_url_by_email($email);
    wp_die(json_encode($result));
}
