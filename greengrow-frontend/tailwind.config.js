export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        gg: {
          dark: '#0a1a0f',
          neon: '#39ff14',
          light: '#e0ffe5',
          glass: 'rgba(255, 255, 255, 0.05)',
          border: 'rgba(57, 255, 20, 0.2)'
        }
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      }
    },
  },
  plugins: [],
}
