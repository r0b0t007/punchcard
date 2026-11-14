<?php

declare(strict_types=1);

namespace App\Actions\Wallet;

use App\Enums\WalletPlatform;
use App\Models\CardEnrollment;
use App\Models\LoyaltyCard;
use App\Models\Organization;
use App\Models\User;
use App\Models\WalletPass;
use App\Support\Cards\CustomerCard;
use App\Support\Tenancy\TenantContext;
use App\Support\Wallet\ApplePassJson;
use App\Support\Wallet\AppleWalletConfig;
use App\Support\Wallet\PassCode;
use App\Support\Wallet\PassImages;
use App\Support\Wallet\SignPkpass;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The customer's card as an Apple Wallet pass (CHW-38, design 25): the
 * enrollment's pass, made the first time with a random serial and token (a
 * revoked one gets new ones, so its old code stays dead), then the signed
 * .pkpass built from what the card shows now (CustomerCard::ofProgram, the
 * colours through CardBrand), in the customer's language. In bypass(): a
 * customer has no tenant, and the caller has checked the card is theirs.
 */
final readonly class IssueApplePass
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{pass: WalletPass, pkpass: string}
     */
    public function handle(User $user, CardEnrollment $enrollment): array
    {
        return $this->context->bypass(function () use ($user, $enrollment): array {
            $pass = $this->passOf($enrollment);

            return ['pass' => $pass, 'pkpass' => $this->build($pass, $user, $enrollment)];
        });
    }

    /** The pkpass of a pass as it is now: the web service sends it again after a change (part 2). */
    public function build(WalletPass $pass, User $user, CardEnrollment $enrollment): string
    {
        return $this->context->bypass(function () use ($pass, $user, $enrollment): string {
            $card = LoyaltyCard::query()->findOrFail($enrollment->card_id);
            $organization = Organization::query()->findOrFail($enrollment->organization_id, ['id', 'name', 'type', 'brand_color', 'logo_path', 'cover_path']);
            $shown = CustomerCard::ofProgram($organization, $card, CustomerCard::stampsOf($card, $enrollment));
            $locale = $this->localeOf($user);
            [$background, $foreground, $stamp] = ApplePassJson::colours($shown['brandColor'], $shown['brandForeground']);

            $json = ApplePassJson::build([
                'passTypeId' => AppleWalletConfig::passTypeId(),
                'teamId' => AppleWalletConfig::teamId(),
                'serial' => $pass->serial,
                'authToken' => $pass->auth_token,
                'webServiceUrl' => url('/wallet/apple'),
                'organizationName' => $organization->name,
                'cardName' => $shown['cardName'],
                'rewardText' => $shown['rewardText'],
                'terms' => $card->terms,
                'stampsCollected' => $shown['stampsCollected'],
                'stampsRequired' => $shown['stampsRequired'],
                'brandColor' => $shown['brandColor'],
                'brandForeground' => $shown['brandForeground'],
                'memberName' => $user->shortName(),
                'memberNumber' => $user->id,
                'cardUrl' => route('app.cards.show', $enrollment->id),
                'locale' => $locale,
            ]);

            $images = PassImages::for([
                'stampsCollected' => $shown['stampsCollected'],
                'stampsRequired' => $shown['stampsRequired'],
                'stampStyle' => $shown['stampStyle'],
                'background' => $background,
                'foreground' => $foreground,
                'stamp' => $stamp,
                'rtl' => in_array($locale, (array) config('punchcard.rtl_locales'), true),
                'logoPath' => $organization->logo_path,
            ]);

            return SignPkpass::handle(['pass.json' => json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ...$images]);
        });
    }

    private function passOf(CardEnrollment $enrollment): WalletPass
    {
        $pass = WalletPass::query()->where('enrollment_id', $enrollment->id)->where('platform', WalletPlatform::Apple)->first();

        if ($pass instanceof WalletPass && ! $pass->isRevoked()) {
            return $pass;
        }

        $values = ['serial' => PassCode::newSerial(), 'auth_token' => Str::random(40), 'changed_at' => now(), 'revoked_at' => null];

        if ($pass instanceof WalletPass) {
            $pass->forceFill($values)->save();

            return $pass;
        }

        try {
            $pass = new WalletPass;
            $pass->forceFill(['enrollment_id' => $enrollment->id, 'platform' => WalletPlatform::Apple, ...$values])->save();

            return $pass;
        } catch (UniqueConstraintViolationException) {
            // Two first adds at once (a double tap): the other request made it, and both answer with that pass.
            return WalletPass::query()->where('enrollment_id', $enrollment->id)->where('platform', WalletPlatform::Apple)->firstOrFail();
        }
    }

    /** The customer's language, else the app's default. */
    private function localeOf(User $user): string
    {
        $locales = array_keys((array) config('punchcard.locales'));

        return is_string($user->locale) && in_array($user->locale, $locales, true) ? $user->locale : (string) config('punchcard.default_locale');
    }
}
