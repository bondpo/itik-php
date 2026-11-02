<?php

return [
    'enabled' => env('MINIO.S3_ENABLED', true),

    'endpoint' => env('MINIO.S3_ENDPOINT', ''),

    'region' => env('MINIO.S3_REGION', 'us-east-1'),

    'bucket' => env('MINIO.S3_BUCKET', ''),

    'access_key' => env('MINIO.S3_ACCESS_KEY', ''),

    'secret_key' => env('MINIO.S3_SECRET_KEY', ''),

    'public_url' => env('MINIO.S3_PUBLIC_URL', ''),

    'path_style' => true,

    'cache_control' => 'public, max-age=31536000',
];
