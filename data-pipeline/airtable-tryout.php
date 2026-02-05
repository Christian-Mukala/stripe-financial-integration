<?php
/**
 * Tryout Registration Data Pipeline — Airtable Integration
 *
 * Routes tryout registration data to the system of record after
 * payment confirmation. Includes field-level data transformation
 * for position and experience level values.
 *
 * Revenue type: One-time receivable ($15), immediate recognition
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Send tryout registration data to Airtable
 *
 * Transforms raw form values (e.g., 'high_school' → 'High School')
 * before posting to the Airtable API. The system of record expects
 * human-readable select field values, not form slugs.
 *
 * @param array $form_data Registration form data
 * @param string $payment_intent_id Stripe Payment Intent ID for audit trail
 * @return bool Success status
 */
function newteam_send_to_tryout_airtable($form_data, $payment_intent_id) {
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

    // Tryout Registration table ID — loaded from environment
    $table_id = '';
    if (function_exists('newteam_read_docker_secret')) {
        $table_id = newteam_read_docker_secret('airtable_tryout_table_id');
    }
    if (empty($table_id)) {
        $table_id = defined('AIRTABLE_TRYOUT_TABLE_ID') ? AIRTABLE_TRYOUT_TABLE_ID : (getenv('AIRTABLE_TRYOUT_TABLE_ID') ?: '');
    }

    if (empty($base_id) || empty($api_key) || empty($table_id)) {
        error_log('AIRTABLE TRYOUT: Missing credentials - skipping Airtable save');
        return false;
    }

    $url = "https://api.airtable.com/v0/{$base_id}/{$table_id}";

    // Position value normalization — form slugs → Airtable select field values
    $position_map = [
        'goalkeeper' => 'Goalkeeper',
        'defender' => 'Defender',
        'midfielder' => 'Midfielder',
        'forward' => 'Forward'
    ];
    $position = $position_map[$form_data['position']] ?? $form_data['position'];

    // Experience level normalization — same pattern
    $experience_map = [
        'high_school' => 'High School',
        'club' => 'Club',
        'college' => 'College',
        'semi_pro' => 'Semi-Professional',
        'professional' => 'Professional'
    ];
    $experience = $experience_map[$form_data['experience']] ?? $form_data['experience'];

    $data = [
        'fields' => [
            'First Name' => $form_data['first_name'],
            'Last Name' => $form_data['last_name'],
            'Email' => $form_data['email'],
            'Phone' => $form_data['phone'],
            'Date of Birth' => $form_data['date_of_birth'],
            'Position' => $position,
            'Experience' => $experience,
            'Tryout Date' => $form_data['tryout_date'],
            'Stripe Payment ID' => $payment_intent_id,
            'Registration Date' => date('Y-m-d'),
            'Status' => 'Registered'
        ]
    ];

    error_log('AIRTABLE TRYOUT: Sending data: ' . json_encode($data, JSON_PRETTY_PRINT));

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        error_log('AIRTABLE TRYOUT ERROR: ' . $response->get_error_message());
        return false;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($http_code === 200) {
        error_log('AIRTABLE TRYOUT SUCCESS: Registration saved to Airtable');
        return true;
    } else {
        error_log("AIRTABLE TRYOUT FAILED: HTTP $http_code - $body");
        return false;
    }
}

/**
 * AJAX handler: Process tryout registration after payment
 *
 * Called after Stripe PaymentIntent succeeds. Validates form data,
 * sends to Airtable, sends admin notification, and adds to Mailchimp.
 */
function newteam_ajax_process_tryout_registration() {
    // Nonce verification — internal control
    if (!wp_verify_nonce($_POST['tryout_nonce'] ?? '', 'tryout_form_nonce')) {
        wp_die(json_encode(['success' => false, 'message' => 'Security check failed']));
    }

    // Sanitize all form inputs
    $form_data = [
        'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
        'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
        'email' => sanitize_email($_POST['email'] ?? ''),
        'phone' => sanitize_text_field($_POST['phone'] ?? ''),
        'date_of_birth' => sanitize_text_field($_POST['date_of_birth'] ?? ''),
        'position' => sanitize_text_field($_POST['position'] ?? ''),
        'experience' => sanitize_text_field($_POST['experience'] ?? ''),
        'tryout_date' => sanitize_text_field($_POST['tryout_date'] ?? ''),
    ];

    $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');

    // Validate required fields
    $errors = [];
    if (empty($form_data['first_name'])) $errors[] = 'First name is required';
    if (empty($form_data['last_name'])) $errors[] = 'Last name is required';
    if (empty($form_data['email']) || !is_email($form_data['email'])) $errors[] = 'Valid email is required';
    if (empty($payment_intent_id)) $errors[] = 'Payment verification failed';

    if (!empty($errors)) {
        wp_die(json_encode(['success' => false, 'message' => implode(', ', $errors)]));
    }

    // Add to Mailchimp with tryout tags and extra fields
    if (function_exists('newteam_add_to_mailchimp_with_tags')) {
        $position_map = [
            'goalkeeper' => 'Goalkeeper',
            'defender' => 'Defender',
            'midfielder' => 'Midfielder',
            'forward' => 'Forward'
        ];
        $experience_map = [
            'high_school' => 'High School',
            'club' => 'Club',
            'college' => 'College',
            'semi_pro' => 'Semi-Professional',
            'professional' => 'Professional'
        ];

        newteam_add_to_mailchimp_with_tags(
            $form_data['email'],
            $form_data['first_name'],
            $form_data['last_name'],
            ['Tryout Registration Spring 2026', 'Newteam FC'],
            '',
            [
                'PHONE' => $form_data['phone'],
                'POSITION' => $position_map[$form_data['position']] ?? $form_data['position'],
                'EXPERIENCE' => $experience_map[$form_data['experience']] ?? $form_data['experience']
            ]
        );
    }

    // Admin notification
    $admin_subject = 'New Tryout Registration - ' . $form_data['first_name'] . ' ' . $form_data['last_name'];
    $admin_message = "New tryout registration received:\n\n";
    $admin_message .= "Name: {$form_data['first_name']} {$form_data['last_name']}\n";
    $admin_message .= "Email: {$form_data['email']}\n";
    $admin_message .= "Phone: {$form_data['phone']}\n";
    $admin_message .= "Position: {$form_data['position']}\n";
    $admin_message .= "Experience: {$form_data['experience']}\n";
    $admin_message .= "Tryout Date: {$form_data['tryout_date']}\n\n";
    $admin_message .= "Payment Intent ID: {$payment_intent_id}\n";

    wp_mail('info@newteamfc.com', $admin_subject, $admin_message, [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Newteam F.C. Registration <noreply@newteamfc.com>'
    ]);

    // Send to Airtable
    $airtable_result = newteam_send_to_tryout_airtable($form_data, $payment_intent_id);

    wp_die(json_encode([
        'success' => true,
        'message' => 'Registration completed successfully!'
    ]));
}

// Register AJAX endpoints
add_action('wp_ajax_process_tryout_registration', 'newteam_ajax_process_tryout_registration');
add_action('wp_ajax_nopriv_process_tryout_registration', 'newteam_ajax_process_tryout_registration');
