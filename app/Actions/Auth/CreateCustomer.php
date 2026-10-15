<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A new customer's account, after their first email link (CHW-23): the
 * address is verified (the link proved it), there is no password, and they
 * said they are 16 or older, and whether they want news by email, each
 * dated (CNDP). Signed up meanwhile by another route: that account, if it
 * may sign in by link (MaySignInByLink), with the consent just given where
 * it has none (its name stays its own); else null.
 */
final readonly class CreateCustomer
{
    public function __construct(
        private MaySignInByLink $maySignInByLink,
        private RecordConsent $recordConsent,
    ) {}

    public function handle(string $email, string $name, bool $wantsNews): ?User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $this->maySignInByLink->handle($existing) ? $this->recordConsent->handle($existing, $wantsNews) : null;
        }

        try {
            $user = $this->create($email, $name, $wantsNews);
        } catch (UniqueConstraintViolationException) {
            // Signed up meanwhile (this form sent twice, another tab): that account, as above.
            return $this->handle($email, $name, $wantsNews);
        }

        event(new Registered($user));

        return $user;
    }

    private function create(string $email, string $name, bool $wantsNews): User
    {
        $user = (new User)->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => null,
            'email_verified_at' => now(),
        ]);

        return $this->recordConsent->handle($user, $wantsNews);
    }
}
