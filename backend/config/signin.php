<?php

/*
 | How people sign in beyond the browser session (docs/decisions/0088-*.md, Q36).
 |
 | These are the *defaults*. The code that reads them is a policy class (App\Support\Auth\DeviceTokenPolicy), so a company-configurable
 | rule — a shorter lifetime, mandatory two-factor, re-authentication — can replace the default later without touching sign-in itself.
 */
return [
    // A bearer token (a phone or device signed in with device_name) is valid this long from the moment it is issued, then the person
    // signs in again. 30 days. There is no refresh token: renewing means authenticating, so a lifetime can never be silently extended.
    // sanctum.expiration is the hard ceiling and also covers any token issued without its own expiry.
    'device_token_lifetime_minutes' => (int) env('ERP_DEVICE_TOKEN_LIFETIME_MINUTES', 43200),

    // The switch for signing in with a device_name at all. On by default (mobile and integration testing need it); turning it off
    // stops any new bearer token being issued without a release. Existing tokens keep their own expiry.
    'bearer_sign_in_enabled' => (bool) env('ERP_BEARER_SIGN_IN', true),
];
