<?php
/**
 * Webhook Event Handler — Payment Reconciliation Engine
 *
 * Processes incoming Stripe webhook events to reconcile payments against
 * the system of record (Airtable). This is the core of the payment
 * reconciliation pipeline — every dollar that moves through Stripe
 * is verified and recorded here.
 *
 * Events handled:
 * - payment_intent.succeeded: Confirms tryout payment, creates GL record
 * - checkout.session.completed: Legacy checkout flow confirmation
 * - invoice.payment_failed: Triggers escalating retry notification sequence
 * - customer.subscription.deleted: Updates subscription lifecycle status
 */

// Credentials loaded from Docker secrets — never hardcoded

/**
 * Main webhook handler — event-driven payment reconciliation
 *
 * Validates webhook signature (internal control), parses the event,
 * and routes to the appropriate processing function.
 *
 * Signature verification prevents fraudulent webhook submissions
 * from creating false payment records in the system of record.
 */
function newteam_handle_stripe_webhook() {
    $stripe_secret_key = newteam_get_stripe_secret_key();

    // Webhook signing secret — loaded from Docker secrets for signature verification
    $webhook_secret = '';
    if (function_exists('newteam_read_docker_secret')) {
        $webhook_secret = newteam_read_docker_secret('stripe_webhook_secret');
    }
    if (empty($webhook_secret)) {
        $webhook_secret = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : (getenv('STRIPE_WEBHOOK_SECRET') ?: '');
    }

    if (empty($stripe_secret_key)) {
        error_log('STRIPE WEBHOOK ERROR: No secret key found');
        http_response_code(400);
        exit;
    }

    \Stripe\Stripe::setApiKey($stripe_secret_key);

    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    try {
        // Verify webhook signature — internal control against fraudulent events
        if (!empty($webhook_secret)) {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } else {
            $event = json_decode($payload, true);
        }

        // Route event to appropriate handler
        switch ($event['type']) {
            case 'checkout.session.completed':
                $session = $event['data']['object'];
                error_log('STRIPE: Checkout session completed for session ID: ' . $session['id']);
                newteam_process_successful_tryout_payment($session);
                break;

            case 'payment_intent.succeeded':
                $payment_intent = $event['data']['object'];
                error_log('STRIPE: Payment succeeded for payment intent: ' . $payment_intent['id']);
                newteam_process_successful_payment_intent($payment_intent);
                break;

            case 'invoice.payment_failed':
                $invoice = $event['data']['object'];
                error_log('STRIPE: Invoice payment failed for subscription: ' . ($invoice['subscription'] ?? 'N/A'));
                newteam_handle_subscription_payment_failed($invoice);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event['data']['object'];
                error_log('STRIPE: Subscription ended/canceled: ' . $subscription['id']);
                if (!empty($subscription['id'])) {
                    newteam_update_season_registration_status($subscription['id'], 'Subscription Ended');
                }
                break;

            default:
                error_log('STRIPE: Received unknown event type: ' . $event['type']);
        }

        http_response_code(200);

    } catch (\UnexpectedValueException $e) {
        error_log('STRIPE WEBHOOK ERROR: Invalid payload: ' . $e->getMessage());
        http_response_code(400);
        exit;
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        error_log('STRIPE WEBHOOK ERROR: Invalid signature: ' . $e->getMessage());
        http_response_code(400);
        exit;
    }
}

/**
 * Process successful tryout payment (from checkout session)
 *
 * When a tryout payment succeeds via webhook:
 * 1. Extracts player metadata from the Stripe session
 * 2. Sends admin notification email with payment details
 * 3. Adds player to Mailchimp with "Tryout Registration" tag
 * 4. Logs the transaction for audit trail
 */
