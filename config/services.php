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

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'automatic_tax' => env('STRIPE_AUTOMATIC_TAX', true),
    ],

    'webpush' => [
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:support@lookdo.app'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-5.6-luna'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'monthly_budget' => (float) env('OPENAI_MONTHLY_BUDGET', 20),
        'user_daily_limit' => (int) env('OPENAI_USER_DAILY_LIMIT', 20),
        'timeout' => (int) env('OPENAI_TIMEOUT', 300),
        'text_input_cost_per_million' => (float) env('OPENAI_TEXT_INPUT_COST_PER_MILLION', .20),
        'text_output_cost_per_million' => (float) env('OPENAI_TEXT_OUTPUT_COST_PER_MILLION', 1.20),
        'transcription_input_cost_per_million' => (float) env('OPENAI_TRANSCRIPTION_INPUT_COST_PER_MILLION', 1.25),
        'transcription_output_cost_per_million' => (float) env('OPENAI_TRANSCRIPTION_OUTPUT_COST_PER_MILLION', 5),
        'image_cost_low' => (float) env('OPENAI_IMAGE_COST_LOW', .006),
        'image_cost_medium' => (float) env('OPENAI_IMAGE_COST_MEDIUM', .053),
        'image_cost_high' => (float) env('OPENAI_IMAGE_COST_HIGH', .211),
    ],

    'books' => [
        'google_api_key' => env('GOOGLE_BOOKS_API_KEY'),
    ],

    'social' => [
        'meta' => [
            'client_id' => env('META_APP_ID'),
            'client_secret' => env('META_APP_SECRET'),
            'version' => env('META_GRAPH_VERSION', 'v21.0'),
        ],
        'vk' => [
            'client_id' => env('VK_APP_ID'),
            'client_secret' => env('VK_APP_SECRET'),
            'version' => env('VK_API_VERSION', '5.199'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'plesk' => [
        'api_url' => env('PLESK_API_URL'),
        'api_key' => env('PLESK_API_KEY'),
        'subscription_domain' => env('PLESK_SUBSCRIPTION_DOMAIN', env('APP_DOMAIN', 'lookdo.app')),
        'letsencrypt_email' => env('PLESK_LETSENCRYPT_EMAIL'),
        'verify_ssl' => env('PLESK_VERIFY_SSL', true),
    ],

];
