<?php

return [
    'app' => [
        'env' => 'production',
        'timezone' => 'America/New_York',
    ],

    'settings' => [
        'logger' => [
            'path' => __DIR__ . '/logs/app.log',
        ],
    ],

    'database' => [
        'type' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'directus',
        'username' => 'root',
        'password' => 'pass',
        'engine' => 'InnoDB',
        'charset' => 'utf8mb4'
    ],

    'cache' => [
        'enabled' => false,
        'response_ttl' => 3600, // seconds
        // 'pool' => [
        //    'adapter' => 'apc'
        // ],
        // 'pool' => [
        //    'adapter' => 'apcu'
        // ],
        // 'pool' => [
        //    'adapter' => 'filesystem',
        //    'path' => '../cache/', // relative to the api directory
        // ],
        // 'pool' => [
        //    'adapter'   => 'memcached',
        //    'host'      => 'localhost',
        //    'port'      => 11211
        // ],
        // 'pool' => [
        //    'adapter'   => 'redis',
        //    'host'      => 'localhost',
        //    'port'      => 6379
        // ],
    ],

    'filesystem' => [
        'adapter' => 'local',
        // By default media directory are located at the same level of directus root
        // To make them a level up outsite the root directory
        // use this instead
        // Ex: 'root' => realpath(ROOT_PATH.'/../storage/uploads'),
        // Note: ROOT_PATH constant doesn't end with trailing slash
        'root' => 'public/storage/uploads',
        // This is the url where all the media will be pointing to
        // here all assets will be (yourdomain)/storage/uploads
        // same with thumbnails (yourdomain)/storage/uploads/thumbs
        'root_url' => '/storage/uploads',
        //   'key'    => 's3-key',
        //   'secret' => 's3-key',
        //   'region' => 's3-region',
        //   'version' => 's3-version',
        //   'bucket' => 's3-bucket'
    ],

    'mail' => [
        'default' => [
            'transport' => 'sendmail',
            'from' => 'admin@admin.com'
        ],
    ],

    'cors' => [
        'enabled' => true,
        'origin' => ['*'],
        'methods' => [
            'GET',
            'POST',
            'PUT',
            'PATCH',
            'DELETE',
            'HEAD',
        ],
        'headers' => [],
        'exposed_headers' => [],
        'max_age' => null, // in seconds
        'credentials' => false,
    ],

    'rate_limit' => [
        'enabled' => false,
        'limit' => 100, // number of request
        'interval' => 60, // seconds
        'adapter' => 'redis',
        'host' => '127.0.0.1',
        'port' => 6379,
        'timeout' => 10
    ],

    'hooks' => [],

    'filters' => [],

    'feedback' => [
        'token' => 'a-kind-of-unique-token',
        'login' => true
    ],

    // These tables will not be loaded in the directus schema
    'tableBlacklist' => [],

    'auth' => [
        'secret_key' => '<secret-authentication-key>',
        'social_providers' => [
            // 'okta' => [
            //     'client_id' => '',
            //     'client_secret' => '',
            //     'base_url' => 'https://dev-000000.oktapreview.com/oauth2/default'
            // ],
            // 'github' => [
            //     'client_id' => '',
            //     'client_secret' => ''
            // ],
            // 'facebook' => [
            //     'client_id'          => '',
            //     'client_secret'      => '',
            //     'graph_api_version'  => 'v2.8',
            // ],
            // 'google' => [
            //     'client_id'       => '',
            //     'client_secret'   => '',
            //     'hosted_domain'   => '*',
            // ],
            // 'twitter' => [
            //     'identifier'   => '',
            //     'secret'       => ''
            // ]
        ]
    ],
];
