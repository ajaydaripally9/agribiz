import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  base: './',
  server: {
    proxy: {
      '^/.*\\.php': {
        target: 'http://localhost/fertilizer-shop',
        changeOrigin: true,
      }
    }
  }
})
