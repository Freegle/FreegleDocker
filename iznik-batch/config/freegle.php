<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Freegle Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Freegle-specific settings. These can be overridden
    | via environment variables to support deployment on different domains.
    |
    */

    'sites' => [
        'user' => env('FREEGLE_USER_SITE', 'https://www.ilovefreegle.org'),
        'mod' => env('FREEGLE_MOD_SITE', 'https://modtools.org'),
    ],

    'api' => [
        'base_url' => env('FREEGLE_API_BASE_URL', 'https://api.ilovefreegle.org'),
        'v2_url' => env('FREEGLE_API_V2_URL', 'https://api.ilovefreegle.org/apiv2'),
    ],

    'branding' => [
        'name' => env('FREEGLE_SITE_NAME', 'Freegle'),
        'logo_url' => env('FREEGLE_LOGO_URL', 'https://www.ilovefreegle.org/icon.png'),
        'wallpaper_url' => env('FREEGLE_WALLPAPER_URL', 'https://www.ilovefreegle.org/wallpaper.png'),
    ],

    'mail' => [
        'noreply_addr' => env('FREEGLE_NOREPLY_ADDR', 'noreply@ilovefreegle.org'),
        'user_domain' => env('FREEGLE_USER_DOMAIN', 'users.ilovefreegle.org'),
        // Internal domains that should be excluded when selecting a user's preferred email.
        // These are Freegle-internal addresses that can't receive external mail.
        // Matches iznik-server's Mail::ourDomain() + GROUP_DOMAIN + yahoogroups.
        'internal_domains' => [
            'users.ilovefreegle.org',
            'groups.ilovefreegle.org',
            'direct.ilovefreegle.org',
            'republisher.freegle.in',
        ],
        'excluded_domain_patterns' => [
            '@yahoogroups.',
        ],
        // Email logging - send BCC copies of specific email types for debugging/monitoring.
        // Format: comma-separated list of email types to log (e.g., "Welcome,ChatNotification").
        'log_types' => env('FREEGLE_MAIL_LOG_TYPES', ''),
        'log_address' => env('FREEGLE_MAIL_LOG_ADDRESS', ''),
        // Email types enabled for sending from iznik-batch.
        // Comma-separated list of email type names that this system should send.
        // Email types: Welcome, ChatNotification, etc.
        // If empty, NO emails will be sent (fail-safe default).
        'enabled_types' => env('FREEGLE_MAIL_ENABLED_TYPES', ''),
        // GeekAlerts email for system alerts and failure notifications.
        'geek_alerts_addr' => env('FREEGLE_GEEK_ALERTS_ADDR', 'geek-alerts@ilovefreegle.org'),
    ],

    'images' => [
        // Image domain for user profile images
        'domain' => env('FREEGLE_IMAGES_DOMAIN', 'https://images.ilovefreegle.org'),

        // Base URLs for source images
        'welcome1' => env('FREEGLE_WELCOME_IMAGE1', 'https://www.ilovefreegle.org/images/welcome1.jpg'),
        'welcome2' => env('FREEGLE_WELCOME_IMAGE2', 'https://www.ilovefreegle.org/images/welcome2.jpg'),
        'welcome3' => env('FREEGLE_WELCOME_IMAGE3', 'https://www.ilovefreegle.org/images/welcome3.jpg'),
        // Email assets (icons and small graphics for email templates)
        'email_assets' => env('FREEGLE_EMAIL_ASSETS_URL', 'https://www.ilovefreegle.org/emailimages'),

        // Rule images for welcome emails (from email_assets folder)
        'rule_free' => env('FREEGLE_RULE_FREE_IMAGE', 'https://www.ilovefreegle.org/emailimages/rule-free.png'),
        'rule_nice' => env('FREEGLE_RULE_NICE_IMAGE', 'https://www.ilovefreegle.org/emailimages/rule-nice.png'),
        'rule_safe' => env('FREEGLE_RULE_SAFE_IMAGE', 'https://www.ilovefreegle.org/emailimages/rule-safe.png'),
    ],

    // TUS uploader for AI-generated images
    'tus_uploader' => env('TUS_UPLOADER', 'https://uploads.ilovefreegle.org:8080'),

    /*
    |--------------------------------------------------------------------------
    | Image Delivery Service
    |--------------------------------------------------------------------------
    |
    | Configuration for the image delivery/resizing service (weserv/images).
    | All email images should use this service for optimal sizing.
    |
    */

    'delivery' => [
        'base_url' => env('FREEGLE_DELIVERY_URL', 'https://delivery.ilovefreegle.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geospatial Settings
    |--------------------------------------------------------------------------
    |
    | SRID 3857 is the Web Mercator projection used for geospatial data.
    |
    */

    'srid' => env('FREEGLE_SRID', 3857),

    /*
    |--------------------------------------------------------------------------
    | Loki Logging
    |--------------------------------------------------------------------------
    |
    | Configuration for Grafana Loki logging. Logs are written to JSON files
    | that Alloy ships to Loki.
    |
    */

    'loki' => [
        'enabled' => env('LOKI_ENABLED', false) || env('LOKI_JSON_FILE', false),
        'log_path' => env('LOKI_JSON_PATH', '/var/log/freegle'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spam Checking
    |--------------------------------------------------------------------------
    |
    | Configuration for spam checking emails during testing.
    | When enabled, emails are checked against SpamAssassin and Rspamd
    | and the scores are added as headers.
    |
    */

    'spam_check' => [
        'enabled' => env('SPAM_CHECK_ENABLED', false),
        'spamassassin_host' => env('SPAMASSASSIN_HOST', 'spamassassin-app'),
        'spamassassin_port' => env('SPAMASSASSIN_PORT', 783),
        'rspamd_host' => env('RSPAMD_HOST', 'rspamd'),
        'rspamd_port' => env('RSPAMD_PORT', 11334),
        'fail_threshold' => env('SPAM_FAIL_THRESHOLD', 5.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | AMP for Email
    |--------------------------------------------------------------------------
    |
    | Configuration for AMP (Accelerated Mobile Pages) email support.
    | AMP emails allow dynamic content and inline actions like replying
    | to messages directly from the email client.
    |
    */

    'amp' => [
        // Enable/disable AMP email generation
        'enabled' => env('AMP_EMAIL_ENABLED', true),

        // Secret key for HMAC token generation
        'secret' => env('AMP_SECRET', env('FREEGLE_AMP_SECRET', '')),

        // API endpoint for AMP requests
        'api_url' => env('AMP_API_URL', 'https://api.ilovefreegle.org/amp'),

        // Token expiry (single token used for both read and write)
        'token_expiry_hours' => env('AMP_TOKEN_EXPIRY', 168), // 7 days

        // Note: AMP CORS validation checks domain suffix, not specific sender.
        // Allowed domains are configured in the Go API: @ilovefreegle.org,
        // @users.ilovefreegle.org, @mail.ilovefreegle.org
        // Per-recipient FROM addresses like notify-xxx@users.ilovefreegle.org work fine.
    ],

    /*
    |--------------------------------------------------------------------------
    | Git Summary (Weekly Code Review)
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered weekly git summaries sent to Discourse.
    | Uses Gemini API to summarize code changes across repositories.
    |
    */

    'git_summary' => [
        'gemini_api_key' => env('GOOGLE_GEMINI_API_KEY', ''),
        'repositories' => [
            '/home/edward/FreegleDockerWSL/iznik-nuxt3',
            '/home/edward/FreegleDockerWSL/iznik-server',
            '/home/edward/FreegleDockerWSL/iznik-server-go',
            '/home/edward/FreegleDockerWSL/iznik-batch',
        ],
        'max_days_back' => 7,
        'discourse_email' => env('FREEGLE_DISCOURSE_TECH_EMAIL', ''),
    ],

    // Note: App release classification (hotfix: detection) is handled directly
    // in CircleCI via the check-hotfix-promote job. See iznik-nuxt3/.circleci/config.yml
];
