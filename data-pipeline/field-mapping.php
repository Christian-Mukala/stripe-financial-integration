<?php
/**
 * Data Transformation & Field Mapping
 *
 * Centralized data integrity logic for transforming raw form/payment data
 * into values compatible with the system of record (Airtable).
 *
 * This file contains the mapping rules that fixed the original data pipeline
 * failure: Airtable returned 422 errors because select field values from the
 * payment form didn't match the exact schema values in the database.
 *
 * Example: Form sends 'S' for socks → Airtable expects 'S (US 5-7)'
 * Example: Form sends 'Paid in Full' → Airtable expects 'Paid in full' (case-sensitive)
 *
 * Without these transformations, payments succeed in Stripe but the corresponding
 * records never appear in the financial database — a reconciliation gap.
 */

/**
 * Socks Size Mapping
 *
 * The React form sends abbreviated size codes for UX simplicity.
 * Airtable's select field requires the full label with US sizing.
 *
 * Mismatch caused: 422 INVALID_VALUE_FOR_COLUMN error
 */
function newteam_map_socks_size($raw_value) {
    $socks_size_map = [
        'S'  => 'S (US 5-7)',
        'M'  => 'M (US 7-9)',
        'L'  => 'L (US 9-12)',
        'XL' => 'XL (US 12+)'
    ];

    return $socks_size_map[$raw_value] ?? $raw_value;
}

/**
 * Payment Status Mapping
 *
 * Transforms payment frequency into a GL-compatible status value.
 * - "full" payment → "Paid" (immediate revenue recognition)
 * - "monthly" subscription → "Pending" (deferred revenue, updated by webhook)
 *
 * The status field is updated throughout the subscription lifecycle:
 * - "Pending" → initial subscription state
 * - "Paid" → all payments received or paid in full
 * - "Payment Failed - Retry 1" → first failed payment attempt
 * - "Payment Failed - Final Warning" → 3+ failed attempts
 * - "Subscription Ended" → subscription canceled or expired
 */
function newteam_map_payment_status($payment_frequency) {
    return $payment_frequency === 'full' ? 'Paid' : 'Pending';
}

/**
 * Payment Frequency Label Mapping
 *
 * Normalizes payment frequency for the database.
 * Must match the Airtable select field exactly (case-sensitive).
 *
 * Original bug: 'Paid in Full' (capital F) didn't match 'Paid in full' (lowercase f)
 */
function newteam_map_payment_frequency($payment_frequency) {
    return $payment_frequency === 'full' ? 'Paid in full' : 'Monthly';
}

/**
 * Position Value Normalization
 *
 * Transforms form slug values to display-ready strings.
 * Used for both Airtable storage and Mailchimp merge fields.
 */
function newteam_map_position($raw_value) {
    $position_map = [
        'goalkeeper'  => 'Goalkeeper',
        'defender'    => 'Defender',
        'midfielder'  => 'Midfielder',
        'forward'     => 'Forward'
    ];

    return $position_map[$raw_value] ?? $raw_value;
}

/**
 * Experience Level Normalization
 *
 * Same pattern — form slugs → human-readable values for database storage.
 */
function newteam_map_experience($raw_value) {
    $experience_map = [
        'high_school'    => 'High School',
        'club'           => 'Club',
        'college'        => 'College',
        'semi_pro'       => 'Semi-Professional',
        'professional'   => 'Professional'
    ];

    return $experience_map[$raw_value] ?? $raw_value;
}

/**
 * Player Type Label Mapping
 *
 * Transforms internal player type codes to display labels.
 */
function newteam_map_player_type($player_type) {
    return $player_type === 'guest' ? 'Guest Player' : 'Full Season Player';
}

/**
 * Apply all field transformations to a season registration record
 *
 * Takes raw form data and returns a clean, schema-compatible field array
 * ready for Airtable API submission.
 *
 * @param array $form_data Raw form data from registration
 * @return array Transformed fields matching Airtable schema exactly
 */
function newteam_transform_season_registration($form_data) {
    $is_paid_in_full = ($form_data['payment_frequency'] ?? 'monthly') === 'full';

    return [
        'First Name'           => $form_data['first_name'],
        'Last Name'            => $form_data['last_name'],
        'Email'                => $form_data['email'],
        'Age'                  => $form_data['age'],
        'Positions'            => $form_data['position'],
        'Tracksuit Size'       => $form_data['tracksuit_size'],
        'Practice Jersey Size' => $form_data['practice_jersey_size'],
        'Shorts Size'          => $form_data['shorts_size'],
        'Socks Size'           => newteam_map_socks_size($form_data['socks_size']),
        'Player Type'          => newteam_map_player_type($form_data['player_type']),
        'Name Personalization' => $form_data['name_personalization'] ? 'Yes' : 'No',
        'Payment Amount'       => $form_data['payment_amount'],
        'Payment Frequency'    => newteam_map_payment_frequency($form_data['payment_frequency']),
        'Subscription ID'      => $form_data['subscription_id'] ?? '',
        'Payment Intent ID'    => $form_data['payment_intent_id'] ?? '',
        'Stripe Customer ID'   => $form_data['customer_id'],
        'Stripe Payment ID'    => $form_data['payment_intent_id'] ?? $form_data['subscription_id'] ?? '',
        'Payment Status'       => newteam_map_payment_status($form_data['payment_frequency']),
        'Waiver Agreement'     => 'Waiver Signed',
        'Registration date'    => date('Y-m-d')
    ];
}

/**
 * Apply all field transformations to a tryout registration record
 *
 * @param array $form_data Raw form data from tryout registration
 * @param string $payment_intent_id Stripe Payment Intent ID
 * @return array Transformed fields matching Airtable schema
 */
function newteam_transform_tryout_registration($form_data, $payment_intent_id) {
    return [
        'First Name'        => $form_data['first_name'],
        'Last Name'         => $form_data['last_name'],
        'Email'             => $form_data['email'],
        'Phone'             => $form_data['phone'],
        'Date of Birth'     => $form_data['date_of_birth'],
        'Position'          => newteam_map_position($form_data['position']),
        'Experience'        => newteam_map_experience($form_data['experience']),
        'Tryout Date'       => $form_data['tryout_date'],
        'Stripe Payment ID' => $payment_intent_id,
        'Registration Date' => date('Y-m-d'),
        'Status'            => 'Registered'
    ];
}
