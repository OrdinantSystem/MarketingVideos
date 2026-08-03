<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'structure_model' => env('OPENROUTER_STRUCTURE_MODEL', 'openai/gpt-5.6-sol'),
        'polish_model' => env('OPENROUTER_POLISH_MODEL', 'anthropic/claude-fable-5'),
        'reasoning_effort' => env('OPENROUTER_REASONING_EFFORT', 'low'),
        'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 2200),
        'http_referer' => env('OPENROUTER_HTTP_REFERER', env('APP_URL')),
        'app_name' => env('OPENROUTER_APP_NAME', env('APP_NAME', 'VideoAuto')),
    ],

    'fal' => [
        'key' => env('FAL_KEY'),
        'base_url' => env('FAL_BASE_URL', 'https://fal.run'),
        'platform_base_url' => env('FAL_PLATFORM_BASE_URL', 'https://api.fal.ai/v1'),
        'seedance_model' => env('FAL_SEEDANCE_MODEL', 'bytedance/seedance-2.0/fast/text-to-video'),
        'seedance_image_model' => env('FAL_SEEDANCE_IMAGE_MODEL', 'bytedance/seedance-2.0/fast/image-to-video'),
        'resolution' => env('FAL_SEEDANCE_RESOLUTION', '480p'),
        'duration' => (int) env('FAL_SEEDANCE_DURATION', 4),
        'aspect_ratio' => env('FAL_SEEDANCE_ASPECT_RATIO', '9:16'),
        'generate_audio' => (bool) env('FAL_SEEDANCE_GENERATE_AUDIO', false),
    ],

    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1'),
        'voice_id' => env('ELEVENLABS_VOICE_ID'),
        'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_flash_v2_5'),
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_22050_32'),
    ],

];
