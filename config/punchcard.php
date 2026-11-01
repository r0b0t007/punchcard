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
        // Per IP is loose: many customers share one behind a mobile carrier's CGNAT or café Wi-Fi.
        'per_ip_per_minute' => (int) env('TAP_PER_IP_PER_MINUTE', 120),
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

    'campaigns' => [
        // A business's campaigns in any 7 days (CHW-42, CampaignLimit), so its followers keep wanting to hear from it.
        'per_week' => (int) env('CAMPAIGNS_PER_WEEK', 3),
    ],

    'customers' => [
        // A business's customers (CHW-44, B4, docs/spec.md "Derived metrics"): going quiet after twice their usual
        // gap between visits, never sooner than quiet_min_days; lost after lost_days; new within new_days of their
        // first visit; a regular from regular_visits visits, while neither quiet nor lost. Each customer's dates are
        // stored: after changing these, run `php artisan punchcard:customers:summarise`.
        'quiet_min_days' => 14,
        'lost_days' => 60,
        'new_days' => 30,
        'regular_visits' => 3,
    ],

    'privacy' => [
        // The inactivity rule (CHW-58, docs/privacy/retention.md): an account unused this long is deleted, or anonymised
        // when it has history. Its owner is emailed this many days before, and always gets that long to sign in.
        'inactive_after_months' => (int) env('INACTIVE_AFTER_MONTHS', 24),
        'warning_days' => (int) env('INACTIVITY_WARNING_DAYS', 30),
        // Warning emails sent a night at most (synchronously, one by one): the rest go on the nights after.
        'warnings_per_run' => (int) env('INACTIVITY_WARNINGS_PER_RUN', 500),
    ],

    'documents' => [
        // Business verification documents (CHW-145): their own key (DocumentCipher), never APP_KEY. "base64:" and 32
        // random bytes; a rotated key stays in previous_keys (comma-separated) until its files are gone. Setup, the deploy
        // check (punchcard:documents:check) and rotation: docs/runbooks/verification-documents.md.
        'key' => env('DOCUMENTS_KEY'),
        'previous_keys' => env('DOCUMENTS_PREVIOUS_KEYS', ''),
        // Kept this long after the platform decides (accepted or rejected), then deleted (docs/privacy/retention.md).
        'retention_months' => 3,
        // One the platform never decided on goes this long after it was sent, and its owners are told.
        'waiting_months' => 12,
    ],

    'admin' => [
        // The platform admin signed in as a business owner (CHW-144): read-only, back to admin after this long.
        'impersonation_minutes' => 30,
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

    // Proxies whose X-Forwarded-For is trusted: comma-separated IPs or CIDRs, or "*"
    // for whatever proxy connects (only when the origin is reachable through proxies
    // alone). List every hop in front of PHP: Cloudflare's published ranges and any
    // load balancer or nginx host between them and PHP. Empty trusts none.
    'trusted_proxies' => trim((string) env('TRUSTED_PROXIES', '')) === '*'
        ? '*'
        : array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))))),

];
