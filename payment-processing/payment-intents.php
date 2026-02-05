<?php
/**
 * Payment Intent Processing
 *
 * Handles one-time payment creation and confirmation for tryout registrations
 * and season registration pay-in-full transactions.
 *
 * Revenue type: One-time receivable, immediate revenue recognition
 * Transaction types: Tryout fee ($15), Season pay-in-full ($510)
 */

// Credentials loaded from Docker secrets — never hardcoded
// Stripe API keys are resolved at runtime via newteam_get_stripe_secret_key()

/**
 * Create Stripe Payment Intent for on-page payment processing
 *
 * Creates a PaymentIntent object with metadata for transaction traceability.
 * Amount is converted from dollars to cents for Stripe API compliance.
 *
 * @param array $registration_data Player information (name, email, phone, position, etc.)
 * @param float $price Amount in dollars
 * @param array $tryout_date_info Formatted date information for the transaction description
 * @return array Success status with client_secret for frontend confirmation
 */
function newteam_create_payment_intent($registration_data, $price, $tryout_date_info) {
    $stripe_secret_key = newteam_get_stripe_secret_key();

    if (empty($stripe_secret_key)) {
        error_log('STRIPE ERROR: No secret key found in environment');
        return ['success' => false, 'message' => 'Payment system configuration error'];
    }

    \Stripe\Stripe::setApiKey($stripe_secret_key);

    try {
        // Create payment intent with full audit metadata
        $payment_intent = \Stripe\PaymentIntent::create([
            'amount' => $price * 100, // Convert to cents for Stripe API
            'currency' => 'usd',
            'payment_method_types' => ['card'],
            'description' => 'Newteam F.C. Soccer Tryout Registration - ' . $tryout_date_info['formatted'],
            'metadata' => [
                'type' => 'tryout_registration',
                'player_name' => $registration_data['first_name'] . ' ' . $registration_data['last_name'],
                'player_email' => $registration_data['email'],
                'player_phone' => $registration_data['phone'],
                'tryout_date' => $tryout_date_info['date'],
                'position' => $registration_data['position'],
                'experience' => $registration_data['experience'],
            ]
        ]);

        return [
            'success' => true,
            'client_secret' => $payment_intent->client_secret,
            'payment_intent_id' => $payment_intent->id
        ];

    } catch (\Stripe\Exception\CardException $e) {
        error_log('STRIPE CARD ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()];
    } catch (\Stripe\Exception\RateLimitException $e) {
        error_log('STRIPE RATE LIMIT ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Too many requests. Please try again later.'];
    } catch (\Stripe\Exception\InvalidRequestException $e) {
        error_log('STRIPE INVALID REQUEST ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Invalid payment request.'];
    } catch (\Stripe\Exception\AuthenticationException $e) {
        error_log('STRIPE AUTH ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Payment authentication failed.'];
    } catch (\Stripe\Exception\ApiConnectionException $e) {
        error_log('STRIPE CONNECTION ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Network communication with payment processor failed.'];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('STRIPE API ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Payment processing error.'];
    } catch (Exception $e) {
        error_log('STRIPE GENERAL ERROR: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An unexpected error occurred.'];
    }
}

/**
 * AJAX handler: Create simple payment intent (tryout registration)
 *
 * Called from the React TryoutPage component via fetch().
 * Creates a PaymentIntent with the fixed $15 tryout fee.
 */
function newteam_ajax_create_payment_intent_simple() {
    header('Content-Type: application/json');

    $amount = intval($_POST['amount'] ?? 0);
    $email = sanitize_email($_POST['email'] ?? '');
    $name = sanitize_text_field($_POST['name'] ?? '');

    if ($amount <= 0) {
        wp_die(json_encode(['success' => false, 'message' => 'Invalid amount: ' . $amount]));
    }

    $stripe_secret_key = newteam_get_stripe_secret_key();

    if (empty($stripe_secret_key)) {
        wp_die(json_encode(['success' => false, 'message' => 'Payment configuration error']));
    }

    // Create payment intent via Stripe REST API
    $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'amount' => $amount,
            'currency' => 'usd',
            'payment_method_types[]' => 'card',
            'metadata[customer_email]' => $email,
            'metadata[customer_name]' => $name
        ]),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('STRIPE API ERROR: ' . $response->get_error_message());
        wp_die(json_encode(['success' => false, 'message' => 'Payment API error']));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200 && isset($body['client_secret'])) {
        wp_die(json_encode([
            'success' => true,
            'client_secret' => $body['client_secret']
        ]));
    } else {
        error_log('STRIPE API RESPONSE: ' . print_r($body, true));
        wp_die(json_encode([
            'success' => false,
            'message' => isset($body['error']['message']) ? $body['error']['message'] : 'Payment setup failed'
        ]));
    }
}

