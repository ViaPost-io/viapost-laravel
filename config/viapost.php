<?php

declare(strict_types=1);

return [
    'api_key' => env('VIAPOST_API_KEY', ''),
    'base_url' => env('VIAPOST_BASE_URL', 'https://api.viapost.io'),
    'timeout' => (int) env('VIAPOST_TIMEOUT', 60),
    'connect_timeout' => (int) env('VIAPOST_CONNECT_TIMEOUT', 10),
    'max_response_bytes' => (int) env('VIAPOST_MAX_RESPONSE_BYTES', 10 * 1024 * 1024),
    'retry' => [
        'max_retries' => (int) env('VIAPOST_RETRY_MAX_RETRIES', 2),
        'base_delay_ms' => (int) env('VIAPOST_RETRY_BASE_DELAY_MS', 250),
        'max_delay_ms' => (int) env('VIAPOST_RETRY_MAX_DELAY_MS', 30_000),
    ],
];
