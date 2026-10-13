<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LoginLink;
use App\Support\Nfc\FakeTap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prints a working email sign-in link for an address (CHW-23), as the mail
 * would carry it: for the end-to-end tests, which can't read mail, and local
 * work. Runs only in local and testing, like punchcard:fake-tap (anyone with
 * it could sign in as any customer).
 */
#[Signature('punchcard:login-link {email : The address to sign in}')]
#[Description('Print a working email sign-in link for an address (local and testing only)')]
class LoginLinkCommand extends Command
{
    public function handle(): int
    {
        if (! $this->laravel->environment(FakeTap::ENVIRONMENTS)) {
            $this->error('Sign-in links are printed only in local and testing.');

            return self::FAILURE;
        }

        $this->line(LoginLink::for(strtolower(trim((string) $this->argument('email'))))->signedUrl());

        return self::SUCCESS;
    }
}
