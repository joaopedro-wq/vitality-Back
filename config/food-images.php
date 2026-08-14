<?php

return [
    'disk' => env('FOOD_IMAGE_DISK', 'public'),
    'thumbnail_width' => (int) env('FOOD_IMAGE_THUMBNAIL_WIDTH', 480),
    'minimum_match_score' => (float) env('FOOD_IMAGE_MINIMUM_MATCH_SCORE', 90),
    'user_agent' => env('FOOD_IMAGE_USER_AGENT', 'VitalityPlus-FoodImageEnrichment/1.0'),
    'wikidata_api' => 'https://www.wikidata.org/w/api.php',
    'commons_api' => 'https://commons.wikimedia.org/w/api.php',
];
