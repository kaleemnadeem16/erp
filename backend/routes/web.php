<?php

// No routes: this is an API-only backend (docs/architecture/api-conventions.md). The real
// UI is the separate React SPA on a different domain — see docker-compose.prod.yml's
// erp-frontend service. Leaving this file empty means an unmatched request (e.g. the bare
// domain root) 404s like any other unrecognised path, instead of leaking the framework's
// default demo page in production.
