<?php

declare(strict_types=1);

use App\Enums\WalletPlatform;
use App\Models\User;
use App\Models\WalletPass;
use App\Support\Tenancy\TenantContext;
use App\Support\Wallet\PassCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Tenants;
use Tests\Support\WalletCertificates;

/*
|--------------------------------------------------------------------------
| Add to Apple Wallet (CHW-38, design 25a)
|--------------------------------------------------------------------------
|
| A customer's own card as a signed .pkpass, from C2 and C6: one pass per
| card, the same until it is revoked. Throwaway certificates stand in for
| Apple's.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    $this->certificates = WalletCertificates::install();
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->customer = User::factory()->create(['name' => 'Salma Bint Tazi', 'locale' => 'fr']);
    $this->enrollment = $this->tenants->enroll($this->customer, $this->tenants->cardB);
    $this->passes = fn (): array => $this->context->bypass(fn (): array => WalletPass::query()->get()->all());
    $this->passJson = function (string $pkpass): array {
        $path = (string) tempnam(sys_get_temp_dir(), 'pkpass');
        file_put_contents($path, $pkpass);
        $zip = new ZipArchive;
        $zip->open($path);
        $json = json_decode((string) $zip->getFromName('pass.json'), true);
        $zip->close();
        @unlink($path);

        return $json;
    };
});

afterEach(function (): void {
    $this->certificates->remove();
});

it('gives the customer their card as a signed pass, the same pass each time', function (): void {
    $response = $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.apple.pkpass')
        ->assertHeader('Content-Disposition', 'attachment; filename="card.pkpass"');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $passes = ($this->passes)();
    $json = ($this->passJson)((string) $response->getContent());

    expect($passes)->toHaveCount(1)
        ->and($passes[0]->platform)->toBe(WalletPlatform::Apple)
        ->and($passes[0]->organization_id)->toBe($this->tenants->orgB->id)
        ->and($json['serialNumber'])->toBe($passes[0]->serial)
        ->and($json['barcodes'][0]['message'])->toBe(PassCode::of($passes[0]->serial))
        ->and($json['storeCard']['headerFields'][0])->toMatchArray(['label' => 'Tampons', 'value' => '0/'.$this->tenants->cardB->stamps_required])
        ->and($json['storeCard']['secondaryFields'][1]['value'])->toBe('Salma T.')
        ->and($json['organizationName'])->toBe($this->tenants->orgB->name);

    $again = ($this->passJson)((string) $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->getContent());

    expect(($this->passes)())->toHaveCount(1)->and($again['serialNumber'])->toBe($passes[0]->serial);
});

it('gives a revoked pass a new serial and token, so its old code stays dead', function (): void {
    $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertOk();
    $old = ($this->passes)()[0];
    $this->context->bypass(fn () => $old->forceFill(['revoked_at' => now()])->save());

    $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertOk();
    $new = ($this->passes)()[0];

    expect($new->id)->toBe($old->id)
        ->and($new->serial)->not->toBe($old->serial)
        ->and($new->auth_token)->not->toBe($old->auth_token)
        ->and($new->revoked_at)->toBeNull();
});

it('keeps the token encrypted at rest', function (): void {
    $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertOk();
    $pass = ($this->passes)()[0];
    $stored = DB::table('wallet_passes')->where('id', $pass->id)->value('auth_token');

    expect($stored)->toBeString()->not->toContain($pass->auth_token)
        ->and(strlen($pass->auth_token))->toBe(40);
});

it('never gives anyone else\'s card, nor a card while Apple Wallet is not set up', function (): void {
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertNotFound();

    config(['punchcard.wallet.apple.cert_path' => null]);
    $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertNotFound();

    expect(($this->passes)())->toBe([]);
});

it('asks a guest to sign in, and slows down a customer adding cards over and over', function (): void {
    $this->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertRedirect(route('login'));

    foreach (range(1, 10) as $i) {
        $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertOk();
    }

    $this->actingAs($this->customer)->get(route('app.cards.wallet.apple', $this->enrollment->id))->assertTooManyRequests();
});

it('tells the pages whether Apple Wallet is set up', function (): void {
    $this->actingAs($this->customer)->get(route('app.cards.show', $this->enrollment->id))
        ->assertInertia(fn (Assert $page): Assert => $page->where('wallet.apple', true));

    config(['punchcard.wallet.apple.team_id' => '']);

    $this->actingAs($this->customer)->get(route('app.cards.show', $this->enrollment->id))
        ->assertInertia(fn (Assert $page): Assert => $page->where('wallet.apple', false));
});
