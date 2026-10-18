<?php

declare(strict_types=1);

namespace App\Actions\Fraud\Concerns;

use App\Models\Business;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

/**
 * Mails a fraud alert to each owner of a business at most once per $key
 * until $until (CHW-36), each owner on their own: a send that fails releases
 * that owner's key and fails the job, so its retry mails only the owners it
 * did not reach.
 */
trait MailsOwnersOnce
{
    private function mailOwnersOnce(Business $business, string $key, CarbonInterface $until, Notification $mail): void
    {
        foreach ($business->owners()->orderBy('users.id')->get() as $owner) {
            $sent = $key.':'.$owner->id;

            if (! Cache::add($sent, true, $until)) {
                continue;
            }

            try {
                Notifications::send($owner, $mail);
            } catch (Throwable $failed) {
                Cache::forget($sent);

                throw $failed;
            }
        }
    }
}
