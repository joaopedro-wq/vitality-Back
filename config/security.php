<?php

return [
    /* Enable only after the value was validated against the real SPA assets. */
    'content_security_policy' => env('SECURITY_CSP', "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; object-src 'none'"),
    'content_security_policy_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
];