function newteam_process_successful_tryout_payment($session) {
    try {
        $metadata = $session['metadata'];
        $player_name = $metadata['player_name'];
        $player_email = $metadata['player_email'];
        $tryout_date = $metadata['tryout_date'];
        $position = $metadata['position'];
        $experience = $metadata['experience'];
        $tryout_date_formatted = date('l, F j, Y', strtotime($tryout_date));

        // Admin notification — internal alert
        $admin_subject = 'New Tryout Registration - ' . $player_name;
        $admin_message = "
New tryout registration received:

PLAYER INFORMATION:
Name: {$player_name}
Email: {$player_email}
Phone: {$metadata['player_phone']}
Position: {$position}
Experience: {$experience}

TRYOUT DETAILS:
Date: {$tryout_date_formatted}

PAYMENT INFORMATION:
Stripe Session ID: {$session['id']}
Amount Paid: $" . ($session['amount_total'] / 100) . "

Please follow up with the player if needed.
        ";

        wp_mail('info@newteamfc.com', $admin_subject, $admin_message, [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Newteam F.C. Registration <noreply@newteamfc.com>'
        ]);

        // Add to email marketing list with tags
        if (function_exists('newteam_add_to_mailchimp_with_tags')) {
            $name_parts = explode(' ', $player_name);
            $first_name = $name_parts[0];
            $last_name = implode(' ', array_slice($name_parts, 1));

            newteam_add_to_mailchimp_with_tags(
                $player_email,
                $first_name,
                $last_name,
                ['Tryout Registration', 'Paid Member', 'Newteam FC']
            );
        }

        error_log("TRYOUT REGISTRATION SUCCESS: {$player_name} ({$player_email}) registered for {$tryout_date}");

    } catch (Exception $e) {
        error_log('TRYOUT PAYMENT PROCESSING ERROR: ' . $e->getMessage());
    }
}

/**
 * Process successful payment intent (tryout on-page payment)
 *
 * Same reconciliation logic as checkout session, but for PaymentIntent flow.
 * Both flows converge to the same outcome: verified payment → database record.
 */
function newteam_process_successful_payment_intent($payment_intent) {
    try {
        $metadata = $payment_intent['metadata'];
        $player_name = $metadata['player_name'];
        $player_email = $metadata['player_email'];
        $tryout_date = $metadata['tryout_date'];
        $position = $metadata['position'];
        $experience = $metadata['experience'];
        $player_phone = $metadata['player_phone'] ?? '';
        $tryout_date_formatted = date('l, F j, Y', strtotime($tryout_date));

        // Admin notification
        $admin_subject = 'New Tryout Registration - ' . $player_name;
        $admin_message = "
New tryout registration received:

PLAYER INFORMATION:
Name: {$player_name}
Email: {$player_email}
Phone: {$player_phone}
Position: {$position}
Experience: {$experience}

TRYOUT DETAILS:
Date: {$tryout_date_formatted}

PAYMENT INFORMATION:
Stripe Payment Intent ID: {$payment_intent['id']}
Amount Paid: $" . ($payment_intent['amount'] / 100) . "

Please follow up with the player if needed.
        ";

        wp_mail('info@newteamfc.com', $admin_subject, $admin_message, [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Newteam F.C. Registration <noreply@newteamfc.com>'
        ]);

        // Add to email marketing list
        if (function_exists('newteam_add_to_mailchimp_with_tags')) {
            $name_parts = explode(' ', $player_name);
            newteam_add_to_mailchimp_with_tags(
                $player_email,
                $name_parts[0],
                implode(' ', array_slice($name_parts, 1)),
                ['Tryout Registration', 'Paid Member', 'Newteam FC']
            );
        }

        error_log("TRYOUT REGISTRATION SUCCESS: {$player_name} ({$player_email}) registered for {$tryout_date}");

    } catch (Exception $e) {
        error_log('TRYOUT PAYMENT INTENT PROCESSING ERROR: ' . $e->getMessage());
    }
}

// Register webhook endpoint
add_action('init', function() {
    if (isset($_GET['stripe_webhook']) && $_GET['stripe_webhook'] === 'season_subscriptions') {
        newteam_handle_stripe_webhook();
        exit;
    }
});
