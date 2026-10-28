import { defineConfig } from 'orval';

export default defineConfig({
  api: {
    input: '../../../backend/storage/api-docs/openapi.json',
    output: {
      mode: 'tags-split',
      target: 'src/generated',
      schemas: 'src/generated/models',
      client: 'react-query',
      httpClient: 'fetch',
      clean: true,
      // Declared header parameters (today only the quotation draft writes' required Idempotency-Key) become a typed `headers` argument instead of
      // something every caller must remember to put into the generic request options.
      headers: true,
      prettier: true,
      override: {
        mutator: {
          path: 'src/mutator/fetch-client.ts',
          name: 'fetchClient',
        },
        // fetchClient's signature is (url, options: RequestInit) with no separate signal
        // param, which is what the fetch httpClient hardcodes regardless of the mutator's
        // real type — leaving this on produces a queryFn that passes an AbortSignal where
        // RequestInit is expected and fails to type-check. Revisit if query cancellation
        // is needed later (would mean threading `signal` through fetchClient explicitly).
        query: {
          signal: false,
        },
      },
    },
    // The models barrel would otherwise `export *` over a thousand files and make dev load them all —
    // see the script for why. Runs after prettier, so the rewrite is what ends up committed.
    hooks: {
      afterAllFilesWrite: 'node scripts/slim-models-barrel.mjs',
    },
  },
});
