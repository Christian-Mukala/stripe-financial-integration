<?php
/**
 * Season Registration Data Pipeline — Airtable Integration
 *
 * Routes validated season registration data from the payment platform
 * to the system of record (Airtable). Handles field-level data
 * transformation to ensure payment data matches the database schema.
 *
 * This is the bridge between "payment confirmed" and "record created."
 * Every successful payment must result in a matching database record
 * for the books to balance.
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Send season registration to Airtable (system of record)
 *
 * Transforms raw form data into Airtable-compatible field values,
 * including select field normalization (e.g., socks sizes, payment status).
 *
 * This function contains the data transformation layer that fixed
 * the original 422 errors — select field values must exactly match
 * the Airtable table schema.
 *
 * @param array $form_data Complete registration data including payment info
 * @return bool Success status
 */
function newteam_send_season_registration_to_airtable($form_data) {
    // Get Airtable credentials from Docker secrets, then constants, then env
    $base_id = '';
    $api_key = '';

    if (function_exists('newteam_read_docker_secret')) {
        $base_id = newteam_read_docker_secret('airtable_base_id');
        $api_key = newteam_read_docker_secret('airtable_api_key');
    }

    if (empty($base_id)) {
        $base_id = defined('AIRTABLE_BASE_ID') ? AIRTABLE_BASE_ID : (getenv('AIRTABLE_BASE_ID') ?: '');
    }
    if (empty($api_key)) {
        $api_key = defined('AIRTABLE_API_KEY') ? AIRTABLE_API_KEY : (getenv('AIRTABLE_API_KEY') ?: '');
    }

    // Season Registration table ID — loaded from environment
    $table_id = defined('AIRTABLE_SEASON_TABLE_ID') ? AIRTABLE_SEASON_TABLE_ID : '';

    if (empty($base_id) || empty($api_key)) {
        error_log('AIRTABLE SEASON: Missing credentials');
        return false;
    }

    $url = "https://api.airtable.com/v0/{$base_id}/{$table_id}";

    $player_type_label = $form_data['player_type'] === 'guest' ? 'Guest Player' : 'Full Season Player';

    // Payment status normalization — maps payment frequency to GL-compatible values
    // "full" → "Paid" (immediate revenue recognition)
    // "monthly" → "Pending" (deferred revenue, recognized over billing period)
    $is_paid_in_full = ($form_data['payment_frequency'] ?? 'monthly') === 'full';
    $payment_status = $is_paid_in_full ? 'Paid' : 'Pending';
    $payment_frequency_label = $is_paid_in_full ? 'Paid in full' : 'Monthly';

    // Socks size mapping — transforms shorthand values to match Airtable select field schema
    // This mapping fixed the original 422 errors: 'S' alone doesn't match 'S (US 5-7)' in the schema
    $socks_size_map = [
        'S' => 'S (US 5-7)',
        'M' => 'M (US 7-9)',
        'L' => 'L (US 9-12)',
        'XL' => 'XL (US 12+)'
    ];
    $socks_value = $socks_size_map[$form_data['socks_size']] ?? $form_data['socks_size'];

    // Build the record with all required fields for the system of record
    $fields = [
        'First Name' => $form_data['first_name'],
        'Last Name' => $form_data['last_name'],
        'Email' => $form_data['email'],
        'Age' => $form_data['age'],
        'Positions' => $form_data['position'],
        'Tracksuit Size' => $form_data['tracksuit_size'],
        'Practice Jersey Size' => $form_data['practice_jersey_size'],
        'Shorts Size' => $form_data['shorts_size'],
        'Socks Size' => $socks_value,
        'Player Type' => $player_type_label,
        'Name Personalization' => $form_data['name_personalization'] ? 'Yes' : 'No',
        'Payment Amount' => $form_data['payment_amount'],
        'Payment Frequency' => $payment_frequency_label,
        'Subscription ID' => $form_data['subscription_id'] ?? '',
        'Payment Intent ID' => $form_data['payment_intent_id'] ?? '',
        'Stripe Customer ID' => $form_data['customer_id'],
        'Stripe Payment ID' => $form_data['payment_intent_id'] ?? $form_data['subscription_id'] ?? '',
        'Payment Status' => $payment_status,
        'Waiver Agreement' => 'Waiver Signed',
        'Registration date' => date('Y-m-d')
    ];

    $data = ['fields' => $fields];

    error_log('AIRTABLE SEASON: Sending data: ' . json_encode($data, JSON_PRETTY_PRINT));

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('AIRTABLE SEASON ERROR: ' . $response->get_error_message());
        return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($http_code === 200) {
        error_log('AIRTABLE SEASON SUCCESS: Registration saved');
        return true;
    }

    error_log("AIRTABLE SEASON FAILED: HTTP $http_code - $body");
    return false;
}

