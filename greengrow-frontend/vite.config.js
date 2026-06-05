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
        // Point to the local PHP dev server. If you run PHP with
        // `php -S localhost:8000 -t .` the backend will be available at port 8000.
        // Adjust this if you use a different local dev server or port.
        target: 'http://localhost:8000',
        changeOrigin: true,
        secure: false,
      }
    }
  }
})