/**
 * AJAX handler: Create season full payment (one-time charge for entire season)
 *
 * Handles the pay-in-full option for season registration.
 * Creates a Stripe Customer, attaches payment method, then creates
 * a confirmed PaymentIntent with return_url for 3D Secure handling.
 *
 * Revenue recognition: Full amount recognized immediately upon payment confirmation.
 */
function newteam_ajax_create_season_full_payment() {
    header('Content-Type: application/json');

    // Nonce verification — internal control against CSRF
    if (!wp_verify_nonce($_POST['season_nonce'] ?? '', 'season_form_nonce')) {
        wp_die(json_encode(['success' => false, 'message' => 'Security check failed']));
    }

    $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name = sanitize_text_field($_POST['last_name'] ?? '');
    $player_type = sanitize_text_field($_POST['player_type'] ?? 'full_season');
    $total_amount = floatval($_POST['total_amount'] ?? 0);

    if (!in_array($player_type, ['full_season', 'guest'])) {
        $player_type = 'full_season';
    }

    if (empty($payment_method_id) || empty($email) || $total_amount <= 0) {
        wp_die(json_encode(['success' => false, 'message' => 'Missing required fields']));
    }

    $stripe_secret_key = newteam_get_stripe_secret_key();
    if (empty($stripe_secret_key)) {
        wp_die(json_encode(['success' => false, 'message' => 'Payment configuration error']));
    }

    error_log("STRIPE: Creating FULL PAYMENT for {$player_type} player, amount: \${$total_amount}");

    // Create customer record in Stripe for audit trail
    $customer = newteam_create_stripe_customer($email, $first_name . ' ' . $last_name, [
        'type' => 'season_registration',
        'player_type' => $player_type,
        'payment_frequency' => 'full',
        'personalization' => 'yes'
    ]);

    if (!$customer) {
        wp_die(json_encode(['success' => false, 'message' => 'Failed to create customer profile']));
    }

    // Attach payment method to customer
    wp_remote_post("https://api.stripe.com/v1/payment_methods/{$payment_method_id}/attach", [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query(['customer' => $customer['id']]),
        'timeout' => 30
    ]);

    // Create and confirm payment intent
    $amount_cents = intval($total_amount * 100);
    $games_label = $player_type === 'guest' ? '3-5 games' : 'All games';

    $payment_intent_response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
        'headers' => [
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'amount' => $amount_cents,
            'currency' => 'usd',
            'customer' => $customer['id'],
            'payment_method' => $payment_method_id,
            'confirm' => 'true',
            'return_url' => home_url('/season-registration/?payment_complete=1'),
            'description' => "Spring 2026 Season Registration - " . ucfirst(str_replace('_', ' ', $player_type)) . " ({$games_label}) - PAID IN FULL",
            'metadata[player_name]' => $first_name . ' ' . $last_name,
            'metadata[player_email]' => $email,
            'metadata[player_type]' => $player_type,
            'metadata[payment_frequency]' => 'full',
            'metadata[season]' => 'Spring 2026'
        ]),
        'timeout' => 30
    ]);

    if (is_wp_error($payment_intent_response)) {
        error_log('STRIPE PAYMENT ERROR: ' . $payment_intent_response->get_error_message());
        wp_die(json_encode(['success' => false, 'message' => 'Payment processing failed']));
    }

    $payment_intent_body = json_decode(wp_remote_retrieve_body($payment_intent_response), true);

    if (isset($payment_intent_body['error'])) {
        error_log('STRIPE PAYMENT ERROR: ' . json_encode($payment_intent_body['error']));
        wp_die(json_encode(['success' => false, 'message' => $payment_intent_body['error']['message'] ?? 'Payment failed']));
    }

    // Handle 3D Secure authentication requirement
    if ($payment_intent_body['status'] === 'requires_action') {
        wp_die(json_encode([
            'success' => false,
            'requires_action' => true,
            'payment_intent_client_secret' => $payment_intent_body['client_secret'],
            'customer_id' => $customer['id']
        ]));
    }

    if ($payment_intent_body['status'] !== 'succeeded') {
        wp_die(json_encode(['success' => false, 'message' => 'Payment was not completed']));
    }

    error_log("STRIPE: Full payment successful - Payment Intent: {$payment_intent_body['id']}");

    wp_die(json_encode([
        'success' => true,
        'payment_intent_id' => $payment_intent_body['id'],
        'customer_id' => $customer['id'],
        'amount' => $total_amount
    ]));
}

// Register AJAX endpoints
add_action('wp_ajax_create_payment_intent_simple', 'newteam_ajax_create_payment_intent_simple');
add_action('wp_ajax_nopriv_create_payment_intent_simple', 'newteam_ajax_create_payment_intent_simple');
add_action('wp_ajax_create_season_full_payment', 'newteam_ajax_create_season_full_payment');
add_action('wp_ajax_nopriv_create_season_full_payment', 'newteam_ajax_create_season_full_payment');
