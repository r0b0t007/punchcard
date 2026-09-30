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
        // Master key (hex, 32 chars) used to derive per-tag AES-128 keys
        // (NXP AN12196 diversification). Never commit a real value.
        'sun_master_key' => env('NFC_SUN_MASTER_KEY'),
        'key_version' => (int) env('NFC_SUN_KEY_VERSION', 1),
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

    'locales' => ['fr', 'en', 'ar'],

];
