<?php
/**
 * Lead Capture — Winter Protocol Free Guide Handler
 *
 * Processes lead magnet signups for the Winter Protocol training guide.
 * No payment — this drives the marketing funnel by capturing prospect
 * data and routing it to the email marketing platform (Mailchimp).
 *
 * Lead acquisition cost: $0
 * Conversion goal: Free guide download → future season registration revenue
 * Tracks acquisition channel (traffic source) for marketing ROI analysis
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Process Winter Protocol lead magnet signup
 *
 * Flow:
 * 1. Verify nonce (CSRF protection)
 * 2. Sanitize inputs
 * 3. Run anti-spam gibberish detection on name fields
 * 4. Validate email
 * 5. Add to Mailchimp with tags and traffic source merge field
 * 6. Return success (PDF download happens client-side)
 *
 * Anti-fraud note: Gibberish detection silently rejects spam submissions
 * by returning a fake success response. This prevents bots from learning
 * what triggers rejection — they think their submission worked.
 */
function newteam_process_winter_protocol_signup() {
    // Nonce verification — internal control against CSRF
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'winter_protocol_signup')) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Security check failed. Please refresh and try again.'
        ]));
    }

    // Sanitize form data
    $email = sanitize_email($_POST['email']);
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $traffic_source = isset($_POST['traffic_source']) ? sanitize_text_field($_POST['traffic_source']) : 'Unknown';

    // Anti-spam: Gibberish detection on name fields
    // Returns fake success to avoid revealing detection logic to bots
    if (function_exists('newteam_is_gibberish') && (newteam_is_gibberish($first_name) || newteam_is_gibberish($last_name))) {
        error_log('GIBBERISH DETECTED (Winter Protocol): Spam blocked - Name: ' . $first_name . ' ' . $last_name . ' from IP ' . $_SERVER['REMOTE_ADDR']);
        wp_die(json_encode([
            'success' => true,
            'message' => 'Your download is ready!'
        ]));
    }

    // Validate email
    if (empty($email) || !is_email($email)) {
        wp_die(json_encode([
            'success' => false,
            'message' => 'Please enter a valid email address.'
        ]));
    }

    // Add to Mailchimp with tags and traffic source tracking
    $mailchimp_result = newteam_add_to_mailchimp_with_tags(
        $email,
        $first_name,
        $last_name,
        ['Winter Protocol Insider Club', 'Newteam FC'],
        '', // use default list ID
        ['SOURCE' => $traffic_source] // merge field for acquisition channel tracking
    );

    if ($mailchimp_result['success']) {
        error_log('Winter Protocol signup success: ' . $email . ' (Source: ' . $traffic_source . ')');
    } else {
        error_log('Winter Protocol signup issue: ' . $email . ' - ' . $mailchimp_result['message']);
    }

    // Always return success if we got this far (PDF download happens client-side)
    wp_die(json_encode([
        'success' => true,
        'message' => 'Your download is ready!'
    ]));
}

// Register AJAX endpoints (accessible to both logged-in and anonymous users)
add_action('wp_ajax_process_winter_protocol_signup', 'newteam_process_winter_protocol_signup');
add_action('wp_ajax_nopriv_process_winter_protocol_signup', 'newteam_process_winter_protocol_signup');
