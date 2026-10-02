import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { configureCoreApi } from '@erp/core-api';
import { AppearanceProvider, createAppQueryClient } from '@erp/core-ui';

import { AppRouter } from './router';
import './index.css';

// No VITE_API_BASE_URL is set in dev — the fallback derives the API host from
// whatever host the SPA itself was loaded from, not a hardcoded 'localhost'.
// A hardcoded localhost breaks the moment the SPA is opened from another
// device on the LAN (e.g. a phone testing at http://<dev-machine-ip>:5176):
// 'localhost' in that browser resolves to the phone itself, not this machine.
configureCoreApi({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? `http://${window.location.hostname}:8090/api`,
});

// Retry rules and "refresh what is on screen after any saved change" live in createAppQueryClient.
const queryClient = createAppQueryClient();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <AppearanceProvider>
        <AppRouter />
      </AppearanceProvider>
    </QueryClientProvider>
  </StrictMode>
);
