<?php

return [
    'root' => env('CREATIVE_PROJECTS_ROOT', base_path('creative-projects')),

    'phases' => [
        'ideas' => [
            'number' => '00',
            'name' => 'Ideas',
            'short' => 'Explore directions',
            'optional' => true,
            'output_label' => 'idea options',
            'action' => 'Create 3 ideas',
            'slots' => ['primary'],
        ],
        'concepts' => [
            'number' => '01',
            'name' => 'Concept',
            'short' => 'Shape the advertisement',
            'optional' => false,
            'output_label' => 'concept options',
            'action' => 'Create 3 concepts',
            'slots' => ['primary'],
        ],
        'brief-script' => [
            'number' => '02',
            'name' => 'Brief & script',
            'short' => 'Write the 15-second story',
            'optional' => false,
            'output_label' => 'script options',
            'action' => 'Create 3 scripts',
            'slots' => ['primary'],
        ],
        'images' => [
            'number' => '03',
            'name' => 'Images',
            'short' => 'Choose each keyframe',
            'optional' => false,
            'output_label' => 'keyframe alternatives',
            'action' => 'Create 2 per shot',
            'slots' => ['shot-01', 'shot-02', 'shot-03', 'shot-04', 'shot-05'],
        ],
        'scenes' => [
            'number' => '04',
            'name' => 'Scenes',
            'short' => 'Turn frames into clips',
            'optional' => false,
            'output_label' => 'scene attempts',
            'action' => 'Create scene previews',
            'slots' => ['shot-01', 'shot-02', 'shot-03', 'shot-04', 'shot-05'],
        ],
        'sequence' => [
            'number' => '05',
            'name' => 'Sequence',
            'short' => 'Prepare the editor handoff',
            'optional' => false,
            'output_label' => 'sequence plans',
            'action' => 'Create 3 edit plans',
            'slots' => ['primary'],
        ],
    ],
];
