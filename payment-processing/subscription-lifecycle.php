<?php
/**
 * Subscription Billing Lifecycle
 *
 * Manages recurring billing for season registrations:
 * - Subscription creation with 6-month auto-cancellation
 * - Payment method attachment to Stripe Customer objects
 * - 4-tier pricing based on player type and name personalization
 * - 3D Secure authentication handling
 *
 * Revenue type: Deferred revenue — recognized monthly over 6-month billing period
 * Failed payments trigger escalating retry notification sequence
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Get or create Stripe Price ID for season subscription
 *
 * Creates the Stripe Product and recurring Price if they don't exist,
 * then caches the price_id in WordPress options for reuse.
 *
 * Pricing tiers:
 * - Full Season: $85/mo base
 * - Full Season + Personalization: $98/mo ($85 + $13)
 * - Guest: $42/mo base
 * - Guest + Personalization: $55/mo ($42 + $13)
 */
function newteam_get_season_subscription_price_id() {
    $stored_price_id = get_option('newteam_season_subscription_price_id');
    if ($stored_price_id) {
        return $stored_price_id;
    }

    $stripe_secret_key = newteam_get_stripe_secret_key();

    if (empty($stripe_secret_key)) {
        error_log('STRIPE: No secret key for subscription setup');
        return false;
    }

    // Create product via API
    $product_response = wp_remote_post('https://api.stripe.com/v1/products', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'name' => 'Newteam FC Season Registration - Full Season',
            'description' => '2025 Season - 6 month payment plan ($85/month)',
            'metadata[type]' => 'season_registration_full'
        ]),
        'timeout' => 30
    ]);

    if (is_wp_error($product_response)) {
        return false;
    }

    $product = json_decode(wp_remote_retrieve_body($product_response), true);
    if (!isset($product['id'])) {
        return false;
    }

    // Create recurring monthly price
    $price_response = wp_remote_post('https://api.stripe.com/v1/prices', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'product' => $product['id'],
            'unit_amount' => 8500, // $85.00 in cents
            'currency' => 'usd',
            'recurring[interval]' => 'month',
            'recurring[interval_count]' => 1
        ]),
        'timeout' => 30
    ]);

    $price = json_decode(wp_remote_retrieve_body($price_response), true);

    if (isset($price['id'])) {
        update_option('newteam_season_subscription_price_id', $price['id']);
        return $price['id'];
    }

    return false;
}

/**
 * Get Price ID for personalized subscription ($98/mo — $85 base + $13 personalization)
 */
function newteam_get_personalized_subscription_price_id() {
    $stored_price_id = get_option('newteam_personalized_subscription_price_id');
    if ($stored_price_id) {
        return $stored_price_id;
    }

    $stripe_secret_key = newteam_get_stripe_secret_key();
    if (empty($stripe_secret_key)) return false;

    $product_response = wp_remote_post('https://api.stripe.com/v1/products', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'name' => 'Newteam FC Season Registration - Full Season + Name Personalization',
            'description' => '2025 Season - 6 month payment plan with name on all equipment ($98/month)',
            'metadata[type]' => 'season_registration_personalized'
        ]),
        'timeout' => 30
    ]);

    $product = json_decode(wp_remote_retrieve_body($product_response), true);
    if (!isset($product['id'])) return false;

    $price_response = wp_remote_post('https://api.stripe.com/v1/prices', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'product' => $product['id'],
            'unit_amount' => 9800, // $98.00 in cents ($85 + $13 personalization)
            'currency' => 'usd',
            'recurring[interval]' => 'month',
            'recurring[interval_count]' => 1
        ]),
        'timeout' => 30
    ]);

    $price = json_decode(wp_remote_retrieve_body($price_response), true);

    if (isset($price['id'])) {
        update_option('newteam_personalized_subscription_price_id', $price['id']);
        return $price['id'];
    }

    return false;
}

/**
 * Get Price ID for guest subscription ($42/mo)
 */
