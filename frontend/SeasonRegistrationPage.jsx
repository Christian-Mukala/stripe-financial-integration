import React, { useState, useEffect } from 'react';

const SeasonRegistrationPage = () => {
  // Responsive hook
  const [isMobile, setIsMobile] = useState(window.innerWidth <= 768);

  useEffect(() => {
    const handleResize = () => setIsMobile(window.innerWidth <= 768);
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  // Form state
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    age: '',
    position: '',
    tracksuit_size: '',
    practice_jersey_size: '',
    shorts_size: '',
    socks_size: '',
    waiver_agreement: false
  });

  // UI state
  const [playerType, setPlayerType] = useState('full_season');
  const [paymentFrequency, setPaymentFrequency] = useState('monthly'); // 'monthly' or 'full'
  const [namePersonalization, setNamePersonalization] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  // Stripe state
  const [stripe, setStripe] = useState(null);
  const [elements, setElements] = useState(null);

  // Pricing constants
  const FULL_SEASON_BASE = 85;
  const GUEST_BASE = 42;
  const PERSONALIZATION_UPSELL = 13;
  const SEASON_MONTHS = 6;

  // Get config from WordPress
  const stripeKey = window.newteamSeasonConfig?.stripePublishableKey || '';
  const ajaxUrl = window.newteamSeasonConfig?.ajaxUrl || '/wp-admin/admin-ajax.php';
  const nonce = window.newteamSeasonConfig?.seasonNonce || '';
  const siteUrl = window.newteamSeasonConfig?.siteUrl || '';
  const themeUri = window.newteamSeasonConfig?.themeUri || '';

  // Calculate current price
  const basePrice = playerType === 'full_season' ? FULL_SEASON_BASE : GUEST_BASE;
  const isPayingInFull = paymentFrequency === 'full';

  // Pay in full = free name personalization, monthly = optional upsell
  const monthlyPrice = isPayingInFull
    ? basePrice  // No extra charge for names when paying in full
    : basePrice + (namePersonalization ? PERSONALIZATION_UPSELL : 0);

  const totalPrice = isPayingInFull
    ? basePrice * SEASON_MONTHS  // Full payment (names included free)
    : monthlyPrice * SEASON_MONTHS;  // Monthly total

  // For Stripe - amount to charge now
  const chargeAmount = isPayingInFull ? totalPrice : monthlyPrice;

  // Size options
  const sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
  const sockSizes = [
    { value: 'S', label: 'S (US 5-7)' },
    { value: 'M', label: 'M (US 7-9)' },
    { value: 'L', label: 'L (US 9-12)' },
    { value: 'XL', label: 'XL (US 12+)' }
  ];

  // Initialize Stripe
  useEffect(() => {
    if (!stripeKey || !window.Stripe) return;

    const stripeInstance = window.Stripe(stripeKey);
    setStripe(stripeInstance);

    const appearance = {
      theme: 'night',
      variables: {
        colorPrimary: '#dc2626',
        colorBackground: '#374151',
        colorText: '#ffffff',
        colorDanger: '#ef4444',
        fontFamily: 'system-ui, sans-serif',
        borderRadius: '8px'
      }
    };

    const elementsInstance = stripeInstance.elements({
      mode: 'payment',
      amount: chargeAmount * 100,
      currency: 'usd',
      appearance,
      paymentMethodTypes: ['card'],
      paymentMethodCreation: 'manual'
    });

    setElements(elementsInstance);

    const paymentElement = elementsInstance.create('payment', {
      paymentMethodTypes: ['card']
    });

    setTimeout(() => {
      const container = document.getElementById('payment-element');
      if (container) {
        paymentElement.mount('#payment-element');
      }
    }, 100);

    return () => paymentElement?.unmount();
  }, [stripeKey]);

  // Update Stripe Elements when price changes
  useEffect(() => {
    if (elements) {
      elements.update({ amount: chargeAmount * 100 });
    }
  }, [chargeAmount, elements]);

  const handleInputChange = (e) => {
    const { name, value, type, checked } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: type === 'checkbox' ? checked : value
    }));
  };

  const validateForm = () => {
    if (!formData.first_name || !formData.last_name) {
      return 'Please enter your full name';
    }
    if (!formData.email || !formData.email.includes('@')) {
      return 'Please enter a valid email address';
    }
    if (!formData.age || formData.age < 18 || formData.age > 45) {
      return 'Age must be between 18 and 45';
    }
    if (!formData.position) {
      return 'Please enter your position(s)';
    }
    if (!formData.tracksuit_size || !formData.practice_jersey_size || !formData.shorts_size || !formData.socks_size) {
      return 'Please select all equipment sizes';
    }
    if (!formData.waiver_agreement) {
      return 'Please read and agree to the Season Commitment Agreement';
    }
    return null;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');

    const validationError = validateForm();
    if (validationError) {
      setError(validationError);
      return;
    }

    if (!stripe || !elements) {
      setError('Payment system not initialized. Please refresh the page.');
      return;
    }

    setIsProcessing(true);

    try {
      // Validate card
      const { error: submitError } = await elements.submit();
      if (submitError) {
        throw new Error(submitError.message);
      }

      // Create payment method
      const { error: pmError, paymentMethod } = await stripe.createPaymentMethod({
        elements,
        params: {
          billing_details: {
            name: `${formData.first_name} ${formData.last_name}`,
            email: formData.email
          }
        }
      });

      if (pmError) {
        throw new Error(pmError.message);
      }

      // Determine if paying in full or subscription
      const paymentAction = isPayingInFull ? 'create_season_full_payment' : 'create_season_subscription';

      // Create payment/subscription
      const paymentResponse = await fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: paymentAction,
          payment_method_id: paymentMethod.id,
          email: formData.email,
          first_name: formData.first_name,
          last_name: formData.last_name,
          player_type: playerType,
          monthly_amount: monthlyPrice,
          total_amount: totalPrice,
          payment_frequency: paymentFrequency,
          name_personalization: isPayingInFull ? '1' : (namePersonalization ? '1' : '0'),
          season_nonce: nonce
        })
      });

      const paymentResult = await paymentResponse.json();

      if (!paymentResult.success) {
        if (paymentResult.requires_action) {
          const { error: authError } = await stripe.confirmCardPayment(
            paymentResult.payment_intent_client_secret,
            { return_url: window.location.href }
          );
          if (authError) {
            throw new Error(authError.message);
          }
        } else {
          throw new Error(paymentResult.message || 'Payment processing failed');
        }
      }

      // Save registration
      const registrationResponse = await fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'process_season_registration',
          ...formData,
          player_type: playerType,
          payment_amount: isPayingInFull ? totalPrice : monthlyPrice,
          payment_frequency: paymentFrequency,
          name_personalization: isPayingInFull ? '1' : (namePersonalization ? '1' : '0'),
          subscription_id: paymentResult.subscription_id || '',
          payment_intent_id: paymentResult.payment_intent_id || '',
          customer_id: paymentResult.customer_id,
          season_nonce: nonce
        })
      });

      const registrationResult = await registrationResponse.json();

      if (registrationResult.success) {
        setSuccess(true);
      } else {
        throw new Error(registrationResult.message || 'Registration failed');
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setIsProcessing(false);
    }
  };

  // Success message
  if (success) {
    const isFullSeason = playerType === 'full_season';
    const paidInFull = paymentFrequency === 'full';
    return (
      <div style={styles.container}>
        <div style={styles.successBox}>
          <h2 style={styles.successTitle}>
            {isFullSeason ? '🎉 Welcome to the Spring 2026 Season!' : '🎉 Welcome, Guest Player!'}
          </h2>
          <p style={styles.successSubtitle}>
            {paidInFull
              ? `Your payment of $${totalPrice} has been processed! You're all set for the season.`
              : `Your subscription has been activated! You'll be charged $${monthlyPrice}/month for 6 months.`
            }
          </p>
          <p style={styles.successDetails}>
            {isFullSeason
              ? 'You now have a guaranteed roster spot and will receive your equipment before the season starts.'
              : 'You\'ll be assigned 3-5 games based on roster availability. Your equipment will be delivered before the season starts.'}
          </p>
          {paidInFull && (
            <p style={{ color: '#4caf50', fontWeight: 'bold', marginBottom: '15px' }}>
              ✓ Name personalization included on your tracksuit, practice jersey & backpack!
            </p>
          )}
          <p style={styles.successCta}>See you on the pitch!</p>
          <a href={siteUrl || '/'} style={styles.homeButton}>Back to Home</a>
        </div>
      </div>
    );
  }

  // Dynamic hero style with background image
  const heroStyle = {
    ...styles.hero,
    padding: isMobile ? '40px 15px' : '80px 20px',
    background: `linear-gradient(rgba(10, 15, 26, 0.85), rgba(31, 41, 55, 0.9)), url("${themeUri}/images/team-photo.jpg") center/cover no-repeat`,
    backgroundAttachment: isMobile ? 'scroll' : 'fixed' // Fixed attachment causes issues on mobile
  };

  return (
    <div style={styles.pageWrapper}>
      {/* Hero Section */}
      <section style={heroStyle}>
        <div style={styles.heroContent}>
          <h1 style={{ ...styles.heroTitle, fontSize: isMobile ? '2rem' : '3.5rem' }}>
            <span style={styles.gradientText}>Spring 2026 Season Registration</span>
          </h1>
          <h2 style={styles.heroSubtitle}>
            Join Newteam FC for the Spring 2026 Season
          </h2>
          <p style={styles.heroDescription}>
            Registration covers field fees, equipment, home and away jerseys, referee fees, and league registration.
          </p>

          {/* Pricing Cards */}
          <div style={styles.pricingCards}>
            {/* Full Season Card */}
            <div style={styles.pricingCard}>
              <h3 style={styles.cardTitle}>Full Season Player</h3>
              <div style={styles.cardPrice}>$85<span style={styles.cardPriceUnit}>/month</span></div>
              <div style={styles.cardSubtext}>6-month commitment</div>
              <ul style={styles.cardList}>
                <li>✓ Guaranteed roster spot</li>
                <li>✓ All league matches & practices</li>
                <li>✓ VEO game footage & highlights</li>
                <li style={styles.cardListHeader}>Equipment Package:</li>
                <li style={styles.cardListIndent}>• Home & away game jerseys</li>
                <li style={styles.cardListIndent}>• Practice jersey</li>
                <li style={styles.cardListIndent}>• Team tracksuit</li>
                <li style={styles.cardListIndent}>• Game shorts</li>
                <li style={styles.cardListIndent}>• Team socks</li>
                <li style={styles.cardListIndent}>• Team backpack</li>
              </ul>
            </div>

            {/* Guest Player Card */}
            <div style={{ ...styles.pricingCard, ...styles.guestCard }}>
              <h3 style={styles.cardTitle}>Guest Player</h3>
              <div style={styles.cardPrice}>$42<span style={styles.cardPriceUnit}>/month</span></div>
              <div style={styles.cardSubtext}>6-month commitment</div>
              <ul style={styles.cardList}>
                <li><strong style={{ color: '#f59e0b' }}>✓ 3-5 games per season</strong></li>
                <li>✓ Games assigned based on roster needs</li>
                <li>✓ VEO footage for your games</li>
                <li style={styles.cardListHeader}>Equipment Package:</li>
                <li style={styles.cardListIndent}>• Home & away game jerseys</li>
                <li style={styles.cardListIndent}>• Practice jersey</li>
                <li style={styles.cardListIndent}>• Team tracksuit</li>
                <li style={styles.cardListIndent}>• Game shorts</li>
                <li style={styles.cardListIndent}>• Team socks</li>
                <li style={styles.cardListIndent}>• Team backpack</li>
              </ul>
            </div>
          </div>

        </div>

      </section>

      {/* Registration Form */}
      <section style={styles.formSection}>
        <div style={styles.formContainer}>
          <div style={styles.formHeader}>
            <h2 style={styles.formTitle}>
              Secure Your <span style={styles.gradientText}>Spring 2026 Season Spot</span>
            </h2>
            <p style={styles.formSubtitle}>
              Join our competitive soccer team for the Spring 2026 season.
            </p>
          </div>

          <div style={styles.formBox}>
            {/* Player Type Selection */}
            <div style={styles.playerTypeSection}>
              <h3 style={styles.sectionTitle}>Choose Your Registration Type</h3>
              <div style={styles.playerTypeOptions}>
                <div
                  style={{
                    ...styles.playerTypeOption,
                    ...(playerType === 'full_season' ? styles.playerTypeSelected : {})
                  }}
                  onClick={() => setPlayerType('full_season')}
                >
                  <div style={styles.optionTitle}>Full Season Player</div>
                  <div style={styles.optionPrice}>$85<span style={styles.optionPriceUnit}>/mo</span></div>
                  <div style={styles.optionDescription}>6 months • Equipment included</div>
                </div>
                <div
                  style={{
                    ...styles.playerTypeOption,
                    ...(playerType === 'guest' ? styles.playerTypeSelected : {})
                  }}
                  onClick={() => setPlayerType('guest')}
                >
                  <div style={styles.optionTitle}>Guest Player</div>
                  <div style={styles.optionPrice}>$42<span style={styles.optionPriceUnit}>/mo</span></div>
                  <div style={styles.optionDescription}>6 months • 3-5 games • Equipment</div>
                </div>
              </div>

              {/* Tracksuit Showcase - Maximum Deprivation Point */}
              <div style={styles.tracksuitShowcase}>
                <div style={styles.tracksuitImageContainer}>
                  <img
                    src={`${themeUri}/images/tracksuit-clean.png`}
                    alt="Newteam FC Official Tracksuit"
                    style={styles.tracksuitImage}
                  />
                </div>
                <div style={styles.tracksuitTeaser}>
                  <p style={styles.teaserText}>
                    Want <span style={styles.teaserHighlight}>YOUR NAME</span> on this?
                  </p>
                </div>
              </div>

              {/* Payment Frequency Selection */}
              <h3 style={{ ...styles.sectionTitle, marginTop: '1.5rem' }}>Payment Option</h3>
              <div style={styles.playerTypeOptions}>
                <div
                  style={{
                    ...styles.playerTypeOption,
                    ...(paymentFrequency === 'monthly' ? styles.playerTypeSelected : {})
                  }}
                  onClick={() => setPaymentFrequency('monthly')}
                >
                  <div style={styles.optionTitle}>Monthly Payments</div>
                  <div style={styles.optionPrice}>${basePrice}<span style={styles.optionPriceUnit}>/mo</span></div>
                  <div style={styles.optionDescription}>6 monthly payments</div>
                </div>
                <div
                  style={{
                    ...styles.playerTypeOption,
                    ...(paymentFrequency === 'full' ? styles.playerTypeSelected : {}),
                    border: paymentFrequency === 'full' ? '2px solid #4caf50' : '2px solid transparent',
                    background: paymentFrequency === 'full' ? 'rgba(76, 175, 80, 0.1)' : '#374151'
                  }}
                  onClick={() => setPaymentFrequency('full')}
                >
                  <div style={styles.optionTitle}>Pay in Full</div>
                  <div style={styles.optionPrice}>${basePrice * SEASON_MONTHS}<span style={styles.optionPriceUnit}> total</span></div>
                  <div style={{ ...styles.optionDescription, color: '#4caf50', fontWeight: 'bold' }}>
                    ⭐ FREE name personalization!
                  </div>
                </div>
              </div>

              <div style={styles.totalDisplay}>
                {isPayingInFull ? (
                  <>
                    <div style={styles.totalLabel}>One-Time Payment:</div>
                    <div style={styles.totalAmount}>${totalPrice}</div>
                    <div style={{ ...styles.recurringNote, color: '#4caf50' }}>
                      ✓ Name personalization included FREE ($78 value) •{' '}
                      {playerType === 'full_season' ? 'All games' : '3-5 games'} + equipment
                    </div>
                  </>
                ) : (
                  <>
                    <div style={styles.totalLabel}>First Month Payment:</div>
                    <div style={styles.totalAmount}>${monthlyPrice}</div>
                    <div style={styles.recurringNote}>
                      Then ${monthlyPrice}/month for 5 more months •{' '}
                      {playerType === 'full_season' ? 'All games' : '3-5 games'} + equipment
                    </div>
                  </>
                )}
              </div>
            </div>

            <form onSubmit={handleSubmit}>
              {/* Personal Info */}
              <div style={{ ...styles.formRow, gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr' }}>
                <div style={styles.formGroup}>
                  <label style={styles.label}>First Name *</label>
                  <input
                    type="text"
                    name="first_name"
                    value={formData.first_name}
                    onChange={handleInputChange}
                    style={styles.input}
                    required
                  />
                </div>
                <div style={styles.formGroup}>
                  <label style={styles.label}>Last Name *</label>
                  <input
                    type="text"
                    name="last_name"
                    value={formData.last_name}
                    onChange={handleInputChange}
                    style={styles.input}
                    required
                  />
                </div>
              </div>

              <div style={{ ...styles.formRow, gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr' }}>
                <div style={styles.formGroup}>
                  <label style={styles.label}>Email Address *</label>
                  <input
                    type="email"
                    name="email"
                    value={formData.email}
                    onChange={handleInputChange}
                    style={styles.input}
                    placeholder="For payment receipts & updates"
                    required
                  />
                </div>
                <div style={styles.formGroup}>
                  <label style={styles.label}>Age *</label>
                  <input
                    type="number"
                    name="age"
                    value={formData.age}
                    onChange={handleInputChange}
                    style={styles.input}
                    min="18"
                    max="45"
                    required
                  />
                </div>
              </div>

              <div style={styles.formGroup}>
                <label style={styles.label}>Position(s) *</label>
                <input
                  type="text"
                  name="position"
                  value={formData.position}
                  onChange={handleInputChange}
                  style={styles.input}
                  placeholder="List all positions you play (e.g., Midfielder, Defender)"
                  required
                />
              </div>

              {/* Equipment Sizes */}
              <div style={styles.equipmentSection}>
                <h4 style={styles.sectionTitle}>Equipment Sizes *</h4>
                <p style={styles.sectionNote}>Select your sizes for the team equipment package (jerseys & backpack are one-size-fits-all)</p>

                <div style={{ ...styles.formRow, gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr' }}>
                  <div style={styles.formGroup}>
                    <label style={styles.label}>Tracksuit Size *</label>
                    <select name="tracksuit_size" value={formData.tracksuit_size} onChange={handleInputChange} style={styles.select} required>
                      <option value="">Select Size</option>
                      {sizeOptions.map(size => <option key={size} value={size}>{size}</option>)}
                    </select>
                  </div>
                  <div style={styles.formGroup}>
                    <label style={styles.label}>Practice Jersey Size *</label>
                    <select name="practice_jersey_size" value={formData.practice_jersey_size} onChange={handleInputChange} style={styles.select} required>
                      <option value="">Select Size</option>
                      {sizeOptions.map(size => <option key={size} value={size}>{size}</option>)}
                    </select>
                  </div>
                </div>

                <div style={{ ...styles.formRow, gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr' }}>
                  <div style={styles.formGroup}>
                    <label style={styles.label}>Shorts Size *</label>
                    <select name="shorts_size" value={formData.shorts_size} onChange={handleInputChange} style={styles.select} required>
                      <option value="">Select Size</option>
                      {sizeOptions.map(size => <option key={size} value={size}>{size}</option>)}
                    </select>
                  </div>
                  <div style={styles.formGroup}>
                    <label style={styles.label}>Socks Size *</label>
                    <select name="socks_size" value={formData.socks_size} onChange={handleInputChange} style={styles.select} required>
                      <option value="">Select Size</option>
                      {sockSizes.map(size => <option key={size.value} value={size.value}>{size.label}</option>)}
                    </select>
                  </div>
                </div>

              </div>

              {/* Personalization Upsell - only show for monthly payments */}
              {!isPayingInFull && (
                <div style={styles.upsellSection}>
                  <div style={styles.upsellContent}>
                    <div style={styles.upsellText}>
                      <h4 style={styles.upsellTitle}>⭐ Add Your Name</h4>
                      <p style={styles.upsellDescription}>
                        Get your name printed on your tracksuit, practice jersey & backpack
                      </p>
                    </div>
                    <div style={styles.upsellAction}>
                      <div style={styles.upsellPrice}>+$13<span style={styles.upsellPriceUnit}>/mo</span></div>
                      <label style={styles.upsellCheckboxLabel}>
                        <input
                          type="checkbox"
                          checked={namePersonalization}
                          onChange={(e) => setNamePersonalization(e.target.checked)}
                          style={styles.upsellCheckbox}
                        />
                        <span style={styles.upsellCheckboxText}>Yes, add my name!</span>
                      </label>
                    </div>
                  </div>
                </div>
              )}

              {/* Show confirmation when paying in full */}
              {isPayingInFull && (
                <div style={{ ...styles.upsellSection, borderColor: '#4caf50', background: 'rgba(76, 175, 80, 0.1)' }}>
                  <div style={{ textAlign: 'center' }}>
                    <h4 style={{ ...styles.upsellTitle, color: '#4caf50' }}>✓ Name Personalization Included!</h4>
                    <p style={styles.upsellDescription}>
                      Your name will be printed on your tracksuit, practice jersey & backpack at no extra charge.
                    </p>
                  </div>
                </div>
              )}

              {/* Payment */}
              <div style={styles.paymentSection}>
                <h4 style={styles.sectionTitle}>Payment Details</h4>
                <p style={styles.sectionNote}>Secure your spot with instant payment processing.</p>
                <div id="payment-element" style={styles.paymentElement}></div>
                {error && <div style={styles.errorMessage}>{error}</div>}
              </div>

              {/* Waiver - IMPORTANT: Make this prominent so players read it */}
              <div style={styles.waiverSection}>
                <h3 style={styles.waiverTitle}>SEASON COMMITMENT AGREEMENT</h3>
                <p style={styles.waiverIntro}>Please read carefully before registering:</p>
                <div style={styles.waiverText}>
                  <p style={styles.waiverParagraph}>
                    <strong style={styles.waiverStrong}>Season Commitment:</strong> I understand that I am committing to participate in the entire Spring 2026 season and will make every effort to be available for scheduled games and practices. I recognize that consistent attendance is essential for team success.
                  </p>
                  <p style={styles.waiverParagraph}>
                    <strong style={styles.waiverStrong}>Communication:</strong> If I am unable to attend any team activities, I will provide advance notice to the coaching staff and team management whenever possible, allowing for proper game planning.
                  </p>
                  <p style={styles.waiverParagraph}>
                    <strong style={styles.waiverStrong}>Equipment Care:</strong> I agree to maintain all team-issued equipment in excellent condition and uphold the professional appearance expected of Newteam FC at all times.
                  </p>
                  <p style={styles.waiverParagraphHighlight}>
                    <strong style={styles.waiverStrong}>Professional Conduct:</strong> I commit to conducting myself in a professional and respectful manner both on and off the field. This includes <span style={{ color: '#f59e0b', fontWeight: 'bold' }}>NOT arguing with referees</span>, accepting referee decisions gracefully, and addressing any concerns through proper channels with team management after the match. Repeated violations may result in benching or removal from the team.
                  </p>
                  <p style={styles.waiverParagraph}>
                    <strong style={styles.waiverStrong}>Team Unity:</strong> I understand that success depends on teamwork, respect, and dedication. I commit to supporting my teammates and contributing positively to the team environment throughout the season.
                  </p>
                </div>
                <label style={styles.waiverCheckboxLabel}>
                  <input
                    type="checkbox"
                    name="waiver_agreement"
                    checked={formData.waiver_agreement}
                    onChange={handleInputChange}
                    style={styles.waiverCheckbox}
                    required
                  />
                  <span style={styles.waiverCheckboxText}>
                    I have read, understood, and agree to the Season Commitment Agreement above. I acknowledge my responsibilities as a member of Newteam FC for the Spring 2026 season. <span style={{ color: '#dc2626' }}>*</span>
                  </span>
                </label>
              </div>

              {/* Submit */}
              <button type="submit" disabled={isProcessing} style={styles.submitButton}>
                {isProcessing
                  ? 'Processing...'
                  : isPayingInFull
                    ? `PAY IN FULL - $${totalPrice}`
                    : `START SUBSCRIPTION - $${monthlyPrice}/mo`
                }
              </button>
              <p style={styles.securityNote}>🔒 Secure payment powered by Stripe • Instant confirmation</p>
            </form>
          </div>
        </div>
      </section>
    </div>
  );
};

// Styles
const styles = {
  pageWrapper: {
    backgroundColor: '#0a0f1a',
    color: '#ffffff',
    fontFamily: 'system-ui, -apple-system, sans-serif',
    minHeight: '100vh'
  },
  container: {
    maxWidth: '600px',
    margin: '0 auto',
    padding: '4rem 1rem'
  },
  hero: {
    position: 'relative',
    padding: '80px 20px',
    textAlign: 'center',
    minHeight: '70vh',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden'
  },
  heroContent: {
    maxWidth: '800px',
    margin: '0 auto',
    position: 'relative',
    zIndex: 1
  },
  heroTitle: {
    fontWeight: 'bold',
    marginBottom: '1.5rem',
    lineHeight: 1.1
  },
  gradientText: {
    background: 'linear-gradient(135deg, #dc2626, #f59e0b)',
    WebkitBackgroundClip: 'text',
    WebkitTextFillColor: 'transparent',
    backgroundClip: 'text'
  },
  heroSubtitle: {
    fontSize: '2rem',
    fontWeight: 'bold',
    marginBottom: '1rem'
  },
  heroDescription: {
    fontSize: '1.25rem',
    marginBottom: '2rem',
    color: '#e0e0e0',
    lineHeight: 1.6
  },
  pricingCards: {
    display: 'flex',
    gap: '1.5rem',
    justifyContent: 'center',
    flexWrap: 'wrap',
    marginBottom: '2rem'
  },
  pricingCard: {
    background: 'rgba(220, 38, 38, 0.1)',
    border: '2px solid #dc2626',
    borderRadius: '15px',
    padding: '1.5rem',
    minWidth: '280px',
    maxWidth: '350px',
    width: '100%',
    textAlign: 'center'
  },
  guestCard: {
    background: 'rgba(245, 158, 11, 0.1)',
    borderColor: '#f59e0b'
  },
  cardTitle: {
    color: '#f59e0b',
    fontSize: '1.5rem',
    marginBottom: '0.5rem'
  },
  cardPrice: {
    fontSize: '3rem',
    fontWeight: 900,
    color: 'white'
  },
  cardPriceUnit: {
    fontSize: '1rem',
    color: '#a0a0a0'
  },
  cardSubtext: {
    color: '#a0a0a0',
    fontSize: '0.9rem',
    marginBottom: '1rem'
  },
  cardList: {
    textAlign: 'left',
    color: '#e0e0e0',
    fontSize: '0.9rem',
    listStyle: 'none',
    padding: 0,
    margin: 0
  },
  cardListHeader: {
    color: '#f59e0b',
    fontWeight: 600,
    marginTop: '0.75rem',
    marginBottom: '0.25rem',
    fontSize: '0.85rem'
  },
  cardListIndent: {
    paddingLeft: '0.75rem',
    fontSize: '0.85rem',
    color: '#d0d0d0'
  },
  formSection: {
    padding: '4rem 0',
    background: '#1a1a2e'
  },
  formContainer: {
    maxWidth: '1000px',
    margin: '0 auto',
    padding: '0 1rem'
  },
  formHeader: {
    textAlign: 'center',
    marginBottom: '3rem'
  },
  formTitle: {
    fontSize: '2.5rem',
    fontWeight: 'bold',
    marginBottom: '1rem'
  },
  formSubtitle: {
    fontSize: '1.25rem',
    color: '#e0e0e0'
  },
  formBox: {
    background: '#1a1a2e',
    borderRadius: '15px',
    padding: '2rem',
    maxWidth: '700px',
    margin: '0 auto'
  },
  playerTypeSection: {
    marginBottom: '2rem'
  },
  sectionTitle: {
    color: '#f59e0b',
    marginBottom: '1rem',
    textAlign: 'center'
  },
  playerTypeOptions: {
    display: 'flex',
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: '1rem',
    justifyContent: 'center',
    marginBottom: '1rem'
  },
  playerTypeOption: {
    background: '#374151',
    border: '2px solid transparent',
    borderRadius: '15px',
    padding: '1.25rem',
    cursor: 'pointer',
    transition: 'all 0.3s ease',
    textAlign: 'center',
    minWidth: '150px',
    flex: '1 1 150px',
    maxWidth: '250px'
  },
  playerTypeSelected: {
    borderColor: '#dc2626',
    background: 'rgba(220, 38, 38, 0.1)'
  },
  optionTitle: {
    fontSize: '1.25rem',
    fontWeight: 'bold',
    color: '#f59e0b',
    marginBottom: '0.5rem'
  },
  optionPrice: {
    fontSize: '2rem',
    fontWeight: 900,
    color: '#ffffff',
    marginBottom: '0.5rem'
  },
  optionPriceUnit: {
    fontSize: '0.6em',
    color: '#a0a0a0'
  },
  optionDescription: {
    fontSize: '0.875rem',
    color: '#a0a0a0'
  },
  totalDisplay: {
    background: '#1a1a2e',
    borderRadius: '10px',
    padding: '1rem',
    textAlign: 'center',
    border: '2px solid #f59e0b'
  },
  totalLabel: {
    color: '#f59e0b',
    marginBottom: '0.5rem'
  },
  totalAmount: {
    fontSize: '2rem',
    fontWeight: 'bold',
    color: '#dc2626'
  },
  recurringNote: {
    color: '#a0a0a0',
    fontSize: '0.85rem',
    marginTop: '0.5rem'
  },
  formRow: {
    display: 'grid',
    gap: '1rem',
    marginBottom: '1rem'
  },
  formRowTriple: {
    display: 'grid',
    gap: '1rem',
    marginBottom: '1rem'
  },
  formGroup: {
    display: 'flex',
    flexDirection: 'column'
  },
  label: {
    marginBottom: '0.5rem',
    fontWeight: 600,
    color: '#f59e0b'
  },
  input: {
    padding: '0.75rem',
    borderRadius: '8px',
    border: '1px solid #374151',
    background: '#374151',
    color: 'white',
    fontSize: '1rem'
  },
  select: {
    padding: '0.75rem',
    borderRadius: '8px',
    border: '1px solid #374151',
    background: '#374151',
    color: 'white',
    fontSize: '1rem'
  },
  equipmentSection: {
    margin: '1.5rem 0',
    padding: '1.5rem',
    background: 'rgba(220, 38, 38, 0.05)',
    border: '1px solid #dc2626',
    borderRadius: '10px'
  },
  tracksuitShowcase: {
    marginTop: '2rem',
    textAlign: 'center',
    padding: '1.5rem',
    background: 'linear-gradient(135deg, rgba(220, 38, 38, 0.1), rgba(245, 158, 11, 0.1))',
    borderRadius: '15px',
    border: '2px solid rgba(245, 158, 11, 0.3)'
  },
  tracksuitImageContainer: {
    position: 'relative',
    display: 'inline-block'
  },
  tracksuitImage: {
    maxWidth: '280px',
    width: '100%',
    height: 'auto',
    filter: 'drop-shadow(0 15px 35px rgba(220, 38, 38, 0.4)) drop-shadow(0 8px 15px rgba(0, 0, 0, 0.5))',
    transition: 'transform 0.3s ease'
  },
  tracksuitTeaser: {
    marginTop: '1rem'
  },
  teaserText: {
    fontSize: '1.3rem',
    color: '#ffffff',
    margin: 0,
    fontWeight: 500
  },
  teaserHighlight: {
    color: '#4caf50',
    fontWeight: 700,
    textShadow: '0 0 10px rgba(76, 175, 80, 0.5)'
  },
  sectionNote: {
    color: '#a0a0a0',
    fontSize: '0.85rem',
    marginBottom: '1rem'
  },
  upsellSection: {
    margin: '1.5rem 0',
    padding: '1.5rem',
    background: 'linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(220, 38, 38, 0.1))',
    border: '2px solid #f59e0b',
    borderRadius: '10px'
  },
  upsellContent: {
    display: 'flex',
    alignItems: 'center',
    gap: '1rem',
    flexWrap: 'wrap'
  },
  upsellText: {
    flex: 1,
    minWidth: '200px'
  },
  upsellTitle: {
    color: '#f59e0b',
    margin: '0 0 0.5rem 0',
    fontSize: '1.2rem'
  },
  upsellDescription: {
    color: '#e0e0e0',
    margin: 0,
    fontSize: '0.9rem'
  },
  upsellAction: {
    textAlign: 'center'
  },
  upsellPrice: {
    fontSize: '1.5rem',
    fontWeight: 900,
    color: 'white'
  },
  upsellPriceUnit: {
    fontSize: '0.8rem',
    color: '#a0a0a0'
  },
  upsellCheckboxLabel: {
    display: 'flex',
    alignItems: 'center',
    gap: '0.5rem',
    cursor: 'pointer',
    marginTop: '0.5rem'
  },
  upsellCheckbox: {
    width: '20px',
    height: '20px',
    accentColor: '#f59e0b'
  },
  upsellCheckboxText: {
    color: '#f59e0b',
    fontWeight: 600
  },
  jerseySection: {
    margin: '1.5rem 0'
  },
  paymentSection: {
    margin: '2rem 0'
  },
  paymentElement: {
    margin: '1rem 0'
  },
  errorMessage: {
    color: '#f44336',
    marginTop: '1rem',
    fontSize: '0.875rem'
  },
  waiverSection: {
    margin: '2rem 0',
    padding: '2rem',
    background: 'rgba(220, 38, 38, 0.1)',
    border: '3px solid #dc2626',
    borderRadius: '15px'
  },
  waiverTitle: {
    color: '#dc2626',
    fontSize: '1.5rem',
    fontWeight: 900,
    textAlign: 'center',
    marginBottom: '0.5rem',
    textTransform: 'uppercase',
    letterSpacing: '2px'
  },
  waiverIntro: {
    color: '#f59e0b',
    fontSize: '1.1rem',
    textAlign: 'center',
    marginBottom: '1.5rem',
    fontWeight: 600
  },
  waiverText: {
    padding: '1.5rem',
    background: '#1f2937',
    borderRadius: '10px',
    marginBottom: '1.5rem',
    fontSize: '1rem',
    lineHeight: 1.8
  },
  waiverParagraph: {
    marginBottom: '1.25rem',
    color: '#e0e0e0'
  },
  waiverParagraphHighlight: {
    marginBottom: '1.25rem',
    color: '#ffffff',
    padding: '1rem',
    background: 'rgba(220, 38, 38, 0.2)',
    borderRadius: '8px',
    border: '1px solid #dc2626'
  },
  waiverStrong: {
    color: '#f59e0b',
    fontSize: '1.05rem'
  },
  waiverCheckboxLabel: {
    display: 'flex',
    alignItems: 'flex-start',
    gap: '1rem',
    color: '#ffffff',
    fontSize: '1rem',
    lineHeight: 1.5,
    cursor: 'pointer',
    padding: '1rem',
    background: '#374151',
    borderRadius: '8px'
  },
  waiverCheckbox: {
    marginTop: '0.25rem',
    width: '24px',
    height: '24px',
    accentColor: '#f59e0b',
    flexShrink: 0
  },
  waiverCheckboxText: {
    color: '#e0e0e0'
  },
  submitButton: {
    width: '100%',
    background: 'linear-gradient(135deg, #dc2626, #991b1b)',
    color: 'white',
    fontWeight: 900,
    padding: '1rem 2rem',
    border: 'none',
    borderRadius: '10px',
    fontSize: '1.25rem',
    cursor: 'pointer',
    textTransform: 'uppercase',
    letterSpacing: '1px'
  },
  securityNote: {
    color: '#a0a0a0',
    marginTop: '1rem',
    fontSize: '0.875rem',
    textAlign: 'center'
  },
  successBox: {
    textAlign: 'center',
    padding: '40px',
    background: 'rgba(76, 175, 80, 0.1)',
    border: '2px solid #4caf50',
    borderRadius: '15px'
  },
  successTitle: {
    color: '#4caf50',
    marginBottom: '20px'
  },
  successSubtitle: {
    fontSize: '18px',
    marginBottom: '15px'
  },
  successDetails: {
    color: '#e0e0e0',
    marginBottom: '15px'
  },
  successCta: {
    color: '#4caf50',
    fontWeight: 'bold'
  },
  homeButton: {
    display: 'inline-block',
    marginTop: '30px',
    background: 'linear-gradient(135deg, #dc2626 0%, #991b1b 100%)',
    color: 'white',
    padding: '12px 30px',
    textDecoration: 'none',
    borderRadius: '25px',
    fontWeight: 600
  }
};

export default SeasonRegistrationPage;
