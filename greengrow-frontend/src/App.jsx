import React, { Suspense } from 'react';
import Navbar from './components/Navbar';
import Hero from './components/Hero';
import Scene3D from './components/Scene3D';
import LoginCard from './components/LoginCard';
import { motion } from 'framer-motion';

function App() {
  return (
    <div className="min-h-screen relative overflow-hidden font-sans text-white">
      {/* 3D Background Scene */}
      <div className="absolute inset-0 z-0 pointer-events-none">
        <Suspense fallback={null}>
          <Scene3D />
        </Suspense>
      </div>

      {/* UI Overlay */}
      <div className="relative z-10 container mx-auto px-6 lg:px-12 pt-8 min-h-screen flex flex-col">
        <Navbar />
        
        <main className="flex-1 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center mt-12 pb-24">
          {/* Left Column: Hero Content */}
          <Hero />

          {/* Right Column: Login Card */}
          <div className="flex justify-center lg:justify-end">
            <LoginCard />
          </div>
        </main>
        
        {/* Features Bottom Bar */}
        <motion.div 
          initial={{ opacity: 0, y: 50 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 1, duration: 0.8 }}
          className="glass-card flex flex-wrap justify-between items-center px-8 py-6 mb-8 gap-4"
        >
          <Feature icon="🌱" title="Boosts Crop Growth" />
          <Feature icon="🌿" title="Enhances Soil Quality" />
          <Feature icon="📈" title="Increases Yield" />
          <Feature icon="✨" title="100% Organic & Safe" />
        </motion.div>
      </div>
    </div>
  );
}

function Feature({ icon, title }) {
  return (
    <div className="flex items-center gap-3">
      <div className="w-10 h-10 rounded-full bg-gg-neon/20 flex items-center justify-center border border-gg-neon/30 text-xl shadow-[0_0_15px_rgba(57,255,20,0.2)]">
        {icon}
      </div>
      <span className="text-sm font-medium text-gray-300">{title}</span>
    </div>
  );
}

export default App;
