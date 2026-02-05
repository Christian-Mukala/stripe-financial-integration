<?php
/**
 * Payment Failure Notification System
 *
 * Sends escalating retry emails when subscription payments fail.
 * Each retry attempt gets a different message with increasing urgency
 * but always maintaining a supportive, human tone.
 *
 * Email sequence:
 * - Attempt 1: "Quick heads up" — casual, no big deal
 * - Attempt 2: "Following up" — friendly nudge with troubleshooting tips
 * - Attempt 3: "Action needed" — urgent, roster spot at risk
 * - Attempt 4+: "Final notice" — last warning before cancellation
 *
 * Also notifies admin on every failed payment with player details
 * and escalation status.
 */

/**
 * Handle subscription payment failure from webhook
 *
 * Called by the webhook handler when invoice.payment_failed fires.
 * Updates the system of record status and sends personalized
 * notification emails to both the player and admin.
 *
 * @param array $invoice Stripe Invoice object from webhook event
 */
function newteam_handle_subscription_payment_failed($invoice) {
    $customer_email = $invoice['customer_email'] ?? '';
    $customer_name = $invoice['customer_name'] ?? '';
    $subscription_id = $invoice['subscription'] ?? '';
    $attempt_count = $invoice['attempt_count'] ?? 1;
    $amount_due = isset($invoice['amount_due']) ? number_format($invoice['amount_due'] / 100, 2) : '0.00';

    error_log("SUBSCRIPTION PAYMENT FAILED: Subscription {$subscription_id}, Attempt {$attempt_count}, Email: {$customer_email}");

    // Extract first name for personalized greeting
    $first_name = '';
    if (!empty($customer_name)) {
        $name_parts = explode(' ', $customer_name);
        $first_name = $name_parts[0];
    }
    $greeting_name = !empty($first_name) ? $first_name : 'there';

    // Update system of record with failure status
    if ($subscription_id) {
        $status = $attempt_count >= 3 ? 'Payment Failed - Final Warning' : 'Payment Failed - Retry ' . $attempt_count;
        newteam_update_season_registration_status($subscription_id, $status);
    }

    // Send personalized player notification
    if (!empty($customer_email)) {
        $player_email_content = newteam_get_payment_failed_email($greeting_name, $attempt_count, $amount_due);

        $player_headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Newteam F.C. <goal@newteamfc.com>',
            'Reply-To: goal@newteamfc.com'
        ];

        $email_sent = wp_mail(
            $customer_email,
            $player_email_content['subject'],
            $player_email_content['body'],
            $player_headers
        );

        if ($email_sent) {
            error_log("PAYMENT FAILED EMAIL: Sent attempt {$attempt_count} notification to {$customer_email}");
        } else {
            error_log("PAYMENT FAILED EMAIL ERROR: Failed to send to {$customer_email}");
        }
    }

    // Admin notification
    $admin_subject = "Payment Failed (Attempt {$attempt_count}): {$customer_name}";
    $admin_message = "A season registration subscription payment has failed.\n\n";
    $admin_message .= "PLAYER INFO:\n";
    $admin_message .= "Name: {$customer_name}\n";
    $admin_message .= "Email: {$customer_email}\n\n";
    $admin_message .= "PAYMENT INFO:\n";
    $admin_message .= "Amount Due: \${$amount_due}\n";
    $admin_message .= "Attempt Number: {$attempt_count}\n";
    $admin_message .= "Subscription ID: {$subscription_id}\n\n";

    if ($attempt_count >= 3) {
        $admin_message .= "URGENT: This is attempt {$attempt_count}. Subscription may be canceled soon if not resolved.\n\n";
    }

    $admin_message .= "Player has been automatically notified via email.";

    wp_mail('goal@newteamfc.com', $admin_subject, $admin_message, [
        'Content-Type: text/plain; charset=UTF-8',
        'From: Newteam F.C. System <noreply@newteamfc.com>'
    ]);
}

/**
 * Get personalized payment failed email content based on attempt number
 *
 * Each retry gets a different, progressively more urgent but still
 * supportive message. HTML emails with branded styling.
 *
 * @param string $first_name Player's first name for personalization
 * @param int $attempt_count Which retry attempt this is (1, 2, 3, 4+)
 * @param string $amount_due Formatted amount (e.g., "85.00")
 * @return array ['subject' => string, 'body' => string]
 */
