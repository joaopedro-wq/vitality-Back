<?php

return [
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
    'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/interactions'),
    'timeout' => (int) env('GEMINI_TIMEOUT', 10),
    'enabled' => (bool) env('MEAL_PLAN_AI_ENABLED', true),
    'draft_ttl_minutes' => (int) env('MEAL_PLAN_DRAFT_TTL_MINUTES', 30),
];