function newteam_get_guest_subscription_price_id() {
    $stored_price_id = get_option('newteam_guest_subscription_price_id');
    if ($stored_price_id) return $stored_price_id;

    $stripe_secret_key = newteam_get_stripe_secret_key();
    if (empty($stripe_secret_key)) return false;

    $product_response = wp_remote_post('https://api.stripe.com/v1/products', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'name' => 'Newteam FC Season Registration - Guest Player',
            'description' => '2025 Season - Guest player 6 month plan ($42/month, 4 games)',
            'metadata[type]' => 'season_registration_guest'
        ]),
        'timeout' => 30
    ]);

    $product = json_decode(wp_remote_retrieve_body($product_response), true);
    if (!isset($product['id'])) return false;

    $price_response = wp_remote_post('https://api.stripe.com/v1/prices', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'product' => $product['id'],
            'unit_amount' => 4200, // $42.00 in cents
            'currency' => 'usd',
            'recurring[interval]' => 'month',
            'recurring[interval_count]' => 1
        ]),
        'timeout' => 30
    ]);

    $price = json_decode(wp_remote_retrieve_body($price_response), true);

    if (isset($price['id'])) {
        update_option('newteam_guest_subscription_price_id', $price['id']);
        return $price['id'];
    }

    return false;
}

/**
 * Get Price ID for guest + personalization ($55/mo — $42 base + $13 personalization)
 */
function newteam_get_guest_personalized_subscription_price_id() {
    $stored_price_id = get_option('newteam_guest_personalized_subscription_price_id');
    if ($stored_price_id) return $stored_price_id;

    $stripe_secret_key = newteam_get_stripe_secret_key();
    if (empty($stripe_secret_key)) return false;

    $product_response = wp_remote_post('https://api.stripe.com/v1/products', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'name' => 'Newteam FC Season Registration - Guest Player + Name Personalization',
            'description' => '2025 Season - Guest player with name on all equipment ($55/month, 4 games)',
            'metadata[type]' => 'season_registration_guest_personalized'
        ]),
        'timeout' => 30
    ]);

    $product = json_decode(wp_remote_retrieve_body($product_response), true);
    if (!isset($product['id'])) return false;

    $price_response = wp_remote_post('https://api.stripe.com/v1/prices', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'product' => $product['id'],
            'unit_amount' => 5500, // $55.00 in cents ($42 + $13 personalization)
            'currency' => 'usd',
            'recurring[interval]' => 'month',
            'recurring[interval_count]' => 1
        ]),
        'timeout' => 30
    ]);

    $price = json_decode(wp_remote_retrieve_body($price_response), true);

    if (isset($price['id'])) {
        update_option('newteam_guest_personalized_subscription_price_id', $price['id']);
        return $price['id'];
    }

    return false;
}

/**
 * Create Stripe Subscription with 6-month auto-cancellation
 *
 * Attaches payment method to customer, sets as default, then creates
 * subscription with cancel_at timestamp 6 months out.
 *
 * Handles 3D Secure by returning requires_action with client_secret
 * so the frontend can complete authentication.
 *
 * @param string $customer_id Stripe customer ID
 * @param string $payment_method_id Stripe payment method ID
 * @param array $metadata Subscription metadata for audit trail
 * @param bool $with_personalization Whether to add name personalization (+$13/mo)
 * @param string $player_type 'full_season' or 'guest'
 * @return array Result with subscription_id, status, and billing period info
 */