/**
 * AJAX handler: Process season registration after payment
 *
 * Called after payment succeeds. Sends data to Airtable, sends admin
 * notification, and adds player to Mailchimp with appropriate tags.
 *
 * This is the final step in the payment pipeline — payment is confirmed,
 * now create the official record in the system of record.
 */
function newteam_ajax_process_season_registration() {
    header('Content-Type: application/json');

    // Nonce verification — internal control
    if (!wp_verify_nonce($_POST['season_nonce'] ?? '', 'season_form_nonce')) {
        wp_die(json_encode(['success' => false, 'message' => 'Security check failed']));
    }

    // Collect and sanitize all form data
    $form_data = [
        'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
        'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
        'email' => sanitize_email($_POST['email'] ?? ''),
        'age' => intval($_POST['age'] ?? 0),
        'position' => sanitize_text_field($_POST['position'] ?? ''),
        'tracksuit_size' => sanitize_text_field($_POST['tracksuit_size'] ?? ''),
        'practice_jersey_size' => sanitize_text_field($_POST['practice_jersey_size'] ?? ''),
        'shorts_size' => sanitize_text_field($_POST['shorts_size'] ?? ''),
        'socks_size' => sanitize_text_field($_POST['socks_size'] ?? ''),
        'player_type' => sanitize_text_field($_POST['player_type'] ?? 'full_season'),
        'payment_amount' => floatval($_POST['payment_amount'] ?? 0),
        'payment_frequency' => sanitize_text_field($_POST['payment_frequency'] ?? 'monthly'),
        'name_personalization' => ($_POST['name_personalization'] ?? '0') === '1',
        'subscription_id' => sanitize_text_field($_POST['subscription_id'] ?? ''),
        'payment_intent_id' => sanitize_text_field($_POST['payment_intent_id'] ?? ''),
        'customer_id' => sanitize_text_field($_POST['customer_id'] ?? '')
    ];

    if (empty($form_data['first_name']) || empty($form_data['last_name']) || empty($form_data['email'])) {
        wp_die(json_encode(['success' => false, 'message' => 'Missing required fields']));
    }

    // Write to system of record
    $airtable_result = newteam_send_season_registration_to_airtable($form_data);

    // Send admin notification email
    $player_type_label = $form_data['player_type'] === 'guest' ? 'Guest Player' : 'Full Season Player';
    $admin_subject = "New Season Registration - {$form_data['first_name']} {$form_data['last_name']} ({$player_type_label})";
    $admin_message = "New season registration received:\n\n";
    $admin_message .= "PLAYER INFORMATION:\n";
    $admin_message .= "Name: {$form_data['first_name']} {$form_data['last_name']}\n";
    $admin_message .= "Email: {$form_data['email']}\n";
    $admin_message .= "Age: {$form_data['age']}\n";
    $admin_message .= "Position: {$form_data['position']}\n\n";
    $admin_message .= "EQUIPMENT SIZES:\n";
    $admin_message .= "Tracksuit: {$form_data['tracksuit_size']}\n";
    $admin_message .= "Practice Jersey: {$form_data['practice_jersey_size']}\n";
    $admin_message .= "Shorts: {$form_data['shorts_size']}\n";
    $admin_message .= "Socks: {$form_data['socks_size']}\n\n";
    $admin_message .= "REGISTRATION TYPE:\n";
    $admin_message .= "Player Type: {$player_type_label}\n";

    $is_paid_in_full = $form_data['payment_frequency'] === 'full';
    if ($is_paid_in_full) {
        $admin_message .= "Payment: \${$form_data['payment_amount']} PAID IN FULL\n";
        $admin_message .= "Payment Intent ID: {$form_data['payment_intent_id']}\n";
    } else {
        $admin_message .= "Payment: Subscription (monthly)\n";
        $admin_message .= "Subscription ID: {$form_data['subscription_id']}\n";
    }
    $admin_message .= "Customer ID: {$form_data['customer_id']}\n";

    wp_mail('goal@newteamfc.com', $admin_subject, $admin_message, [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Newteam F.C. Registration <noreply@newteamfc.com>'
    ]);

    // Add to Mailchimp with appropriate tags
    if (function_exists('newteam_add_to_mailchimp_with_tags')) {
        $tags = ['Newteam FC', 'Season Registration Spring 2026'];
        $tags[] = $form_data['player_type'] === 'guest' ? 'Guest Player' : 'Full Season Player';

        newteam_add_to_mailchimp_with_tags(
            $form_data['email'],
            $form_data['first_name'],
            $form_data['last_name'],
            $tags,
            '',
            ['POSITION' => $form_data['position']]
        );
    }

    wp_die(json_encode([
        'success' => true,
        'message' => 'Registration completed successfully!',
        'airtable' => $airtable_result
    ]));
}

