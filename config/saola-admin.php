<?php

return [
    'resources' => ['audit_table' => 'saola_resource_events'],
    'panel' => [
        'enabled' => false,
        'prefix' => 'admin',
        'guard' => null,
        'login_route' => null,
        'ability' => 'access-admin',
        'brand' => 'Saola Admin',
        'ui_catalog' => false, // Read-only Material component catalogue; normal panel authorization applies.
        'home_page' => null, // Menu key opened at /{prefix}; null = first menu the user may open.
        'theme' => 'classic', // Presentation source: resources/saola/admin/themes/{theme}/.
        'host_modules' => null, // View namespace of host-rendered screens, e.g. 'admin.modules' (see Saola\Admin\Http\AdminController); the theme stays the context base.
        'language_scope' => 'admin',
    ],
    'themes' => [], // Optional theme descriptors; bundled theme manifests are discovered automatically.
    'account' => [
        'name_attribute' => 'name',
        'email_attribute' => 'email',
        'avatar_attribute' => null,
        'subtitle_attribute' => null,
        'profile_route' => null,
        'settings_route' => null,
        'logout_route' => null, // Host POST/DELETE route. Never turn logout into a GET link.
    ],
    'media' => [
        'enabled' => true,
        'connection' => null,
        'table' => 'saola_media',
        'disk' => 'local',
        'directory' => 'saola/admin/media',
        'kind_directories' => [], // Optional folders per image/video/audio/document kind.
        'public_disks' => [], // Explicit opt-in: return filesystem URLs and store publicly on these disks.
        'max_bytes' => 20971520,
        'max_pixels' => 40000000,
        // Public website assets. Executable markup/SVG and archives are excluded.
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon',
            'application/pdf', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'video/mp4', 'video/webm'],
        'abilities' => ['view' => 'view-media', 'upload' => 'upload-media', 'update' => 'update-media', 'delete' => 'delete-media'],
    ],
    'settings' => [
        'register_defaults' => true,
        'read_ability' => 'view-site-settings',
        'write_ability' => 'update-site-settings',
        'system_read_ability' => 'view-system-settings',
        'system_write_ability' => 'update-system-settings',
        'connection' => null,
        'table' => 'saola_settings',
        'content_locales' => ['en', 'vi'],
        'default_locale' => 'en',
    ],
    'language' => [
        'connection' => null,
        'table_prefix' => 'saola_',
        'source_locale' => 'en',
        'detect_browser' => false,
        'abilities' => [
            'view' => 'view-admin-languages', 'manage' => 'manage-admin-languages', 'export' => 'export-admin-languages',
            'import' => 'import-admin-languages', 'publish' => 'publish-admin-languages',
        ],
        'exchange' => ['max_bytes' => 2097152, 'max_keys' => 10000, 'ttl' => 604800],
        'dictionary' => [
            'enabled' => true,
            'prefix' => 'saola/languages',
            'middleware' => ['web', 'throttle:60,1'],
        ],
    ],
];
