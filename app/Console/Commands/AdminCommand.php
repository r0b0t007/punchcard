<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\NotEligibleForAdmin;
use App\Actions\Auth\SetPlatformAdmin;
use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Makes a user a platform admin, or no longer one (CHW-22): the only way to
 * grant the role, run on the server by someone with shell access
 * (SetPlatformAdmin says who may be one). Prints who holds the role now.
 */
#[Signature('punchcard:admin {email : The user\'s email} {--revoke : Take the role away instead}')]
#[Description('Grant or revoke the platform admin role (Filament at /admin)')]
class AdminCommand extends Command
{
    public function handle(SetPlatformAdmin $setPlatformAdmin): int
    {
        // Fortify keeps emails lowercase; Postgres compares them as typed.
        $user = User::query()->notAnonymised()->where('email', Str::lower((string) $this->argument('email')))->first();

        if (! $user instanceof User) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        try {
            $setPlatformAdmin->handle($user, admin: ! $this->option('revoke'));
        } catch (NotEligibleForAdmin $notEligible) {
            $this->error($notEligible->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('revoke') ? "{$user->email} is no longer an admin." : "{$user->email} is an admin.");

        $admins = User::query()->whereHas('roles', fn ($roles) => $roles->where('name', PlatformRole::Admin->value))->orderBy('email')->pluck('email')->all();
        $this->line('Admins: '.($admins === [] ? 'none' : implode(', ', $admins)));

        return self::SUCCESS;
    }
}