/**
 * Update season registration status in Airtable
 *
 * Used by webhook handler to update payment status when subscriptions
 * fail or end. Finds the record by Subscription ID, then patches
 * the Payment Status field.
 *
 * @param string $subscription_id Stripe Subscription ID to look up
 * @param string $status New status value (e.g., "Subscription Ended", "Payment Failed")
 * @return bool Success
 */
function newteam_update_season_registration_status($subscription_id, $status) {
    $base_id = '';
    $api_key = '';

    if (function_exists('newteam_read_docker_secret')) {
        $base_id = newteam_read_docker_secret('airtable_base_id');
        $api_key = newteam_read_docker_secret('airtable_api_key');
    }

    if (empty($base_id)) {
        $base_id = defined('AIRTABLE_BASE_ID') ? AIRTABLE_BASE_ID : (getenv('AIRTABLE_BASE_ID') ?: '');
    }
    if (empty($api_key)) {
        $api_key = defined('AIRTABLE_API_KEY') ? AIRTABLE_API_KEY : (getenv('AIRTABLE_API_KEY') ?: '');
    }

    $table_id = defined('AIRTABLE_SEASON_TABLE_ID') ? AIRTABLE_SEASON_TABLE_ID : '';

    if (empty($base_id) || empty($api_key)) {
        return false;
    }

    // Find the record by Subscription ID
    $filter = urlencode("AND({Subscription ID}='{$subscription_id}')");
    $url = "https://api.airtable.com/v0/{$base_id}/{$table_id}?filterByFormula={$filter}";

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'timeout' => 30
    ]);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($body['records']) || empty($body['records'])) return false;

    $record_id = $body['records'][0]['id'];

    // Update the Payment Status field
    $update_url = "https://api.airtable.com/v0/{$base_id}/{$table_id}/{$record_id}";

    $update_response = wp_remote_request($update_url, [
        'method' => 'PATCH',
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'fields' => ['Payment Status' => $status]
        ]),
        'timeout' => 30
    ]);

    return !is_wp_error($update_response);
}

// Register AJAX endpoint
add_action('wp_ajax_process_season_registration', 'newteam_ajax_process_season_registration');
add_action('wp_ajax_nopriv_process_season_registration', 'newteam_ajax_process_season_registration');
