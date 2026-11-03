import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      // The app ships as one bundle that grew past the plugin's 2 MiB default precache limit (the build then fails, it does not warn). Raise the precache ceiling rather than
      // dropping the bundle from the offline shell; splitting the bundle by route is a separate piece of work.
      workbox: { maximumFileSizeToCacheInBytes: 4 * 1024 * 1024 },
      // Real icons/branding are a Phase 4 design-system task — see
      // phase-0-foundation.md scope note "Frontend design system beyond shadcn defaults".
      manifest: {
        name: 'ERP',
        short_name: 'ERP',
        start_url: '/',
        display: 'standalone',
        theme_color: '#0a0a0a',
        background_color: '#ffffff',
      },
    }),
  ],
  server: {
    host: '0.0.0.0',
    port: 5176,
  },
});
