import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The browser talks only to localhost:5173. Vite forwards /api and /sanctum to Laravel,
// so the login cookie belongs to one origin and no CORS settings are needed in development.
const laravel = process.env.VITE_API_TARGET || 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: laravel, changeOrigin: true },
      '/sanctum': { target: laravel, changeOrigin: true },
    },
  },
  build: {
    rollupOptions: {
      output: { manualChunks: { react: ['react', 'react-dom', 'react-router-dom'], charts: ['recharts'] } },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test/setup.js',
    css: false,
    testTimeout: 20000,
  },
});
