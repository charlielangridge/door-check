<?php

return [
    'generate' => [
        // Leave declarations out of the generated files
        'ignore' => [
            // Attribute classes treated the same as Laravel\Wayfinder\Attributes\WayfinderIgnore
            'attributes' => [],
            // Comment tags that leave out the array key they sit on
            'tags' => ['wayfinder-ignore'],
        ],
        'route' => [
            'actions' => env('WAYFINDER_GENERATE_ROUTE_ACTIONS', true),
            'named' => env('WAYFINDER_GENERATE_NAMED_ROUTES', true),
            'form_variant' => env('WAYFINDER_GENERATE_FORM_VARIANT', true),
            'ignore' => [
                /**
                 * The unused settings redirect accepts QUERY, which Wayfinder next
                 * does not yet include in its generated HTTP method type.
                 */
                'urls' => ['settings'],
                // Patterns to ignore for route names (e.g. 'nova.*')
                'names' => ['boost.*', 'nova.*'],
            ],
        ],
        'models' => env('WAYFINDER_GENERATE_MODELS', true),
        'inertia' => [
            'shared_data' => env('WAYFINDER_GENERATE_INERTIA_SHARED_DATA', true),
            'component' => env('WAYFINDER_GENERATE_INERTIA_COMPONENT', false),
        ],
        'broadcast' => [
            'channels' => env('WAYFINDER_GENERATE_BROADCAST_CHANNELS', true),
            'events' => env('WAYFINDER_GENERATE_BROADCAST_EVENTS', true),
        ],
        'environment_variables' => env('WAYFINDER_GENERATE_ENVIRONMENT_VARIABLES', true),
        'enums' => env('WAYFINDER_GENERATE_ENUMS', true),
        'enum_methods' => env('WAYFINDER_GENERATE_ENUM_METHODS', false),
    ],

    // Format the generated files
    'format' => [
        'enabled' => env('WAYFINDER_FORMAT_ENABLED', false),
    ],

    'cache' => [
        'enabled' => env('WAYFINDER_CACHE_ENABLED', true),
        'directory' => env('WAYFINDER_CACHE_DIRECTORY', storage_path('wayfinder-cache')),
    ],

    'memory_limit' => env('WAYFINDER_MEMORY_LIMIT'),
];