function newteam_get_payment_failed_email($first_name, $attempt_count, $amount_due) {
    switch ($attempt_count) {
        case 1:
            $subject = "Quick heads up about your Newteam payment";
            $body = "
            <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #dc2626, #991b1b); padding: 20px; border-radius: 10px 10px 0 0;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>Hey {$first_name}!</h1>
                </div>
                <div style='background: #1a1a2e; color: #e0e0e0; padding: 30px; border-radius: 0 0 10px 10px;'>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        Just wanted to give you a quick heads up — we tried to process your monthly payment of <strong style='color: #f59e0b;'>\${$amount_due}</strong> for the season, but it didn't go through.
                    </p>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        No worries at all! This happens sometimes. We'll automatically try again in a couple days. If you think there might be an issue with your card, you can update your payment info anytime.
                    </p>
                    <p style='font-size: 16px; line-height: 1.6; color: #a0a0a0;'>
                        If everything's good on your end, just ignore this and we'll take care of it on the next try.
                    </p>
                    <p style='font-size: 16px; line-height: 1.6; margin-top: 30px;'>
                        See you on the pitch!<br>
                        <strong style='color: #f59e0b;'>— The Newteam Crew</strong>
                    </p>
                </div>
                <div style='text-align: center; padding: 20px; color: #666; font-size: 12px;'>
                    Questions? Just reply to this email or hit us up at goal@newteamfc.com
                </div>
            </div>";
            break;

        case 2:
            $subject = "Following up on your Newteam payment";
            $body = "
            <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #dc2626, #991b1b); padding: 20px; border-radius: 10px 10px 0 0;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>Hey {$first_name}, checking in</h1>
                </div>
                <div style='background: #1a1a2e; color: #e0e0e0; padding: 30px; border-radius: 0 0 10px 10px;'>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        We gave your payment another shot today for your season dues (<strong style='color: #f59e0b;'>\${$amount_due}</strong>), but it still didn't go through.
                    </p>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        Could you take a quick look at your card when you get a chance? Sometimes it's just a matter of:
                    </p>
                    <ul style='font-size: 15px; line-height: 1.8; color: #a0a0a0;'>
                        <li>Card expired and needs updating</li>
                        <li>Bank flagged it as unusual (just approve it in your banking app)</li>
                        <li>Insufficient funds at the time we tried</li>
                    </ul>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        We'll try one more time in a few days. If you need to update your payment method, just reply to this email and we'll send you a link.
                    </p>
                    <p style='font-size: 16px; line-height: 1.6; margin-top: 30px;'>
                        Thanks for being part of the team!<br>
                        <strong style='color: #f59e0b;'>— The Newteam Crew</strong>
                    </p>
                </div>
                <div style='text-align: center; padding: 20px; color: #666; font-size: 12px;'>
                    Need help? Reply to this email or reach us at goal@newteamfc.com
                </div>
            </div>";
            break;

        case 3:
            $subject = "Important: Action needed for your Newteam spot";
            $body = "
            <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #dc2626, #991b1b); padding: 20px; border-radius: 10px 10px 0 0;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>{$first_name}, we need your help</h1>
                </div>
                <div style='background: #1a1a2e; color: #e0e0e0; padding: 30px; border-radius: 0 0 10px 10px;'>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        Hey, we've tried a few times now to process your <strong style='color: #f59e0b;'>\${$amount_due}</strong> payment but haven't been able to get it through.
                    </p>
                    <div style='background: rgba(220, 38, 38, 0.2); border: 1px solid #dc2626; border-radius: 8px; padding: 15px; margin: 20px 0;'>
                        <p style='font-size: 15px; line-height: 1.6; margin: 0; color: #ff6b6b;'>
                            <strong>Heads up:</strong> If we can't process payment soon, we'll have to pause your roster spot. We really don't want that to happen!
                        </p>
                    </div>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        Can you reply to this email or shoot us a message at <strong>goal@newteamfc.com</strong> so we can sort this out together?
                    </p>
                    <p style='font-size: 16px; line-height: 1.6; margin-top: 30px;'>
                        Talk soon,<br>
                        <strong style='color: #f59e0b;'>— The Newteam Crew</strong>
                    </p>
                </div>
                <div style='text-align: center; padding: 20px; color: #666; font-size: 12px;'>
                    Reply to this email or contact us at goal@newteamfc.com — we're here to help!
                </div>
            </div>";
            break;

        default:
            $subject = "Final notice: Your Newteam roster spot";
            $body = "
            <div style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #dc2626, #991b1b); padding: 20px; border-radius: 10px 10px 0 0;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>{$first_name}, this is our last try</h1>
                </div>
                <div style='background: #1a1a2e; color: #e0e0e0; padding: 30px; border-radius: 0 0 10px 10px;'>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        We've been trying to reach you about your payment. We really want to keep you on the team, but we haven't been able to process your <strong style='color: #f59e0b;'>\${$amount_due}</strong> payment after several attempts.
                    </p>
                    <div style='background: rgba(220, 38, 38, 0.3); border: 2px solid #dc2626; border-radius: 8px; padding: 20px; margin: 20px 0;'>
                        <p style='font-size: 16px; line-height: 1.6; margin: 0; color: #ff6b6b;'>
                            <strong>Your subscription will be canceled if we don't hear from you.</strong>
                        </p>
                        <p style='font-size: 14px; line-height: 1.6; margin: 10px 0 0 0; color: #e0e0e0;'>
                            This means you'd lose your roster spot and equipment. We don't want that!
                        </p>
                    </div>
                    <p style='font-size: 16px; line-height: 1.6;'>
                        <strong>Please reach out today</strong> — reply to this email or contact us at <strong>goal@newteamfc.com</strong>. Even if you're having financial difficulties, let's talk. We might be able to work something out.
                    </p>
                    <p style='font-size: 16px; line-height: 1.6; margin-top: 30px;'>
                        We're rooting for you,<br>
                        <strong style='color: #f59e0b;'>— The Newteam Crew</strong>
                    </p>
                </div>
                <div style='text-align: center; padding: 20px; color: #666; font-size: 12px;'>
                    Contact us ASAP at goal@newteamfc.com — we want to help!
                </div>
            </div>";
            break;
    }

    return [
        'subject' => $subject,
        'body' => $body
    ];
}
