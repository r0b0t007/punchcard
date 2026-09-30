<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Mail\Transport\ArrayTransport;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * Reads what the test "array" mailer actually sent (phpunit.xml sets MAIL_MAILER=array).
 */
final class Mailbox
{
    public static function lastEmail(): Email
    {
        $transport = app('mail.manager')->mailer('array')->getSymfonyTransport();

        if (! $transport instanceof ArrayTransport) {
            throw new RuntimeException('The array mailer is not configured.');
        }

        $message = $transport->messages()->last()?->getOriginalMessage();

        if (! $message instanceof Email) {
            throw new RuntimeException('No email was sent.');
        }

        return $message;
    }
}
