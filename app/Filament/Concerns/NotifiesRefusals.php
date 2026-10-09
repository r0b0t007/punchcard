<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Closure;
use Filament\Notifications\Notification;
use RuntimeException;

/**
 * How the admin panel's actions report (CHW-34, CHW-138): the Action's own
 * words, a success notification, or its refusal (one exception class, its
 * translated message) as a danger notification. Anything else is a bug and
 * stays an error.
 */
trait NotifiesRefusals
{
    /**
     * @param  Closure(): string  $run  runs the Action, returns the success message
     * @param  class-string<RuntimeException>  $refusal
     */
    private static function notifying(Closure $run, string $refusal): void
    {
        try {
            Notification::make()->success()->title($run())->send();
        } catch (RuntimeException $failed) {
            if (! $failed instanceof $refusal) {
                throw $failed;
            }

            Notification::make()->danger()->title($failed->getMessage())->send();
        }
    }
}
