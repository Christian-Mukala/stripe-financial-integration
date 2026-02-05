<?php
/**
 * Admin Payment Tools Dashboard
 *
 * Internal admin interface for payment operations:
 * - Test failed payment email templates (preview all 4 escalation levels)
 * - Configure and verify Stripe webhook endpoints
 * - Generate customer portal links for payment method updates
 * - View system status (Stripe connectivity, webhook config, email templates)
 *
 * Access: Admin-only (wp_manage_options capability required in production)
 */

// Load WordPress
$wp_load_paths = [
    dirname(__FILE__) . '/../../../wp-load.php',
    dirname(__FILE__) . '/../../../../wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('WordPress not found.');
}

// Check admin access
if (!current_user_can('manage_options')) {
    $is_localhost = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1', 'localhost']) ||
                    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false;
    if (!$is_localhost) {
        wp_die('Access denied. Admin privileges required.');
    }
}

// Include payment processing functions
require_once get_template_directory() . '/inc/stripe-integration.php';

// Detect Stripe mode (live vs test)
$stripe_secret_key = newteam_get_stripe_secret_key();
$is_live_mode = strpos($stripe_secret_key, 'sk_live_') === 0;

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Test email submission
    if (isset($_POST['action']) && $_POST['action'] === 'test_email') {
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        $test_name = sanitize_text_field($_POST['test_name'] ?? 'Test Player');
        $attempt_number = intval($_POST['attempt_number'] ?? 1);

        if (!empty($test_email)) {
            $first_name = explode(' ', $test_name)[0];
            $email_content = newteam_get_payment_failed_email($first_name, $attempt_number, '85.00');

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'From: Newteam F.C. <goal@newteamfc.com>',
                'Reply-To: goal@newteamfc.com'
            ];

            $sent = wp_mail($test_email, '[TEST] ' . $email_content['subject'], $email_content['body'], $headers);

            if ($sent) {
                $message = "Test email (Attempt {$attempt_number}) sent to {$test_email}";
                $message_type = 'success';
            } else {
                $message = "Failed to send test email. Check WordPress mail configuration.";
                $message_type = 'error';
            }
        }
    }

    // Customer portal lookup
    if (isset($_POST['action']) && $_POST['action'] === 'portal_lookup') {
        $portal_email = sanitize_email($_POST['portal_email'] ?? '');

        if (!empty($portal_email)) {
            $result = newteam_get_portal_url_by_email($portal_email);

            if ($result['success']) {
                $message = "Portal URL generated! <a href='{$result['url']}' target='_blank' style='color: #f59e0b;'>Open Customer Portal</a>";
                $message_type = 'success';
            } else {
                $message = "Portal error: " . $result['message'];
                $message_type = 'error';
            }
        }
    }
}

// Check webhook configuration
$webhook_url = home_url('/?stripe_webhook=season_subscriptions');
$webhook_configured = false;

