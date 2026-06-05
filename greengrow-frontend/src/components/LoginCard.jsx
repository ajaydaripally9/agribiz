import React, { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Mail, Lock, EyeOff, Eye, ArrowRight, ShieldCheck, User, Phone, Tractor } from 'lucide-react';

function LoginCard() {
  const [activeTab, setActiveTab] = useState('customer'); // 'customer' | 'admin'
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // If the React app and the PHP backend are deployed separately,
  // set VITE_API_BASE_URL to the backend root URL in Render / production.
  // When both are hosted together on the same origin, leave this empty.
  const rawApiBaseUrl = import.meta.env.VITE_API_BASE_URL?.trim() || '';
  const apiBaseUrl = rawApiBaseUrl.replace(/\/$/, '');
  const apiLoginUrl = `${apiBaseUrl ? `${apiBaseUrl}/` : ''}api_login.php`;

  if (import.meta.env.PROD && apiBaseUrl && /(localhost|127\.0\.0\.1)/.test(apiBaseUrl)) {
    console.warn(
      'VITE_API_BASE_URL is configured for localhost in production. Update it to your Render backend URL.'
    );
  }

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const res = await fetch(apiLoginUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ type: activeTab, identifier, password }),
      });
      const data = await res.json();
      if (data.success) {
        window.location.href = data.redirect;
      } else {
        setError(data.message || 'Login failed. Please try again.');
      }
    } catch (err) {
      setError(
        'Connection error. Make sure the PHP server is running and VITE_API_BASE_URL is set correctly for your backend.'
      );
    } finally {
      setLoading(false);
    }
  };

  const handleTabSwitch = (tab) => {
    setActiveTab(tab);
    setError('');
    setIdentifier('');
    setPassword('');
  };

  const isCustomer = activeTab === 'customer';

  return (
    <motion.div
      initial={{ opacity: 0, x: 50 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ duration: 0.8, delay: 0.4 }}
      className="glass-card w-full max-w-md p-8 relative overflow-hidden group"
    >
      {/* Animated border glow */}
      <div className="absolute inset-0 rounded-2xl bg-gradient-to-tr from-gg-neon/10 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-700 pointer-events-none" />
      <div className={`absolute inset-0 rounded-2xl transition-all duration-700 pointer-events-none ${isCustomer ? 'shadow-[inset_0_0_30px_rgba(57,255,20,0.05)]' : 'shadow-[inset_0_0_30px_rgba(59,130,246,0.05)]'}`} />

      {/* Header */}
      <div className="text-center mb-6">
        <h3 className="text-2xl font-bold text-white mb-1">Welcome Back 🍃</h3>
        <p className="text-sm text-gray-400">Sign in to your account</p>
      </div>

      {/* Tab Toggle */}
      <div className="flex bg-black/40 p-1 rounded-xl mb-6 border border-white/10">
        <button
          type="button"
          onClick={() => handleTabSwitch('customer')}
          className={`flex-1 py-2.5 rounded-lg font-medium flex items-center justify-center gap-2 transition-all duration-300 text-sm ${
            isCustomer
              ? 'bg-gg-neon/20 border border-gg-neon/50 text-gg-neon shadow-[0_0_15px_rgba(57,255,20,0.15)]'
              : 'text-gray-400 hover:text-white'
          }`}
        >
          <Phone className="w-4 h-4" /> Customer
        </button>
        <button
          type="button"
          onClick={() => handleTabSwitch('admin')}
          className={`flex-1 py-2.5 rounded-lg font-medium flex items-center justify-center gap-2 transition-all duration-300 text-sm ${
            !isCustomer
              ? 'bg-blue-500/20 border border-blue-400/50 text-blue-400 shadow-[0_0_15px_rgba(59,130,246,0.15)]'
              : 'text-gray-400 hover:text-white'
          }`}
        >
          <Tractor className="w-4 h-4" /> Admin
        </button>
      </div>

      {/* Error Message */}
      <AnimatePresence>
        {error && (
          <motion.div
            initial={{ opacity: 0, y: -10, height: 0 }}
            animate={{ opacity: 1, y: 0, height: 'auto' }}
            exit={{ opacity: 0, y: -10, height: 0 }}
            className="mb-4 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-start gap-2"
          >
            <span className="mt-0.5">⚠️</span>
            <span>{error}</span>
          </motion.div>
        )}
      </AnimatePresence>

      {/* Form */}
      <form onSubmit={handleSubmit} className="space-y-4">
        {/* Identifier Field */}
        <AnimatePresence mode="wait">
          <motion.div
            key={activeTab + '-identifier'}
            initial={{ opacity: 0, x: isCustomer ? -10 : 10 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="relative"
          >
            {isCustomer
              ? <Phone className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
              : <User className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
            }
            <input
              type={isCustomer ? 'tel' : 'text'}
              placeholder={isCustomer ? 'Mobile Number' : 'Admin Username'}
              value={identifier}
              onChange={(e) => setIdentifier(e.target.value)}
              required
              className={`w-full bg-black/30 border rounded-xl py-3 pl-12 pr-4 text-white placeholder-gray-500 focus:outline-none transition-all ${
                isCustomer
                  ? 'border-white/10 focus:border-gg-neon/50 focus:ring-1 focus:ring-gg-neon/50'
                  : 'border-white/10 focus:border-blue-400/50 focus:ring-1 focus:ring-blue-400/50'
              }`}
            />
          </motion.div>
        </AnimatePresence>

        {/* Password Field */}
        <div className="relative">
          <Lock className="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
          <input
            type={showPassword ? 'text' : 'password'}
            placeholder="Password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            className={`w-full bg-black/30 border rounded-xl py-3 pl-12 pr-12 text-white placeholder-gray-500 focus:outline-none transition-all ${
              isCustomer
                ? 'border-white/10 focus:border-gg-neon/50 focus:ring-1 focus:ring-gg-neon/50'
                : 'border-white/10 focus:border-blue-400/50 focus:ring-1 focus:ring-blue-400/50'
            }`}
          />
          <button
            type="button"
            onClick={() => setShowPassword(!showPassword)}
            className="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300 transition-colors"
          >
            {showPassword ? <Eye className="w-4 h-4" /> : <EyeOff className="w-4 h-4" />}
          </button>
        </div>

        {/* Register / Forgot Links */}
        <div className="flex justify-between items-center text-sm">
          {isCustomer ? (
            <a href="customer_register.php" className="text-gray-400 hover:text-gg-neon transition-colors">New? Register here</a>
          ) : (
            <span />
          )}
          <a href="#" className={`transition-colors ${isCustomer ? 'text-gg-neon hover:text-gg-light' : 'text-blue-400 hover:text-blue-300'}`}>Forgot Password?</a>
        </div>

        {/* Submit Button */}
        <motion.button
          type="submit"
          disabled={loading}
          whileHover={{ scale: 1.01 }}
          whileTap={{ scale: 0.98 }}
          className={`w-full py-3.5 rounded-xl text-white font-bold flex justify-center items-center gap-2 transition-all disabled:opacity-60 disabled:cursor-not-allowed ${
            isCustomer
              ? 'bg-gradient-to-r from-[#0a1a0f] to-[#1a4d27] border border-gg-neon hover:shadow-[0_0_25px_rgba(57,255,20,0.4)]'
              : 'bg-gradient-to-r from-blue-900 to-blue-700 border border-blue-400 hover:shadow-[0_0_25px_rgba(59,130,246,0.4)]'
          }`}
        >
          {loading ? (
            <>
              <svg className="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
              </svg>
              Signing In...
            </>
          ) : (
            <>
              {isCustomer ? 'Enter the Store' : 'Admin Dashboard'}
              <ArrowRight className="w-4 h-4" />
            </>
          )}
        </motion.button>
      </form>

      {/* Social Login */}
      <div className="mt-6 flex gap-4">
        <button type="button" className="flex-1 py-3 px-4 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center gap-2 hover:bg-white/10 transition-all text-sm font-semibold">
          <img src="https://www.svgrepo.com/show/475656/google-color.svg" className="w-4 h-4" alt="Google" />
          Google
        </button>
        <button type="button" className="flex-1 py-3 px-4 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center gap-2 hover:bg-white/10 transition-all text-sm font-semibold">
          <img src="https://www.svgrepo.com/show/475647/facebook-color.svg" className="w-4 h-4" alt="Facebook" />
          Facebook
        </button>
      </div>

      {/* Footer */}
      <div className="mt-8 flex items-start gap-3 p-4 rounded-xl bg-gg-neon/5 border border-gg-neon/20">
        <ShieldCheck className="w-5 h-5 text-gg-neon shrink-0 mt-0.5" />
        <div>
          <h4 className="text-xs font-bold text-white mb-0.5">Your data is safe with us</h4>
          <p className="text-[10px] text-gray-400 leading-tight">We use advanced security to protect your information</p>
        </div>
      </div>
    </motion.div>
  );
}

export default LoginCard;
