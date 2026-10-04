<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| punchcard domain configuration
|--------------------------------------------------------------------------
|
| Settings for the tap-to-stamp flow, anti-fraud defaults and wallet passes.
| Secrets come from the environment only. See docs/spec.md and docs/adr/.
|
*/
return [

    'tap' => [
        // Base URL written into every NFC stamper (SUN URL). Keep it short:
        // NTAG 424 DNA has limited NDEF space for the mirrored parameters.
        'url' => env('TAP_URL', 'http://localhost:8000/t'),
    ],

    'nfc' => [
        // Master key (hex, 32 chars) for NXP AN10922 AES-128 key diversification:
        // one system-wide SDMMetaReadKey, and a per-tag SDMFileReadKey from the
        // tag UID. Never commit a real value.
        'sun_master_key' => env('NFC_SUN_MASTER_KEY'),
        // Version of the system-wide meta read key. Changing it is a hard cutover:
        // every tag must be re-provisioned (docs/runbooks/stamper-keys.md). Each
        // tag keeps its own key_version (nfc_tags), which only feeds its per-tag keys.
        // Parsed strictly: anything but a whole number 1..65535 becomes false, which
        // punchcard:nfc:check reports, instead of a typo like "2v" silently meaning 2.
        'key_version' => filter_var(env('NFC_SUN_KEY_VERSION', 1), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 0xFFFF],
        ]),
    ],

    'taps' => [
        // The tap log keeps each tap's IP and user agent (personal data): rows are
        // pruned after this many days (scheduled model:prune).
        'retention_days' => (int) env('TAP_RETENTION_DAYS', 180),
        // A signed-out tap waits this long for its customer to sign in.
        'pending_minutes' => (int) env('TAP_PENDING_MINUTES', 30),
        // Rate limits on /t, checked before a tap is recorded (every request writes a tap row).
        'per_ip_per_minute' => (int) env('TAP_PER_IP_PER_MINUTE', 30),
        'per_user_per_minute' => (int) env('TAP_PER_USER_PER_MINUTE', 10),
    ],

    'stamps' => [
        // Defaults for new loyalty cards; each card can override them.
        'cooldown_minutes' => (int) env('STAMP_COOLDOWN_MINUTES', 20),
        'daily_cap' => (int) env('STAMP_DAILY_CAP', 5),
        // How long a staff "arm next tap with N stamps" stays active.
        'arm_seconds' => 60,
        'max_per_tap' => 10,
    ],

    'member_qr' => [
        // Rotating member QR for phones without NFC (TOTP-style window).
        'window_seconds' => 30,
    ],

    'wallet' => [
        'apple' => [
            'pass_type_id' => env('APPLE_PASS_TYPE_ID'),
            'team_id' => env('APPLE_TEAM_ID'),
            'cert_path' => env('APPLE_PASS_CERT_PATH'),
            'cert_password' => env('APPLE_PASS_CERT_PASSWORD'),
            'wwdr_cert_path' => env('APPLE_WWDR_CERT_PATH'),
        ],
        'google' => [
            'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
            'service_account_json' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_JSON'),
        ],
    ],

    // Supported UI locales (code => native name, in switcher order). Morocco first,
    // so French is the default when neither the user nor the browser picks one.
    'locales' => [
        'fr' => 'Français',
        'en' => 'English',
        'ar' => 'العربية',
    ],
    'default_locale' => 'fr',
    'rtl_locales' => ['ar'],

    // Proxies whose X-Forwarded-For is trusted (comma-separated IPs or CIDRs):
    // Cloudflare's published ranges in production. Empty trusts none.
    'trusted_proxies' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))))),

];
