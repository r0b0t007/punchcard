<?php

declare(strict_types=1);

namespace App\Actions\Taps;

use App\Enums\StamperStatus;
use App\Enums\TapRejection;
use App\Enums\TapStatus;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Models\Tap;
use App\Models\User;
use App\Support\Nfc\KeyDiversifier;
use App\Support\Nfc\SunMessage;
use App\Support\Nfc\SunVerificationFailed;
use App\Support\Nfc\SunVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Receives a tap on /t (CHW-25, sun-nfc-verification skill) and records it.
 *
 * 1. Verify the SUN URL: the meta read key from the global key version, the
 *    tag's file read key from its own key_version. A malformed, forged or
 *    unknown-tag URL is recorded refused, with no tag or counter: nothing in
 *    it can be trusted.
 * 2. In one committed step, the tag row locked: the MAC verified with the
 *    tag's own key version, then the counter must be above the tag's last
 *    one, and is spent before anything else can refuse the tap, so a refused
 *    URL can never be opened again. Then the current stamper is locked
 *    (Stamper::current(), never by status), its arming read and cleared, and
 *    the tap recorded pending (or refused: retired tag, no stamper, paused
 *    stamper).
 *
 * The stamp itself comes later, in ApplyTap, once the customer is known, so
 * an AddStamps refusal can never roll the counter back. A key configuration
 * error (InvalidArgumentException) is a server error, never a recorded tap;
 * nothing here logs the URL or any key. Call it outside any transaction: the
 * spent counter must commit on its own.
 */
final readonly class ReceiveTap
{
    public function __construct(
        private TenantContext $context,
        private SunVerifier $verifier,
        private KeyDiversifier $keys,
    ) {}

    public function handle(
        #[\SensitiveParameter] string $e,
        #[\SensitiveParameter] string $c,
        ?User $user,
        ?string $ip,
        ?string $userAgent,
    ): Tap {
        $request = [
            'user_id' => $user?->id,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ];

        try {
            $message = $this->verifier->decrypt($e, $this->keys->metaReadKey($this->metaVersion()));
        } catch (SunVerificationFailed $failed) {
            return $this->record([...$request, 'status' => TapStatus::Rejected, 'rejection' => $failed->reason]);
        }

        return DB::transaction(fn (): Tap => $this->context->bypass(fn (): Tap => $this->spend($message, $c, $request)));
    }

    /**
     * Under the tag lock: verify the MAC with the tag's key version as it is
     * now (a re-provisioning cannot slip in between), spend the counter, then
     * find the stamper and its arming. FOR NO KEY UPDATE, not FOR UPDATE: taps
     * on the tag still queue here, but a stamp event's foreign key check on the
     * tag (KEY SHARE) is never blocked by it, so applying an earlier tap on the
     * same stamper cannot deadlock with this one.
     *
     * @param  array{user_id: int|null, ip: string|null, user_agent: string|null}  $request
     */
    private function spend(SunMessage $message, #[\SensitiveParameter] string $c, array $request): Tap
    {
        $tag = NfcTag::query()->where('uid', $message->uid)->lock('for no key update')->first();

        if (! $tag instanceof NfcTag) {
            return $this->record([...$request, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::UnknownTag]);
        }

        try {
            $tap = $this->verifier->verifyMac($message, $c, $this->keys->fileReadKey($message->uid, $tag->key_version));
        } catch (SunVerificationFailed $failed) {
            return $this->record([...$request, 'status' => TapStatus::Rejected, 'rejection' => $failed->reason]);
        }

        // A replay carries no counter: its counter is already on the row that spent it.
        if ($tap->counter <= $tag->last_counter) {
            return $this->record([...$request, 'nfc_tag_id' => $tag->id, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::Replay]);
        }

        $tag->forceFill(['last_counter' => $tap->counter])->save();
        $trusted = [...$request, 'nfc_tag_id' => $tag->id, 'counter' => $tap->counter];

        if ($tag->retired_at !== null) {
            return $this->record([...$trusted, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::RetiredTag]);
        }

        $stamper = Stamper::query()->current()->where('nfc_tag_id', $tag->id)->lockForUpdate()->first();

        if (! $stamper instanceof Stamper) {
            return $this->record([...$trusted, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::UnassignedTag]);
        }

        $trusted = [...$trusted, 'stamper_id' => $stamper->id, 'business_id' => $stamper->business_id];

        if ($stamper->status !== StamperStatus::Active) {
            return $this->record([...$trusted, 'status' => TapStatus::Rejected, 'rejection' => TapRejection::StamperDisabled]);
        }

        $qty = $stamper->armed_qty !== null && $stamper->armed_until?->isFuture() ? $stamper->armed_qty : 1;

        if ($stamper->armed_qty !== null) {
            $stamper->forceFill(['armed_qty' => null, 'armed_until' => null])->save();
        }

        return $this->record([
            ...$trusted,
            'qty' => $qty,
            'status' => TapStatus::Pending,
            'expires_at' => now()->addMinutes((int) config('punchcard.taps.pending_minutes')),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function record(array $attributes): Tap
    {
        return $this->context->bypass(function () use ($attributes): Tap {
            $tap = (new Tap)->forceFill($attributes);
            $tap->save();

            return $tap;
        });
    }

    private function metaVersion(): int
    {
        $version = config('punchcard.nfc.key_version');

        if (! is_int($version)) {
            throw new InvalidArgumentException('NFC_SUN_KEY_VERSION must be a whole number from 1 to 65535 (punchcard:nfc:check).');
        }

        return $version;
    }
}