function newteam_create_season_subscription($customer_id, $payment_method_id, $metadata = [], $with_personalization = false, $player_type = 'full_season') {
    $stripe_secret_key = newteam_get_stripe_secret_key();

    // Select the appropriate price based on player type and personalization
    if ($player_type === 'guest') {
        $price_id = $with_personalization
            ? newteam_get_guest_personalized_subscription_price_id()
            : newteam_get_guest_subscription_price_id();
    } else {
        $price_id = $with_personalization
            ? newteam_get_personalized_subscription_price_id()
            : newteam_get_season_subscription_price_id();
    }

    if (empty($stripe_secret_key) || empty($price_id)) {
        return ['success' => false, 'message' => 'Subscription configuration error'];
    }

    // Attach payment method to customer
    wp_remote_post("https://api.stripe.com/v1/payment_methods/{$payment_method_id}/attach", [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query(['customer' => $customer_id]),
        'timeout' => 30
    ]);

    // Set as default payment method on customer
    wp_remote_post("https://api.stripe.com/v1/customers/{$customer_id}", [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'invoice_settings[default_payment_method]' => $payment_method_id
        ]),
        'timeout' => 30
    ]);

    // Auto-cancel after 6 months — subscription lifecycle management
    $cancel_at = strtotime('+6 months');

    $body = [
        'customer' => $customer_id,
        'items[0][price]' => $price_id,
        'default_payment_method' => $payment_method_id,
        'cancel_at' => $cancel_at,
        'payment_behavior' => 'error_if_incomplete',
        'payment_settings[payment_method_options][card][request_three_d_secure]' => 'automatic',
        'expand[]' => 'latest_invoice.payment_intent'
    ];

    foreach ($metadata as $key => $value) {
        $body['metadata[' . $key . ']'] = $value;
    }

    $response = wp_remote_post('https://api.stripe.com/v1/subscriptions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query($body),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Subscription creation failed'];
    }

    $subscription = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($subscription['id']) && $subscription['status'] === 'active') {
        return [
            'success' => true,
            'subscription_id' => $subscription['id'],
            'customer_id' => $customer_id,
            'status' => $subscription['status'],
            'current_period_end' => $subscription['current_period_end'],
            'cancel_at' => $subscription['cancel_at']
        ];
    }

    // 3D Secure authentication required — return client_secret for frontend handling
    if (isset($subscription['latest_invoice']['payment_intent']['status']) &&
        $subscription['latest_invoice']['payment_intent']['status'] === 'requires_action') {
        return [
            'success' => false,
            'requires_action' => true,
            'payment_intent_client_secret' => $subscription['latest_invoice']['payment_intent']['client_secret'],
            'subscription_id' => $subscription['id']
        ];
    }

    $error_message = isset($subscription['error']['message']) ? $subscription['error']['message'] : 'Subscription creation failed';
    return ['success' => false, 'message' => $error_message];
}

/**
 * AJAX handler: Create season subscription
 *
 * Called from SeasonRegistrationPage.jsx when user selects monthly payment.
 * Creates Stripe Customer, then creates subscription with appropriate pricing.
 */
function newteam_ajax_create_season_subscription() {
    header('Content-Type: application/json');

    if (!wp_verify_nonce($_POST['season_nonce'] ?? '', 'season_form_nonce')) {
        wp_die(json_encode(['success' => false, 'message' => 'Security check failed']));
    }

    $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name = sanitize_text_field($_POST['last_name'] ?? '');
    $player_type = sanitize_text_field($_POST['player_type'] ?? 'full_season');
    $name_personalization = ($_POST['name_personalization'] ?? '0') === '1';

    if (!in_array($player_type, ['full_season', 'guest'])) {
        $player_type = 'full_season';
    }

    if (empty($payment_method_id) || empty($email)) {
        wp_die(json_encode(['success' => false, 'message' => 'Missing required fields']));
    }

    // Create customer in Stripe
    $customer = newteam_create_stripe_customer($email, $first_name . ' ' . $last_name, [
        'type' => 'season_registration',
        'player_type' => $player_type,
        'personalization' => $name_personalization ? 'yes' : 'no'
    ]);

    if (!$customer) {
        wp_die(json_encode(['success' => false, 'message' => 'Failed to create customer profile']));
    }

    // Create subscription with appropriate pricing
    $subscription_result = newteam_create_season_subscription(
        $customer['id'],
        $payment_method_id,
        [
            'player_name' => $first_name . ' ' . $last_name,
            'player_email' => $email,
            'player_type' => $player_type,
            'personalization' => $name_personalization ? 'yes' : 'no'
        ],
        $name_personalization,
        $player_type
    );

    wp_die(json_encode($subscription_result));
}

// Register AJAX endpoint
add_action('wp_ajax_create_season_subscription', 'newteam_ajax_create_season_subscription');
add_action('wp_ajax_nopriv_create_season_subscription', 'newteam_ajax_create_season_subscription');
