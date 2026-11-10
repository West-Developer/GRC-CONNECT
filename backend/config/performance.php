<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gzip JSON API responses
    |--------------------------------------------------------------------------
    |
    | When true, App\Http\Middleware\CompressJsonResponse gzips JSON responses
    | over 1 KB for clients that send `Accept-Encoding: gzip` (ADR 0029). Set
    | API_COMPRESS_JSON=false when a reverse proxy (nginx, Apache mod_deflate,
    | a CDN) already compresses, so the body is not handled twice.
    |
    */

    'compress_api_json' => (bool) env('API_COMPRESS_JSON', true),

];
