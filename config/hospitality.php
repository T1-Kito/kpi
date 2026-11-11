<?php

// One explicitly scoped FaceID connection. Keep HTTP opt-in for local deployments.
return [
    'base_url' => env('HOSPITALITY_BASE_URL', ''),
    'allow_http' => env('HOSPITALITY_ALLOW_HTTP', false),
    'username' => env('HOSPITALITY_USERNAME', ''),
    'password' => env('HOSPITALITY_PASSWORD', ''),
    'tenant_id' => env('HOSPITALITY_TENANT_ID'),
];
