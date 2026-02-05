import React, { useState, useEffect, useRef } from 'react';

/**
 * Winter Protocol Landing Page
 * Lead magnet for free training guide download
 */
const WinterProtocolPage = () => {
  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    source: ''
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [error, setError] = useState('');
  const [recaptchaReady, setRecaptchaReady] = useState(false);
  const recaptchaRef = useRef(null);
  const recaptchaWidgetId = useRef(null);

  // Get WordPress data
  const wpData = typeof window !== 'undefined' ? (window.NEWTEAM_DATA || window.newteamData || {}) : {};
  const siteUrl = wpData.siteUrl || '';
  const themeUrl = wpData.themeUrl || '/wp-content/themes/newteam';
  const ajaxUrl = wpData.ajaxUrl || '/wp-admin/admin-ajax.php';
  const recaptchaSiteKey = wpData.recaptchaSiteKey || '';

  // Images
  const images = {
    logo: `${themeUrl}/images/newteam-transparent-logo.png`,
    guidePreview: `${themeUrl}/images/winter-protocol-preview.png`
  };

  // Traffic source options
  const sourceOptions = [
    { value: '', label: 'Select one...' },
    { value: 'Instagram', label: 'Instagram' },
    { value: 'TikTok', label: 'TikTok' },
    { value: 'Facebook', label: 'Facebook' },
    { value: 'YouTube', label: 'YouTube' },
    { value: 'Google Search', label: 'Google Search' },
    { value: 'Friend/Teammate', label: 'Friend or Teammate' },
    { value: 'Coach', label: 'Coach' },
    { value: 'Other', label: 'Other' }
  ];

  // Initialize reCAPTCHA
  useEffect(() => {
    if (!recaptchaSiteKey) return;

    const checkRecaptcha = () => {
      if (window.grecaptcha && window.grecaptcha.render) {
        setRecaptchaReady(true);
        if (recaptchaRef.current && recaptchaWidgetId.current === null) {
          try {
            recaptchaWidgetId.current = window.grecaptcha.render(recaptchaRef.current, {
              sitekey: recaptchaSiteKey
            });
          } catch (e) {
            console.log('reCAPTCHA already rendered');
          }
        }
      } else {
        setTimeout(checkRecaptcha, 100);
      }
    };

    checkRecaptcha();
  }, [recaptchaSiteKey]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setIsSubmitting(true);

    if (!formData.firstName || !formData.lastName || !formData.email || !formData.source) {
      setError('Please fill in all fields.');
      setIsSubmitting(false);
      return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      setError('Please enter a valid email address.');
      setIsSubmitting(false);
      return;
    }

    let recaptchaResponse = '';
    if (recaptchaSiteKey && window.grecaptcha) {
      recaptchaResponse = window.grecaptcha.getResponse(recaptchaWidgetId.current);
      if (!recaptchaResponse) {
        setError('Please complete the reCAPTCHA verification.');
        setIsSubmitting(false);
        return;
      }
    }

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'process_winter_protocol_signup',
          first_name: formData.firstName,
          last_name: formData.lastName,
          email: formData.email,
          traffic_source: formData.source,
          nonce: wpData.nonce || '',
          'g-recaptcha-response': recaptchaResponse
        })
      });

      const data = await response.json();

      if (data.success) {
        setIsSubmitted(true);
        if (window.grecaptcha && recaptchaWidgetId.current !== null) {
          window.grecaptcha.reset(recaptchaWidgetId.current);
        }
      } else {
        setError(data.message || 'Something went wrong. Please try again.');
      }
    } catch (err) {
      setError('Connection error. Please try again.');
    }

    setIsSubmitting(false);
  };

  return (
    <div className="min-h-screen bg-slate-950 text-white">
      {/* Minimal Nav */}
      <nav className="fixed top-0 left-0 right-0 z-50 bg-gradient-to-b from-black/80 to-transparent">
        <div className="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
          <a href={siteUrl || '/'} className="flex items-center gap-3 no-underline">
            <img src={images.logo} alt="Newteam FC" className="h-10 w-auto" />
          </a>
          <a
            href={siteUrl || '/'}
            className="text-white/60 hover:text-white text-sm transition-colors no-underline"
          >
            Back to Site
          </a>
        </div>
      </nav>

      {/* Hero Section */}
      <section className="relative min-h-screen flex items-center justify-center overflow-hidden py-20">
        {/* Background gradient */}
        <div className="absolute inset-0 bg-gradient-to-br from-slate-900 via-slate-950 to-black" />

        <div className="relative z-10 max-w-3xl mx-auto px-6 text-center">
          {/* Content - Centered */}
          <p className="text-amber-500 text-sm font-bold uppercase tracking-widest mb-6">
            Free Training Guide
          </p>

          <h1 className="font-sports text-4xl sm:text-5xl md:text-6xl font-bold uppercase text-white tracking-tight mb-8 leading-tight">
            For players who are tired of showing up unprepared.
          </h1>

          <div className="text-slate-300 text-lg sm:text-xl leading-relaxed space-y-6 mb-10">
            <p>
              This is the exact system we use at Newteam FC — the same one behind 4 championships in 3 years. And we're giving it away completely free.
            </p>
            <p className="text-white font-medium">
              30 days. 27 drills. Weekly schedules. Accountability tracking.
            </p>
            <p className="text-white font-bold">
              No gym required. No excuses accepted.
            </p>
            <p className="text-slate-400">
              Why free? Because this guide is the front door. Once you're in, you'll get weekly training drops and insider content I only share with the list. When you show up to your next tryout in the best shape of your life, you'll remember where it started.
            </p>
            <p className="text-slate-400">
              And when your teammate asks what changed — send them here.
            </p>
          </div>

          {/* Guide Preview - Centered & Bigger */}
          <div className="mb-12">
            <p className="text-slate-500 text-sm uppercase tracking-widest mb-4">Here's a sample of what you can expect</p>
            <img
              src={images.guidePreview}
              alt="Winter Protocol Guide Preview"
              className="w-full max-w-2xl mx-auto rounded-lg shadow-2xl border border-white/10"
            />
          </div>

          {/* Form - Below everything */}
          <div className="w-full max-w-md mx-auto">
            {!isSubmitted ? (
              <div className="bg-slate-900/80 backdrop-blur border border-white/10 p-6 sm:p-8 rounded-lg">
                <div className="text-center mb-6">
                  <h2 className="font-sports text-2xl font-bold uppercase text-white mb-2">
                    Get the Guide
                  </h2>
                </div>

                {error && (
                  <div className="bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 mb-6 text-sm rounded">
                    {error}
                  </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-xs text-slate-400 uppercase tracking-wider mb-2 font-bold">
                        First Name *
                      </label>
                      <input
                        type="text"
                        value={formData.firstName}
                        onChange={(e) => setFormData({ ...formData, firstName: e.target.value })}
                        placeholder="First"
                        required
                        className="w-full px-4 py-3 bg-slate-800 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 transition-colors rounded"
                      />
                    </div>
                    <div>
                      <label className="block text-xs text-slate-400 uppercase tracking-wider mb-2 font-bold">
                        Last Name *
                      </label>
                      <input
                        type="text"
                        value={formData.lastName}
                        onChange={(e) => setFormData({ ...formData, lastName: e.target.value })}
                        placeholder="Last"
                        required
                        className="w-full px-4 py-3 bg-slate-800 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 transition-colors rounded"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs text-slate-400 uppercase tracking-wider mb-2 font-bold">
                      Email Address *
                    </label>
                    <input
                      type="email"
                      value={formData.email}
                      onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                      placeholder="you@example.com"
                      required
                      className="w-full px-4 py-3 bg-slate-800 border border-white/10 text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 transition-colors rounded"
                    />
                  </div>

                  <div>
                    <label className="block text-xs text-slate-400 uppercase tracking-wider mb-2 font-bold">
                      How did you hear about us? *
                    </label>
                    <select
                      value={formData.source}
                      onChange={(e) => setFormData({ ...formData, source: e.target.value })}
                      required
                      className="w-full px-4 py-3 bg-slate-800 border border-white/10 text-white focus:outline-none focus:border-amber-500 transition-colors rounded appearance-none cursor-pointer"
                      style={{
                        backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%239ca3af'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E")`,
                        backgroundRepeat: 'no-repeat',
                        backgroundPosition: 'right 12px center',
                        backgroundSize: '20px'
                      }}
                    >
                      {sourceOptions.map(option => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </div>

                  {recaptchaSiteKey && (
                    <div className="flex justify-center my-4">
                      <div ref={recaptchaRef}></div>
                    </div>
                  )}

                  <button
                    type="submit"
                    disabled={isSubmitting}
                    className="w-full px-6 py-4 bg-amber-500 text-slate-900 font-sports font-bold text-lg uppercase tracking-wider hover:bg-white transition-colors disabled:opacity-50 rounded"
                  >
                    {isSubmitting ? 'Sending...' : 'Send Me The Guide'}
                  </button>
                </form>

                <p className="text-slate-500 text-xs text-center mt-4">
                  No spam. Unsubscribe anytime.
                </p>
              </div>
            ) : (
              <div className="bg-slate-900/80 backdrop-blur border border-white/10 p-8 rounded-lg text-center">
                <div className="w-16 h-16 bg-emerald-500/20 rounded-full flex items-center justify-center mx-auto mb-6">
                  <svg className="w-8 h-8 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <h3 className="font-sports text-2xl font-bold text-white uppercase mb-2">You're In</h3>
                <p className="text-slate-400 mb-4">
                  Check your inbox. If you don't see it, check Promotions/Spam and move it to Primary so you don't miss what's next.
                </p>
              </div>
            )}
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="bg-slate-950 border-t border-white/5 py-8">
        <div className="max-w-6xl mx-auto px-6">
          <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
            <p className="text-slate-500 text-sm">
              © {new Date().getFullYear()} Newteam FC
            </p>
            <div className="flex items-center gap-6">
              <a href="https://instagram.com/officialnewteamfc" target="_blank" rel="noopener noreferrer" className="text-slate-500 hover:text-white transition-colors no-underline text-sm">
                Instagram
              </a>
              <a href="https://tiktok.com/@officialnewteamfc" target="_blank" rel="noopener noreferrer" className="text-slate-500 hover:text-white transition-colors no-underline text-sm">
                TikTok
              </a>
              <a href={siteUrl || '/'} className="text-slate-500 hover:text-white transition-colors no-underline text-sm">
                Main Site
              </a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default WinterProtocolPage;
