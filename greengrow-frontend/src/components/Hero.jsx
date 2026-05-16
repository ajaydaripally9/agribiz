import React from 'react';
import { motion } from 'framer-motion';

function Hero() {
  return (
    <div className="flex flex-col justify-center h-full max-w-lg z-10 pointer-events-none">
      <motion.div 
        initial={{ opacity: 0, x: -50 }}
        animate={{ opacity: 1, x: 0 }}
        transition={{ duration: 0.8, delay: 0.2 }}
        className="mb-6"
      >
        <span className="flex items-center gap-2 text-gg-neon font-medium mb-4">
          <span className="text-xl">🍃</span> Nourish Today,
        </span>
        <h2 className="text-6xl md:text-7xl font-extrabold leading-tight text-white mb-6">
          Harvest <br/>
          <span className="neon-text text-gg-neon">Tomorrow</span>
        </h2>
        <p className="text-gray-300 text-lg leading-relaxed max-w-md">
          Better crops, better tomorrow. Our premium organic fertilizers help you grow stronger, healthier, and more productive crops naturally.
        </p>
      </motion.div>

      {/* Floating badge */}
      <motion.div
        initial={{ opacity: 0, scale: 0.8 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={{ duration: 0.8, delay: 0.5 }}
        className="absolute top-[45%] left-0 md:-left-12 glass-card rounded-2xl p-4 flex flex-col items-center justify-center border-gg-neon/40 border-2 w-24 shadow-[0_0_20px_rgba(57,255,20,0.2)]"
      >
        <span className="text-2xl mb-1 text-gg-neon">🛡️</span>
        <span className="text-[10px] font-bold text-center leading-tight">100%<br/>ORGANIC</span>
      </motion.div>
    </div>
  );
}

export default Hero;
