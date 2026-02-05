<?php
/**
 * Internal Admin Notifications
 *
 * Sends email alerts to organization admin on key payment events:
 * - New tryout registration (with player details and payment ID)
 * - New season registration (with equipment sizes and payment info)
 * - Failed payment alerts (with escalation status)
 *
 * These notifications ensure no payment goes unnoticed and
 * give the admin immediate visibility into financial activity.
 */

/**
 * Send tryout registration admin notification
 *
 * Triggered after payment confirmation. Includes all player
 * information and the Stripe Payment Intent ID for audit trail.
 *
 * @param array $form_data Sanitized player registration data
 * @param string $payment_intent_id Stripe Payment Intent ID
 */
function newteam_send_tryout_admin_notification($form_data, $payment_intent_id) {
    $admin_subject = 'New Tryout Registration - ' . $form_data['first_name'] . ' ' . $form_data['last_name'];
    $admin_message = "New tryout registration received:\n\n";
    $admin_message .= "PLAYER INFORMATION:\n";
    $admin_message .= "Name: {$form_data['first_name']} {$form_data['last_name']}\n";
    $admin_message .= "Email: {$form_data['email']}\n";
    $admin_message .= "Phone: {$form_data['phone']}\n";
    $admin_message .= "Position: {$form_data['position']}\n";
    $admin_message .= "Experience: {$form_data['experience']}\n";
    $admin_message .= "Tryout Date: {$form_data['tryout_date']}\n\n";
    $admin_message .= "PAYMENT INFORMATION:\n";
    $admin_message .= "Stripe Payment Intent ID: {$payment_intent_id}\n";

    wp_mail('info@newteamfc.com', $admin_subject, $admin_message, [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Newteam F.C. Registration <noreply@newteamfc.com>'
    ]);
}

/**
 * Send season registration admin notification
 *
 * Includes full player info, equipment sizes, payment details,
 * and whether the player paid in full or started a subscription.
 *
 * @param array $form_data Complete registration data
 */
function newteam_send_season_admin_notification($form_data) {
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
    $admin_message .= "Socks: {$form_data['socks_size']}\n";
    $personalization = $form_data['name_personalization'] ? 'YES' : 'No';
    $admin_message .= "Name Personalization: {$personalization}\n\n";

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
}
