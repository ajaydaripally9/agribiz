import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  base: './',
  server: {
    // Local development proxy for PHP requests when running the frontend locally.
    // This proxy is not used in a Render production deployment.
    proxy: {
      '^/.*\\.php': {
        target: 'http://localhost/fertilizer-shop',
        changeOrigin: true,
      }
    }
  }
})
