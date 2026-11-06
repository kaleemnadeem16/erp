<?php

return [
    // Existing ADR 0129 deployment flag; no additional environment setting.
    'mode' => (bool) env('DEMO_MODE', false),
    'user' => [
        'name' => env('DEMO_USER_NAME', 'ERP Demo User'),
        'email' => env('DEMO_USER_EMAIL', 'user@erp.x1techs.com'),
        'password' => env('DEMO_USER_PASSWORD', '!User@Erp@123'),
    ],
];
