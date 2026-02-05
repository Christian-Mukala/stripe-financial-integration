import React, { useState, useEffect } from 'react';

const TryoutPage = () => {
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    date_of_birth: '',
    position: '',
    experience: '',
    tryout_date: ''
  });

  const [isProcessing, setIsProcessing] = useState(false);
  const [errors, setErrors] = useState([]);
  const [success, setSuccess] = useState(false);
  const [daysRemaining, setDaysRemaining] = useState(0);

  // Stripe will be initialized in useEffect
  const [stripe, setStripe] = useState(null);
  const [elements, setElements] = useState(null);
  const [paymentElement, setPaymentElement] = useState(null);

  // Get Stripe key from WordPress
  const stripeKey = window.newteamConfig?.stripePublishableKey || '';

  // Calculate days remaining
  useEffect(() => {
    const deadline = new Date('2025-08-09');
    const now = new Date();
    const diff = deadline.getTime() - now.getTime();
    const days = Math.ceil(diff / (1000 * 60 * 60 * 24));
    setDaysRemaining(days > 0 ? days : 0);
  }, []);

  // Initialize Stripe
  useEffect(() => {
    if (!stripeKey || !window.Stripe) return;

    const stripeInstance = window.Stripe(stripeKey);
    setStripe(stripeInstance);

    // Initialize elements with fixed amount
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
      amount: 1500, // $15 in cents
      currency: 'usd',
      appearance: appearance,
      paymentMethodTypes: ['card']
    });

    setElements(elementsInstance);

    // Create and mount payment element
    const paymentElementInstance = elementsInstance.create('payment', {
      paymentMethodTypes: ['card']
    });

    // Wait for component to mount
    setTimeout(() => {
      const paymentElementDiv = document.getElementById('payment-element');
      if (paymentElementDiv) {
        paymentElementInstance.mount('#payment-element');
        setPaymentElement(paymentElementInstance);
      }
    }, 100);

    // Cleanup
    return () => {
      if (paymentElementInstance) {
        paymentElementInstance.unmount();
      }
    };
  }, [stripeKey]);

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
  };

  const validateForm = () => {
    const newErrors = [];

    if (!formData.first_name) newErrors.push('First name is required');
    if (!formData.last_name) newErrors.push('Last name is required');
    if (!formData.email) newErrors.push('Valid email is required');
    if (!formData.phone) newErrors.push('Phone number is required');
    if (!formData.date_of_birth) newErrors.push('Date of birth is required');
    if (!formData.position) newErrors.push('Position is required');
    if (!formData.experience) newErrors.push('Experience level is required');
    if (!formData.tryout_date) newErrors.push('Tryout date selection is required');

    // Age validation
    if (formData.date_of_birth) {
      const birthDate = new Date(formData.date_of_birth);
      const today = new Date();
      const age = today.getFullYear() - birthDate.getFullYear();
      const monthDiff = today.getMonth() - birthDate.getMonth();

      if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
        age--;
      }

      if (age < 18) {
        newErrors.push('Players must be 18+ years old for tryouts');
      }
    }

    setErrors(newErrors);
    return newErrors.length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) return;

    if (!stripe || !elements) {
      setErrors(['Payment system not initialized. Please refresh the page.']);
      return;
    }

    setIsProcessing(true);
    setErrors([]);

    try {
      // Submit payment element
      const { error: submitError } = await elements.submit();
      if (submitError) {
        setErrors([submitError.message]);
        setIsProcessing(false);
        return;
      }

      // Create payment intent via AJAX
      const intentResponse = await fetch(window.newteamConfig.ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'create_payment_intent_simple',
          amount: 1500, // $15 in cents
          email: formData.email,
          name: `${formData.first_name} ${formData.last_name}`
        })
      });

      const intentData = await intentResponse.json();

      if (!intentData.success) {
        throw new Error(intentData.message || 'Failed to create payment intent');
      }

      // Confirm payment
      const { error: confirmError, paymentIntent } = await stripe.confirmPayment({
        elements,
        clientSecret: intentData.client_secret,
        confirmParams: {
          return_url: window.location.href
        },
        redirect: 'if_required'
      });

      if (confirmError) {
        setErrors([confirmError.message]);
        setIsProcessing(false);
        return;
      }

      if (paymentIntent && paymentIntent.status === 'succeeded') {
        // Process registration
        const registrationResponse = await fetch(window.newteamConfig.ajaxUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: new URLSearchParams({
            action: 'process_tryout_registration',
            payment_intent_id: paymentIntent.id,
            ...formData,
            tryout_nonce: window.newteamConfig.tryoutNonce
          })
        });

        const registrationResult = await registrationResponse.json();

        if (registrationResult.success) {
          setSuccess(true);
          // Redirect after 3 seconds
          setTimeout(() => {
            window.location.href = '/';
          }, 3000);
        } else {
          setErrors([`Payment successful but registration failed: ${registrationResult.message || 'Unknown error'}`]);
        }
      }
    } catch (error) {
      setErrors([`An error occurred: ${error.message}`]);
    }

    setIsProcessing(false);
  };

  if (success) {
    return (
      <div style={{
        minHeight: '100vh',
        backgroundColor: '#111827',
        color: '#ffffff',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '2rem'
      }}>
        <div style={{
          textAlign: 'center',
          padding: '2.5rem',
          backgroundColor: 'rgba(34, 197, 94, 0.1)',
          border: '2px solid #22c55e',
          borderRadius: '1rem',
          maxWidth: '42rem'
        }}>
          <h2 style={{
            fontSize: '2.25rem',
            fontWeight: 'bold',
            color: '#22c55e',
            marginBottom: '1.25rem'
          }}>🎉 Welcome to Newteam FC!</h2>
          <p style={{ fontSize: '1.25rem', marginBottom: '1rem' }}>Your registration and payment have been successfully processed!</p>
          <p style={{ color: '#d1d5db', marginBottom: '1rem' }}>You will receive a confirmation email shortly with all the tryout details.</p>
          <p style={{ color: '#22c55e', fontWeight: 'bold', fontSize: '1.125rem' }}>Your journey to greatness starts now.</p>
          <div style={{ marginTop: '2rem' }}>
            <a href="/" style={{
              display: 'inline-block',
              background: 'linear-gradient(to right, #dc2626, #991b1b)',
              color: 'white',
              padding: '0.75rem 2rem',
              borderRadius: '9999px',
              fontWeight: '600',
              textDecoration: 'none',
              transition: 'all 0.3s'
            }}>
              Back to Home
            </a>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-900 text-white">
      {/* Hero Section */}
      <section className="relative min-h-[80vh] flex items-center justify-center text-center px-4 py-16"
        style={{
          background: `linear-gradient(rgba(10, 15, 26, 0.8), rgba(31, 41, 55, 0.8)), url('/wp-content/themes/newteam/images/hero-action-shot.jpg') center/cover`,
          backgroundAttachment: 'fixed'
        }}>
        <div className="max-w-4xl mx-auto">
          <h1 className="text-5xl md:text-6xl font-bold mb-6 leading-tight">
            We're building something <span className="bg-gradient-to-r from-red-600 to-amber-500 bg-clip-text text-transparent">special</span>.
            Be part of it.
          </h1>
          <h2 className="text-2xl md:text-3xl font-bold mb-4">
            We're redefining amateur soccer in Massachusetts.
            <span className="text-amber-500"> Help us build the future</span>
          </h2>

          <p className="text-xl mb-8 text-gray-300 leading-relaxed max-w-3xl mx-auto">
            <strong className="text-white">Join Massachusetts' most ambitious soccer project.</strong>
            We're not just building a team — we're creating a <strong className="text-amber-500">pathway to your dreams</strong>
            with direct connections to professional scouts through our Diaza partnership.
          </p>

          <h2 className="text-2xl md:text-3xl font-bold mb-8 leading-tight">
            Want to play competitive soccer and get a chance to go pro?
            <br />
            Come Tryout - <span className="text-red-600">Spots filling Fast</span>
          </h2>

          {/* Diaza Logo */}
          <div className="flex justify-center mb-8">
            <img
              src="/wp-content/themes/newteam/images/DIAZA_LOGO_BLK.png"
              alt="Diaza Football Partnership"
              className="h-48 md:h-60 w-auto brightness-0 invert"
            />
          </div>

          {/* CTA Button */}
          <a href="#register"
            className="inline-block bg-gradient-to-r from-red-600 to-red-800 text-white font-black px-12 py-6 rounded-lg text-xl uppercase tracking-wider hover:shadow-2xl hover:scale-105 transition-all duration-300 animate-pulse border-2 border-amber-500">
            SECURE MY SPOT - $15
          </a>

          <div className="text-sm text-gray-400 mt-4">
            💳 Instant registration • ⚡ Email confirmation • 🛡️ 100% money-back guarantee
          </div>
        </div>
      </section>

      {/* Championship Gallery Section */}
      <Section className="bg-black text-center">
        <h2 className="text-4xl font-bold mb-4">
          Our <span className="bg-gradient-to-r from-red-600 to-amber-500 bg-clip-text text-transparent">Championship Legacy</span>
        </h2>
        <p className="text-xl text-gray-300 mb-12 max-w-3xl mx-auto">
          Join a team with a proven track record of success. Multiple championship titles demonstrate our commitment to excellence.
        </p>

        {/* Team Photo */}
        <div className="mb-12">
          <img
            src="/wp-content/themes/newteam/images/team-photo.jpg"
            alt="Newteam FC Team Photo"
            className="w-full max-w-4xl mx-auto rounded-2xl shadow-2xl"
          />
          <h3 className="mt-6 text-2xl font-bold">The Championship Squad</h3>
          <p className="text-xl text-amber-500">Ready to add another title</p>
        </div>

        {/* Championship Gallery Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-8">
          {[
            { img: 'fall-2023-championship.jpg', title: '🏆 Fall 2023 Champions', desc: 'Most recent championship victory' },
            { img: 'spring-2023-championship.jpg', title: '🏆 Spring 2023 Champions', desc: 'Back-to-back excellence' },
            { img: 'spring-2022-championship.webp', title: '🏆 Spring 2022 Champions', desc: 'Building our championship culture' },
            { img: 'fall-2022-championship.webp', title: '🏆 Fall 2022 Champions', desc: 'Championship tradition' }
          ].map((champ, index) => (
            <div key={index}>
              <img
                src={`/wp-content/themes/newteam/images/${champ.img}`}
                alt={champ.title}
                className="w-full rounded-xl shadow-xl border-2 border-amber-500"
              />
              <h3 className="mt-4 text-xl font-bold text-amber-500">{champ.title}</h3>
              <p className="text-gray-300">{champ.desc}</p>
            </div>
          ))}
        </div>

        {/* Championship Summary */}
        <div className="bg-gray-800 rounded-2xl p-8 border-2 border-amber-500 max-w-2xl mx-auto">
          <h3 className="text-amber-500 text-2xl font-bold mb-4">🏆 Championship Record</h3>
          <p className="text-2xl font-bold mb-2">4 Championships in 2 Years</p>
          <p className="text-gray-300">Proven winners at the highest level of Massachusetts amateur soccer</p>
        </div>
      </Section>

      {/* Registration Form Section */}
      <Section id="register" className="bg-gray-800">
        <div className="text-center mb-12">
          <h2 className="text-4xl font-bold mb-4">
            Secure Your Spot — <span className="bg-gradient-to-r from-red-600 to-amber-500 bg-clip-text text-transparent">Take the First Step</span>
          </h2>
          <p className="text-xl text-gray-300">
            Complete your registration below and take the first step toward the next level.
          </p>
        </div>

        <div className="max-w-2xl mx-auto bg-gray-900 rounded-2xl p-8">
          {errors.length > 0 && (
            <div className="mb-6 p-4 bg-red-500/10 border-2 border-red-500 rounded-lg">
              {errors.map((error, index) => (
                <p key={index} className="text-red-500">{error}</p>
              ))}
            </div>
          )}

          <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
              <div className="flex flex-col">
                <label htmlFor="first_name" className="mb-2 font-semibold text-amber-500">First Name *</label>
                <input
                  type="text"
                  id="first_name"
                  name="first_name"
                  value={formData.first_name}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                />
              </div>
              <div className="flex flex-col">
                <label htmlFor="last_name" className="mb-2 font-semibold text-amber-500">Last Name *</label>
                <input
                  type="text"
                  id="last_name"
                  name="last_name"
                  value={formData.last_name}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
              <div className="flex flex-col">
                <label htmlFor="email" className="mb-2 font-semibold text-amber-500">Email *</label>
                <input
                  type="email"
                  id="email"
                  name="email"
                  value={formData.email}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                />
              </div>
              <div className="flex flex-col">
                <label htmlFor="phone" className="mb-2 font-semibold text-amber-500">Phone *</label>
                <input
                  type="tel"
                  id="phone"
                  name="phone"
                  value={formData.phone}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
              <div className="flex flex-col">
                <label htmlFor="date_of_birth" className="mb-2 font-semibold text-amber-500">Date of Birth *</label>
                <input
                  type="date"
                  id="date_of_birth"
                  name="date_of_birth"
                  value={formData.date_of_birth}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                />
              </div>
              <div className="flex flex-col">
                <label htmlFor="position" className="mb-2 font-semibold text-amber-500">Position *</label>
                <select
                  id="position"
                  name="position"
                  value={formData.position}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                >
                  <option value="">Select Position</option>
                  <option value="goalkeeper">Goalkeeper</option>
                  <option value="defender">Defender</option>
                  <option value="midfielder">Midfielder</option>
                  <option value="forward">Forward</option>
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
              <div className="flex flex-col">
                <label htmlFor="experience" className="mb-2 font-semibold text-amber-500">Experience Level *</label>
                <select
                  id="experience"
                  name="experience"
                  value={formData.experience}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                >
                  <option value="">Select Experience</option>
                  <option value="high_school">High School</option>
                  <option value="club">Club</option>
                  <option value="college">College</option>
                  <option value="semi_pro">Semi-Professional</option>
                  <option value="professional">Professional</option>
                </select>
              </div>
              <div className="flex flex-col">
                <label htmlFor="tryout_date" className="mb-2 font-semibold text-amber-500">Tryout Date *</label>
                <select
                  id="tryout_date"
                  name="tryout_date"
                  value={formData.tryout_date}
                  onChange={handleInputChange}
                  required
                  className="p-3 rounded-lg bg-gray-700 text-white border border-gray-600 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                >
                  <option value="">Choose Date</option>
                  <option value="august_9">Saturday, August 9th, 2025 - $15</option>
                </select>
              </div>
            </div>

            {/* Payment Section */}
            <div className="mb-6">
              <h4 className="text-amber-500 font-semibold mb-4">Payment Details</h4>
              <p className="text-gray-300 mb-4 text-sm">
                Your spot is secured instantly upon payment. Show us you're serious.
              </p>

              <div id="payment-element" className="mb-4">
                {/* Stripe Elements will mount here */}
              </div>
            </div>

            {/* Payment Summary */}
            <div className="bg-amber-500/10 border border-amber-500 rounded-lg p-4 mb-6">
              <h4 className="text-amber-500 font-semibold mb-2">Tryout Registration Fee</h4>
              <p className="mb-1">Total Amount: <span className="font-bold text-amber-500">$15</span></p>
              <small className="text-gray-400">Professional evaluation and team consideration</small>
            </div>

            <button
              type="submit"
              disabled={isProcessing}
              className={`w-full bg-gradient-to-r from-red-600 to-red-800 text-white font-black py-4 rounded-lg text-xl uppercase tracking-wider transition-all ${
                isProcessing
                  ? 'opacity-70 cursor-not-allowed'
                  : 'hover:shadow-2xl hover:scale-[1.02] cursor-pointer'
              }`}
            >
              {isProcessing ? 'Processing...' : 'SECURE MY SPOT - $15'}
            </button>

            <p className="text-gray-400 mt-4 text-sm text-center">
              🔒 Secure payment • Instant confirmation • Money-back guarantee
            </p>
          </form>
        </div>
      </Section>

      {/* About & What You Get Section */}
      <Section className="bg-gray-900">
        {/* Casa League Logo */}
        <div className="text-center mb-12">
          <img
            src="/wp-content/themes/newteam/images/casa-league-logo.png"
            alt="Casa League Logo"
            className="h-32 mx-auto"
          />
        </div>

        <div className="text-center mb-16">
          <h2 className="text-4xl font-bold mb-8">About Newteam FC</h2>

          {/* About Photo */}
          <div className="mb-8">
            <img
              src="/wp-content/themes/newteam/images/about-newteam-photo.jpg"
              alt="About Newteam FC"
              className="w-full max-w-6xl mx-auto rounded-2xl shadow-2xl"
            />
          </div>

          <p className="text-xl text-gray-300 leading-relaxed max-w-3xl mx-auto mb-12">
            We're a group of guys who've managed to compete at the highest level in Massachusetts amateur soccer.
            Through hard work, dedication, and now our partnership with Diaza Football, we've created something special —
            a pathway for serious players to reach their potential and connect with professional opportunities.
          </p>

          {/* Casa League Info */}
          <div className="bg-gray-800 rounded-2xl p-8 border-l-4 border-amber-500 max-w-2xl mx-auto mb-12">
            <h3 className="text-amber-500 text-2xl font-bold mb-4">Competing in Casa League</h3>
            <p className="text-xl text-gray-300 mb-4">
              <strong className="text-amber-500">Top Division</strong> - The highest level of amateur soccer in Massachusetts
            </p>
            <p className="text-gray-400">
              40+ teams • Championship-level competition
            </p>
          </div>

          {/* Pricing Notice */}
          <div className="inline-block bg-red-600 rounded-2xl p-6 border-2 border-amber-500">
            <div className="text-2xl font-bold mb-2">Tryout Registration</div>
            <div className="text-amber-500">Only $15 • Professional Evaluation</div>
          </div>
        </div>

        {/* What You Get */}
        <div className="text-center mb-12">
          <h2 className="text-4xl font-bold mb-4">
            What You're Really <span className="bg-gradient-to-r from-red-600 to-amber-500 bg-clip-text text-transparent">Signing Up For</span>
          </h2>
          <p className="text-xl text-gray-300 mb-12">
            Through our partnership with Diaza Football, this isn't just a tryout — it's an entry point into something bigger:
          </p>
        </div>

        <div className="bg-gray-800 rounded-2xl p-8 border-l-4 border-amber-500 max-w-4xl mx-auto">
          <ul className="space-y-8">
            {[
              {
                title: 'Championship-Level Training',
                desc: 'Professional standards and serious development with experienced coaches who\'ve worked at the highest levels.',
                value: null
              },
              {
                title: '🏆 2 Custom Jerseys',
                desc: 'Home & away kits for players who make the team',
                value: '$120 value'
              },
              {
                title: '⚽ Weekly Competitive Matches',
                desc: 'In one of Boston\'s most respected leagues — compete at the highest level every week',
                value: '$200+ value'
              },
              {
                title: 'Team Brotherhood',
                desc: 'Join a tight-knit group of committed players who support each other on and off the field.',
                value: null
              },
              {
                title: '🎯 Scout & Brand Exposure',
                desc: 'Exposure to scouts, brands, and opportunities through our growing media network',
                value: '$500+ value'
              },
              {
                title: '🚀 Real Path to Pro',
                desc: 'Through our partnership with Diaza Football, players have the opportunity to play in front of scouts from Europe and American teams with a legitimate chance of going pro',
                value: '$1000+ value'
              },
              {
                title: '💡 Direct Coaching Feedback',
                desc: 'Level up even if you don\'t make the squad — valuable feedback for your development',
                value: '$150+ value'
              }
            ].map((item, index) => (
              <li key={index} className="pb-8 border-b border-gray-700 last:border-b-0 last:pb-0">
                <h3 className="text-2xl font-bold mb-4 text-amber-500">{item.title}</h3>
                <p className="text-gray-300 text-lg leading-relaxed">
                  {item.desc} {item.value && <strong className="text-amber-500">({item.value})</strong>}
                </p>
              </li>
            ))}
          </ul>
        </div>
      </Section>

      {/* Player Testimonials */}
      <Section className="bg-gray-800">
        <h2 className="text-4xl font-bold text-center mb-12">
          From Our <span className="bg-gradient-to-r from-red-600 to-amber-500 bg-clip-text text-transparent">Current Players</span>
        </h2>
        <p className="text-xl text-center mb-12 text-gray-300">
          Real experiences from players who've joined something special.
        </p>

        <div className="bg-gray-700 rounded-2xl p-8 border-l-4 border-amber-500 max-w-4xl mx-auto">
          <ul className="space-y-8">
            {[
              {
                quote: "I joined Newteam in Spring 2022 after coming back from injury the prior year and I have to say, it's been nothing but a wild ride. The stories I could tell of all of the games and the adventures we've been on could fill a book.",
                author: "Christian Mukala",
                position: "Midfielder"
              },
              {
                quote: "The Newteam brand is growing. Whenever someone hears newteam FC, they're like 'oh.. that's a good club' strong players.",
                author: "Isai D.",
                position: "Defender"
              },
              {
                quote: "When I first joined Newteam, those guys welcomed me with open arms. it's not just about futbol, it's beyond that and I appreciate it and love it because it allows me to be myself and push me to be better in and out of the field as a person. Also becoming socially involved iwth strangers that care and I would fight for is the real lesson of life.",
                author: "Tonis",
                position: "Midfielder"
              }
            ].map((testimonial, index) => (
              <li key={index} className="pb-8 border-b border-gray-600 last:border-b-0 last:pb-0">
                <div className="text-lg italic text-gray-300 mb-4 leading-relaxed">
                  "{testimonial.quote}"
                </div>
                <div className="font-bold text-amber-500">
                  — {testimonial.author}, {testimonial.position}
                </div>
              </li>
            ))}
          </ul>
        </div>
      </Section>

      {/* Countdown Timer */}
      {daysRemaining > 0 && (
        <div className="fixed bottom-8 right-8 bg-gradient-to-r from-red-600 to-red-800 p-6 rounded-2xl shadow-2xl border-2 border-amber-500 text-center">
          <div className="text-3xl font-bold">{daysRemaining}</div>
          <div className="text-sm">Days Until Tryouts</div>
        </div>
      )}
    </div>
  );
};

export default TryoutPage;
