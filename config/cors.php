<?php

return [
   // 'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],
   'paths' => ['*'],
    'allowed_methods' => ['*'],

    'allowed_origins' => [
        
      'https://tedarik.haldeki.com',
    ],

    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',
        '/^http:\/\/127\.0\.0\.1:\d+$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
