<?php

declare(strict_types=1);

use NotificationChannels\WebPush\PushSubscription;

/*
|--------------------------------------------------------------------------
| Web push (CHW-41)
|--------------------------------------------------------------------------
|
| laravel-notification-channels/webpush, its VAPID keys from the env. With
| no public key the app offers no notifications at all (a local install, a
| test). The subject is a mailto: or https: contact for the push services.
|
*/

return [

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    // The app's own connection.
    'database_connection' => null,

    // Guzzle's, for each push service: a slow one gives up after 5 seconds, so a campaign's batch
    // (DeliverCampaign::BATCH) stays inside its job's timeout, and a reward push never hangs a worker.
    'client_options' => ['timeout' => 5],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
