<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    // Port 3000 is the Next.js dev server (ADR 0013). The 5173 entries are the
    // superseded Vite ports, kept so an existing local .env keeps working.
    explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173',
    )),
)));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    // In local development, permit any private IPv4 subnet (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
    // on dev ports (3000, 5173, etc.) so phones and LAN devices can reach the API without
    // breaking whenever changing networks or DHCP reassigns a local IP address.
    'allowed_origins_patterns' => (bool) env('APP_DEBUG', false)
        ? ['#^https?://(192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+)(:\d+)?$#']
        : [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Acting-Context',
        'X-Queue-Kiosk-Token',
        'X-Request-ID',
    ],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => 600,
    'supports_credentials' => false,
];
