<?php
/**
 * Mailchimp Email Marketing Integration
 *
 * Manages subscriber creation and tag-based segmentation in Mailchimp.
 * Every payment and lead capture flow routes through this integration
 * to build the marketing database.
 *
 * Tag strategy:
 * - "Tryout Registration" — paid tryout registrants ($15 revenue)
 * - "Season Registration Spring 2026" — active season members ($510+ revenue)
 * - "Full Season Player" / "Guest Player" — player tier segmentation
 * - "Winter Protocol Insider Club" — free lead magnet subscribers ($0 acquisition)
 * - "Newteam FC" — universal tag for all contacts
 *
 * Merge fields: FNAME, LNAME, PHONE, POSITION, EXPERIENCE, SOURCE
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Get Mailchimp API Key from Docker secrets, environment, or constants
 */
function newteam_get_mailchimp_api_key() {
    if (function_exists('newteam_read_docker_secret')) {
        $docker_key = newteam_read_docker_secret('mailchimp_api_key');
        if ($docker_key) return $docker_key;
    }

    if (getenv('MAILCHIMP_API_KEY')) {
        return getenv('MAILCHIMP_API_KEY');
    }

    if (defined('MAILCHIMP_API_KEY')) {
        return MAILCHIMP_API_KEY;
    }

    return false;
}

/**
 * Extract Mailchimp server prefix from API key
 * API keys end with "-usXX" where XX is the data center
 */
function newteam_get_mailchimp_server() {
    $api_key = newteam_get_mailchimp_api_key();
    $parts = explode('-', $api_key);
    return isset($parts[1]) ? $parts[1] : 'us13';
}

/**
 * Add subscriber to Mailchimp list with tags and optional merge fields
 *
 * Used by all three flows:
 * - Tryout registration: tags=['Tryout Registration', 'Newteam FC'], fields=[PHONE, POSITION, EXPERIENCE]
 * - Season registration: tags=['Season Registration', player type], fields=[POSITION]
 * - Winter Protocol: tags=['Winter Protocol Insider Club'], fields=[SOURCE]
 *
 * If the subscriber already exists, their tags are updated instead of
 * creating a duplicate — idempotent operation.
 *
 * @param string $email Email address
 * @param string $first_name First name
 * @param string $last_name Last name
 * @param array $tags Tags to apply
 * @param string $list_id Mailchimp list/audience ID (empty = default)
 * @param array $extra_fields Additional merge fields beyond FNAME/LNAME
 * @return array ['success' => bool, 'message' => string]
 */
function newteam_add_to_mailchimp_with_tags($email, $first_name = '', $last_name = '', $tags = [], $list_id = '', $extra_fields = []) {
    $api_key = newteam_get_mailchimp_api_key();
    $server = newteam_get_mailchimp_server();

    // List ID loaded from WordPress options — configured in admin settings
    if (empty($list_id)) {
        $list_id = get_option('newteam_mailchimp_list_id', '');
    }

    if (empty($list_id)) {
        return ['success' => false, 'message' => 'Mailchimp list ID not configured'];
    }

    $url = 'https://' . $server . '.api.mailchimp.com/3.0/lists/' . $list_id . '/members';

    if (empty($tags)) {
        $tags = ['Website Contact Form', 'Newteam FC'];
    }

    // Build merge fields
    $merge_fields = ['FNAME' => $first_name, 'LNAME' => $last_name];
    if (!empty($extra_fields) && is_array($extra_fields)) {
        $merge_fields = array_merge($merge_fields, $extra_fields);
    }

    $data = [
        'email_address' => $email,
        'status' => 'subscribed',
        'merge_fields' => $merge_fields,
        'tags' => $tags
    ];

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key),
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode($data),
        'timeout' => 10
    ]);

    if (is_wp_error($response)) {
        error_log('Mailchimp API Error: ' . $response->get_error_message());
        return ['success' => false, 'message' => 'Failed to connect to Mailchimp'];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code === 200 || $code === 201) {
        return ['success' => true, 'message' => 'Successfully subscribed with tags: ' . implode(', ', $tags)];
    } elseif ($code === 400 && isset($body['title']) && $body['title'] === 'Member Exists') {
        // Member exists — update their tags instead
        newteam_update_mailchimp_member_tags($email, $tags, $list_id);
        return ['success' => true, 'message' => 'Email already subscribed, tags updated'];
    } else {
        error_log('Mailchimp API Response: ' . print_r($body, true));
        return ['success' => false, 'message' => isset($body['detail']) ? $body['detail'] : 'Failed to subscribe'];
    }
}

/**
 * Update tags for existing Mailchimp member
 *
 * Uses the Mailchimp Tags endpoint with subscriber hash (MD5 of lowercase email).
 * Each tag is set to 'active' status — existing tags are preserved.
 *
 * @param string $email Subscriber email
 * @param array $tags Tags to add/activate
 * @param string $list_id Mailchimp list ID
 * @return bool Success
 */
function newteam_update_mailchimp_member_tags($email, $tags, $list_id = '') {
    $api_key = newteam_get_mailchimp_api_key();
    $server = newteam_get_mailchimp_server();

    if (empty($list_id)) {
        $list_id = get_option('newteam_mailchimp_list_id', '');
    }

    if (empty($list_id)) return false;

    $subscriber_hash = md5(strtolower($email));
    $url = 'https://' . $server . '.api.mailchimp.com/3.0/lists/' . $list_id . '/members/' . $subscriber_hash . '/tags';

    $tag_objects = [];
    foreach ($tags as $tag) {
        $tag_objects[] = ['name' => $tag, 'status' => 'active'];
    }

    $response = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key),
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode(['tags' => $tag_objects]),
        'timeout' => 10
    ]);

    if (is_wp_error($response)) {
        error_log('Mailchimp Tag Update Error: ' . $response->get_error_message());
        return false;
    }

    return (wp_remote_retrieve_response_code($response) === 204);
}
