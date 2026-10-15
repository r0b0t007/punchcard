<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Auth\SendLoginLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asking for an email sign-in link, done on the queue (CHW-23): looking up
 * the account and deciding what to mail (SendLoginLink) takes longer for an
 * existing account than for an unknown address, so the page only queues
 * this and answers in the same time for any address. The page's language
 * goes with it, for a mail to an address without an account.
 */
final class MailLoginLink implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $email, public readonly string $locale) {}

    public function handle(SendLoginLink $sendLoginLink): void
    {
        $sendLoginLink->handle($this->email, $this->locale);
    }
}
