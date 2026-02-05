# System Architecture

Detailed flow diagrams for each transaction type in the Stripe Financial Integration system.

---

## Flow 1: Tryout Registration ($15 One-Time Payment)

```mermaid
sequenceDiagram
    participant Player
    participant React as TryoutPage.jsx
    participant PHP as PHP Backend
    participant Stripe
    participant Airtable as Airtable (System of Record)
    participant MC as Mailchimp
    participant Admin as Admin Email

    Player->>React: Fill form + enter card
    React->>React: elements.submit() — validate card
    React->>PHP: POST create_payment_intent_simple<br>{amount: 1500, email, name}
    PHP->>Stripe: POST /v1/payment_intents<br>{amount, currency, payment_method_types}
    Stripe-->>PHP: {client_secret}
    PHP-->>React: {success, client_secret}
    React->>Stripe: stripe.confirmPayment()<br>{elements, clientSecret, return_url}
    Stripe-->>React: {paymentIntent: {status: 'succeeded'}}

    React->>PHP: POST process_tryout_registration<br>{form_data, payment_intent_id, nonce}
    PHP->>PHP: Verify nonce (CSRF protection)
    PHP->>PHP: Sanitize & validate inputs
    PHP->>Airtable: POST /v0/{base}/{table}<br>{fields: transformed_data}
    Airtable-->>PHP: 200 OK
    PHP->>MC: POST /lists/{id}/members<br>{email, tags: ['Tryout Registration']}
    PHP->>Admin: wp_mail() — admin notification
    PHP-->>React: {success: true}
    React->>Player: Show success confirmation

    Note over Stripe,PHP: Webhook also fires (backup reconciliation)
    Stripe->>PHP: POST webhook<br>payment_intent.succeeded
    PHP->>PHP: Verify webhook signature
    PHP->>Admin: wp_mail() — webhook confirmation
```

---

## Flow 2: Season Registration — Monthly Subscription ($85/mo)

```mermaid
sequenceDiagram
    participant Player
    participant React as SeasonRegistrationPage.jsx
    participant PHP as PHP Backend
    participant Stripe
    participant Airtable as Airtable (System of Record)
    participant MC as Mailchimp
    participant Admin as Admin Email

    Player->>React: Select player type + equipment sizes
    Player->>React: Choose "Monthly Payments"
    React->>React: elements.submit() — validate card
    React->>Stripe: stripe.createPaymentMethod()<br>{elements, billing_details}
    Stripe-->>React: {paymentMethod: {id}}

    React->>PHP: POST create_season_subscription<br>{payment_method_id, email, player_type, nonce}
    PHP->>PHP: Verify nonce
    PHP->>Stripe: POST /v1/customers<br>{email, name, metadata}
    Stripe-->>PHP: {customer_id}
    PHP->>Stripe: POST /v1/payment_methods/{id}/attach<br>{customer}
    PHP->>Stripe: POST /v1/customers/{id}<br>{default_payment_method}
    PHP->>PHP: Get price_id for tier ($85/$98/$42/$55)
    PHP->>Stripe: POST /v1/subscriptions<br>{customer, price, cancel_at: +6months}
    Stripe-->>PHP: {subscription: {status: 'active'}}
    PHP-->>React: {success, subscription_id, customer_id}

    React->>PHP: POST process_season_registration<br>{all_form_data, subscription_id, customer_id, nonce}
    PHP->>PHP: Transform field values<br>socks: 'S' → 'S (US 5-7)'<br>status: 'monthly' → 'Pending'
    PHP->>Airtable: POST /v0/{base}/{table}<br>{fields: transformed_data}
    Airtable-->>PHP: 200 OK
    PHP->>MC: POST /lists/{id}/members<br>{tags: ['Season Registration', 'Full Season Player']}
    PHP->>Admin: wp_mail() — registration details + payment info
    PHP-->>React: {success: true}
```

---

## Flow 2b: Season Registration — Pay in Full ($510)

```mermaid
sequenceDiagram
    participant Player
    participant React as SeasonRegistrationPage.jsx
    participant PHP as PHP Backend
    participant Stripe
    participant Airtable as Airtable (System of Record)

    Player->>React: Choose "Pay in Full"
    React->>Stripe: stripe.createPaymentMethod()
    Stripe-->>React: {paymentMethod: {id}}

    React->>PHP: POST create_season_full_payment<br>{payment_method_id, total_amount: 510, nonce}
    PHP->>Stripe: POST /v1/customers<br>{email, name}
    Stripe-->>PHP: {customer_id}
    PHP->>Stripe: POST /v1/payment_methods/{id}/attach
    PHP->>Stripe: POST /v1/payment_intents<br>{amount: 51000, confirm: true, return_url}

    alt Payment Succeeds
        Stripe-->>PHP: {status: 'succeeded'}
        PHP-->>React: {success, payment_intent_id}
    else 3D Secure Required
        Stripe-->>PHP: {status: 'requires_action', client_secret}
        PHP-->>React: {requires_action: true, client_secret}
        React->>Stripe: stripe.confirmCardPayment(client_secret)
        Stripe-->>React: {paymentIntent: {status: 'succeeded'}}
    end

    React->>PHP: POST process_season_registration<br>{payment_frequency: 'full', payment_intent_id}
    PHP->>PHP: Transform: status → 'Paid', frequency → 'Paid in full'
    PHP->>Airtable: POST with transformed data
```

