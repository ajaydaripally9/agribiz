import React from 'react';
import { motion } from 'framer-motion';
import { Leaf, Globe } from 'lucide-react';

function Navbar() {
  return (
    <motion.nav 
      initial={{ y: -50, opacity: 0 }}
      animate={{ y: 0, opacity: 1 }}
      transition={{ duration: 0.6 }}
      className="flex justify-between items-center w-full"
    >
      <div className="flex items-center gap-2 cursor-pointer">
        <Leaf className="w-8 h-8 text-gg-neon" />
        <div>
          <h1 className="text-xl font-bold tracking-tight text-white leading-none">GreenGrow</h1>
          <span className="text-[10px] uppercase tracking-[0.2em] text-gray-400">Fertilizers</span>
        </div>
      </div>

      <div className="hidden md:flex items-center gap-8 text-sm font-medium text-gray-300">
        <a href="#" className="text-white hover:text-gg-neon transition-colors">Home</a>
        <a href="#" className="hover:text-white transition-colors">Products</a>
        <a href="#" className="hover:text-white transition-colors">About Us</a>
        <a href="#" className="hover:text-white transition-colors">Crop Guide</a>
        <a href="#" className="hover:text-white transition-colors">Contact</a>
      </div>

      <div className="flex items-center gap-2 glass-card px-4 py-2 rounded-full cursor-pointer hover:bg-white/10 transition-colors">
        <Globe className="w-4 h-4 text-gray-300" />
        <span className="text-sm font-medium text-gray-300">English</span>
      </div>
    </motion.nav>
  );
}

export default Navbar;
