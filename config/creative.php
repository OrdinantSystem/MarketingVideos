<?php

return [
    'root' => env('CREATIVE_PROJECTS_ROOT', base_path('creative-projects')),

    'access' => [
        'mode' => env('CREATIVE_ACCESS_MODE', 'local'),
        'port' => (int) env('CREATIVE_LOCAL_PORT', 8000),
    ],

    'generation' => [
        'driver' => env('CREATIVE_GENERATION_DRIVER', 'simulation'),
        'lightweight' => (bool) env('CREATIVE_LIGHTWEIGHT_MODE', true),
        'timeout' => (int) env('CREATIVE_REQUEST_TIMEOUT', 180),
    ],

    'media' => [
        'execution' => env('CREATIVE_MEDIA_EXECUTION', 'sync'),
        'queue' => env('CREATIVE_MEDIA_QUEUE', 'creative-media'),
        'timeout_seconds' => (int) env('CREATIVE_MEDIA_TIMEOUT', 30),
        'ffmpeg_binary' => env('CREATIVE_FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('CREATIVE_FFPROBE_BINARY', 'ffprobe'),
        'width' => 360,
        'height' => 640,
        'frames_per_second' => 12,
    ],

    'render' => [
        'execution' => env('CREATIVE_RENDER_EXECUTION', 'sync'),
        'queue' => env('CREATIVE_RENDER_QUEUE', 'creative-render'),
        'timeout_seconds' => (int) env('CREATIVE_RENDER_TIMEOUT', 90),
    ],

    'phases' => [
        'ideas' => [
            'number' => '00',
            'name' => 'Ideas',
            'short' => 'Explore directions',
            'optional' => true,
            'output_label' => 'idea options',
            'action' => 'Create 3 ideas',
            'selection_mode' => 'single',
            'slots' => ['primary'],
        ],
        'concepts' => [
            'number' => '01',
            'name' => 'Concept',
            'short' => 'Shape the advertisement',
            'optional' => false,
            'output_label' => 'concept options',
            'action' => 'Create 3 concepts',
            'selection_mode' => 'single',
            'slots' => ['primary'],
        ],
        'brief-script' => [
            'number' => '02',
            'name' => 'Brief & script',
            'short' => 'Write the 15-second story',
            'optional' => false,
            'output_label' => 'script options',
            'action' => 'Create 3 scripts',
            'selection_mode' => 'single',
            'slots' => ['primary'],
        ],
        'images' => [
            'number' => '03',
            'name' => 'Images',
            'short' => 'Choose each keyframe',
            'optional' => false,
            'output_label' => 'keyframe alternatives',
            'action' => 'Create 2 per shot',
            'selection_mode' => 'script_shots',
            'slots' => [],
        ],
        'scenes' => [
            'number' => '04',
            'name' => 'Scenes',
            'short' => 'Turn frames into clips',
            'optional' => false,
            'output_label' => 'scene attempts',
            'action' => 'Create scene previews',
            'selection_mode' => 'script_shots',
            'slots' => [],
        ],
        'sequence' => [
            'number' => '05',
            'name' => 'Sequence',
            'short' => 'Prepare the editor handoff',
            'optional' => false,
            'output_label' => 'sequence plans',
            'action' => 'Create 3 edit plans',
            'selection_mode' => 'single',
            'slots' => ['primary'],
        ],
    ],
];