---

## Flow 3: Winter Protocol Lead Capture ($0)

```mermaid
sequenceDiagram
    participant Visitor
    participant React as WinterProtocolPage.jsx
    participant reCAPTCHA as Google reCAPTCHA
    participant PHP as PHP Backend
    participant MC as Mailchimp

    Visitor->>React: Enter name, email, traffic source
    React->>reCAPTCHA: grecaptcha.getResponse()
    reCAPTCHA-->>React: {recaptcha_token}

    React->>PHP: POST process_winter_protocol_signup<br>{first_name, last_name, email, traffic_source, nonce, recaptcha_token}
    PHP->>PHP: Verify nonce (CSRF protection)
    PHP->>PHP: Sanitize inputs

    alt Gibberish Detected
        PHP->>PHP: newteam_is_gibberish(name)<br>consonant ratio > 4:1 || no vowels || repeated chars
        PHP-->>React: {success: true} ← fake success (anti-bot measure)
        Note over PHP: Spam silently rejected.<br>Bot thinks it worked.
    else Valid Submission
        PHP->>MC: POST /lists/{id}/members<br>{email, tags: ['Winter Protocol Insider Club'],<br>merge_fields: {SOURCE: 'Instagram'}}

        alt New Subscriber
            MC-->>PHP: 201 Created
        else Existing Subscriber
            MC-->>PHP: 400 Member Exists
            PHP->>MC: POST /members/{hash}/tags<br>{tags: [{name: 'Winter Protocol', status: 'active'}]}
        end

        PHP-->>React: {success: true, message: 'Your download is ready!'}
        React->>Visitor: Show success + trigger PDF download
    end
```

---

## Failed Payment Recovery Flow

```mermaid
sequenceDiagram
    participant Stripe
    participant PHP as Webhook Handler
    participant Airtable as Airtable (System of Record)
    participant Player as Player Email
    participant Admin as Admin Email

    Stripe->>PHP: POST webhook<br>invoice.payment_failed<br>{attempt_count, amount_due, customer_email}
    PHP->>PHP: Verify webhook signature

    PHP->>Airtable: PATCH /records/{id}<br>{Payment Status: 'Payment Failed - Retry N'}

    alt Attempt 1
        PHP->>Player: "Quick heads up" — casual tone
    else Attempt 2
        PHP->>Player: "Following up" — troubleshooting tips
    else Attempt 3
        PHP->>Player: "Action needed" — roster spot at risk
    else Attempt 4+
        PHP->>Player: "Final notice" — subscription will be canceled
    end

    PHP->>Admin: wp_mail() — failed payment alert<br>{player info, amount, attempt count}

    Note over Stripe: If all retries fail...
    Stripe->>PHP: POST webhook<br>customer.subscription.deleted
    PHP->>Airtable: PATCH {Payment Status: 'Subscription Ended'}
```

---

## Infrastructure & Secrets Management

```mermaid
flowchart LR
    subgraph Docker ["Docker Compose"]
        WP[WordPress Container<br>Port 3000]
        DB[MariaDB Container<br>Persistent Volume]
    end

    subgraph Secrets ["Docker Secrets (/run/secrets/)"]
        S1[stripe_secret_key]
        S2[stripe_webhook_secret]
        S3[airtable_api_key]
        S4[mailchimp_api_key]
        S5[db_password]
        S6[smtp_password]
    end

    subgraph Resolution ["Credential Resolution Order"]
        D1["1. Docker Secrets (most secure)"]
        D2["2. PHP Constants (wp-config)"]
        D3["3. Environment Variables"]
    end

    Secrets --> WP
    WP --> DB

    D1 --> D2 --> D3

    style Secrets fill:#1a1a2e,stroke:#f59e0b,color:#fff
    style Docker fill:#0a0f1a,stroke:#dc2626,color:#fff
```

---

## Data Transformation Layer

The field mapping layer sits between the payment platform and the database, ensuring every value matches the exact schema:

| Source (Form/Stripe) | Transformation | Target (Airtable) |
|---------------------|----------------|-------------------|
| `'S'` | Socks size mapping | `'S (US 5-7)'` |
| `'M'` | Socks size mapping | `'M (US 7-9)'` |
| `'L'` | Socks size mapping | `'L (US 9-12)'` |
| `'XL'` | Socks size mapping | `'XL (US 12+)'` |
| `'full'` | Payment status | `'Paid'` |
| `'monthly'` | Payment status | `'Pending'` |
| `'full'` | Payment frequency | `'Paid in full'` |
| `'monthly'` | Payment frequency | `'Monthly'` |
| `'goalkeeper'` | Position normalization | `'Goalkeeper'` |
| `'high_school'` | Experience normalization | `'High School'` |
| `'semi_pro'` | Experience normalization | `'Semi-Professional'` |
| `'guest'` | Player type label | `'Guest Player'` |
| `'full_season'` | Player type label | `'Full Season Player'` |

Without these transformations, Airtable returns `422 INVALID_VALUE_FOR_COLUMN` and the record is never created — payments succeed but the financial database stays empty.

See [`data-pipeline/field-mapping.php`](../data-pipeline/field-mapping.php) for the implementation.
