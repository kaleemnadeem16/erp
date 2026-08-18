import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { configureCoreApi } from '@erp/core-api';

import { AppRouter } from './router';
import './index.css';

configureCoreApi({
  baseUrl: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8090/api',
});

const queryClient = new QueryClient();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <AppRouter />
    </QueryClientProvider>
  </StrictMode>
);
