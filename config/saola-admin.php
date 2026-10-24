<?php

return [
    'panel' => [
        'enabled' => false,
        'prefix' => 'admin',
        'guard' => null,
        'login_route' => null,
        'ability' => 'access-admin',
        'brand' => 'Saola Admin',
        'language_scope' => 'admin',
        'pages' => [\Saola\Admin\Pages\WorkspacePage::class],
        'css' => 'resources/saola/admin/assets/css/admin.css',
    ],
    'users' => [
        'enabled' => false,
        'model' => null, // Resolve from panel.guard -> auth provider; host may bind UserDirectory instead.
        'read_ability' => 'view-admin-users',
        'query_scope' => null, // Required host class implementing UserQueryScope; no guessed tenant scope.
        'columns' => ['name' => 'name', 'email' => 'email'],
    ],
    'settings' => [
        'register_defaults' => true,
        'read_ability' => 'view-site-settings',
        'write_ability' => 'update-site-settings',
        'connection' => null,
        'table' => 'saola_settings',
        'content_locales' => ['en', 'vi'],
        'default_locale' => 'en',
    ],
    'language' => [
        'connection' => null,
        'table_prefix' => 'saola_',
        'source_locale' => 'en',
        'exchange' => ['max_bytes' => 2097152, 'max_keys' => 10000, 'ttl' => 604800],
        'dictionary' => [
            'enabled' => true,
            'prefix' => 'saola/languages',
            'middleware' => ['web', 'throttle:60,1'],
        ],
    ],
];
