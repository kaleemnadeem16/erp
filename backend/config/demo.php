<?php

return [
    // Existing ADR 0129 deployment flag; no additional environment setting.
    'mode' => (bool) env('DEMO_MODE', false),
    'user' => [
        'name' => env('DEMO_USER_NAME', 'ERP Demo User'),
        'email' => env('DEMO_USER_EMAIL', 'user@erp.x1techs.com'),
        // No default (issue #100): when unset, `demo:seed` generates one random password and prints it once. Never commit a real value.
        'password' => env('DEMO_USER_PASSWORD'),
    ],
];