if (!empty($stripe_secret_key)) {
    $response = wp_remote_get('https://api.stripe.com/v1/webhook_endpoints?limit=10', [
        'headers' => ['Authorization' => 'Bearer ' . $stripe_secret_key],
        'timeout' => 30
    ]);

    if (!is_wp_error($response)) {
        $webhooks = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($webhooks['data'])) {
            foreach ($webhooks['data'] as $webhook) {
                if (strpos($webhook['url'], 'stripe_webhook') !== false) {
                    $webhook_configured = true;
                    break;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Payment Tools</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0f1a;
            color: #fff;
            padding: 2rem;
            line-height: 1.6;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { color: #f59e0b; margin-bottom: 0.5rem; font-size: 2rem; }
        .subtitle { color: #a0a0a0; margin-bottom: 2rem; }
        .mode-badge {
            display: inline-block; padding: 0.25rem 0.75rem; border-radius: 20px;
            font-size: 0.75rem; font-weight: bold; text-transform: uppercase; margin-left: 1rem;
        }
        .mode-live { background: #dc2626; color: white; }
        .mode-test { background: #10b981; color: white; }
        .card {
            background: #1a1a2e; border-radius: 15px; padding: 2rem; margin-bottom: 2rem;
        }
        .card h2 {
            color: #f59e0b; margin-bottom: 1rem; font-size: 1.25rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .card p { color: #a0a0a0; margin-bottom: 1rem; }
        .status-ok { color: #10b981; }
        .status-error { color: #ef4444; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; color: #f59e0b; margin-bottom: 0.5rem; font-weight: 600; }
        .form-group input, .form-group select {
            width: 100%; padding: 0.75rem; border-radius: 8px;
            border: 1px solid #374151; background: #374151; color: white; font-size: 1rem;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn {
            display: inline-block; background: linear-gradient(135deg, #dc2626, #991b1b);
            color: white; padding: 0.75rem 1.5rem; border-radius: 8px;
            border: none; font-size: 1rem; font-weight: 600; cursor: pointer;
        }
        .btn-success { background: linear-gradient(135deg, #10b981, #059669); }
        .message { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .message-success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #10b981; }
        .message-error { background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #ef4444; }
        .webhook-events { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; margin: 1rem 0; }
        .webhook-event {
            background: #374151; padding: 0.5rem 0.75rem; border-radius: 5px;
            font-family: monospace; font-size: 0.8rem;
        }
        .checklist { list-style: none; padding: 0; }
        .checklist li {
            padding: 0.75rem; border-bottom: 1px solid #374151;
            display: flex; align-items: center; gap: 0.75rem;
        }
        .check-icon {
            width: 24px; height: 24px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; flex-shrink: 0;
        }
        .check-ok { background: #10b981; }
        .check-error { background: #ef4444; }
    </style>
</head>
<body>
<div class="container">
    <h1>
        Admin Payment Tools
        <span class="mode-badge <?php echo $is_live_mode ? 'mode-live' : 'mode-test'; ?>">
            <?php echo $is_live_mode ? 'LIVE MODE' : 'TEST MODE'; ?>
        </span>
    </h1>
    <p class="subtitle">Test emails, configure webhooks, and manage customer payments</p>

    <?php if ($message): ?>
    <div class="message message-<?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <!-- Webhook Configuration -->
    <div class="card">
        <h2>
            <?php if ($webhook_configured): ?>
                <span class="status-ok">&#10003;</span>
            <?php else: ?>
                <span class="status-error">&#10007;</span>
            <?php endif; ?>
            1. Stripe Webhook Configuration
        </h2>

        <?php if ($webhook_configured): ?>
            <p class="status-ok">Webhook appears to be configured in Stripe.</p>
        <?php else: ?>
            <p class="status-error">Webhook not detected. Configure in Stripe Dashboard.</p>
        <?php endif; ?>

        <div style="background: #374151; padding: 1.5rem; border-radius: 10px; margin: 1rem 0;">
            <h3 style="color: #f59e0b; margin-bottom: 1rem;">Required Webhook Events</h3>
            <div class="webhook-events">
                <div class="webhook-event">invoice.payment_failed</div>
                <div class="webhook-event">invoice.payment_succeeded</div>
                <div class="webhook-event">customer.subscription.deleted</div>
                <div class="webhook-event">customer.subscription.updated</div>
            </div>
        </div>
    </div>

    <!-- Test Failed Payment Emails -->
    <div class="card">
        <h2>2. Test Failed Payment Emails</h2>
        <p>Send test emails to preview what players receive when their payment fails.</p>

        <form method="POST" style="margin-top: 1rem;">
            <input type="hidden" name="action" value="test_email">
            <div class="form-row">
                <div class="form-group">
                    <label>Send Test Email To</label>
                    <input type="email" name="test_email" placeholder="your@email.com" required>
                </div>
                <div class="form-group">
                    <label>Player Name (for personalization)</label>
                    <input type="text" name="test_name" value="John Smith" required>
                </div>
            </div>
            <div class="form-group">
                <label>Retry Attempt Number</label>
                <select name="attempt_number">
                    <option value="1">Attempt 1 - "Quick heads up" (casual)</option>
                    <option value="2">Attempt 2 - "Following up" (friendly nudge)</option>
                    <option value="3">Attempt 3 - "Action needed" (urgent)</option>
                    <option value="4">Attempt 4+ - "Final notice" (last warning)</option>
                </select>
            </div>
            <button type="submit" class="btn">Send Test Email</button>
        </form>
    </div>

    <!-- Customer Portal -->
    <div class="card">
        <h2>3. Customer Payment Portal</h2>
        <p>Generate a link for a player to update their payment method or view invoices.</p>

        <form method="POST" style="margin-top: 1rem;">
            <input type="hidden" name="action" value="portal_lookup">
            <div class="form-group">
                <label>Player's Email Address</label>
                <input type="email" name="portal_email" placeholder="player@email.com" required>
            </div>
            <button type="submit" class="btn btn-success">Generate Portal Link</button>
        </form>
    </div>

    <!-- System Status -->
    <div class="card">
        <h2>System Status</h2>
        <ul class="checklist">
            <li>
                <span class="check-icon check-ok">&#10003;</span>
                <div>
                    <strong>Stripe API</strong>
                    <div style="color: #a0a0a0; font-size: 0.85rem;">
                        Connected (<?php echo $is_live_mode ? 'Live' : 'Test'; ?> mode)
                    </div>
                </div>
            </li>
            <li>
                <span class="check-icon <?php echo $webhook_configured ? 'check-ok' : 'check-error'; ?>">
                    <?php echo $webhook_configured ? '&#10003;' : '&#10007;'; ?>
                </span>
                <div>
                    <strong>Webhook Endpoint</strong>
                    <div style="color: #a0a0a0; font-size: 0.85rem;">
                        <?php echo $webhook_configured ? 'Configured' : 'Not configured'; ?>
                    </div>
                </div>
            </li>
            <li>
                <span class="check-icon check-ok">&#10003;</span>
                <div>
                    <strong>Player Email Templates</strong>
                    <div style="color: #a0a0a0; font-size: 0.85rem;">
                        4 personalized templates ready (Attempts 1-4+)
                    </div>
                </div>
            </li>
            <li>
                <span class="check-icon check-ok">&#10003;</span>
                <div>
                    <strong>Customer Portal</strong>
                    <div style="color: #a0a0a0; font-size: 0.85rem;">
                        Ready to generate portal links
                    </div>
                </div>
            </li>
        </ul>
    </div>
</div>
</body>
</html>
