<?php

declare(strict_types=1);

use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\VerifyBusiness;
use App\Actions\Verification\DescribeVerification;
use App\Actions\Verification\DocumentRefused;
use App\Actions\Verification\SubmitDocument;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\PlatformRole;
use App\Http\Middleware\SetTenant;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Verification\DocumentCipher;
use App\Support\Verification\DocumentKeyMissing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Sending verification documents (CHW-145, design 9a)
|--------------------------------------------------------------------------
|
| Whoever runs a business waiting to be verified sends an ID of the owner
| and a proof of its address: JPG, PNG or PDF, 10 MB at most. The file is
| encrypted with the documents' own key (never APP_KEY) on the private
| documents disk under a random name, and never shown back; a new one
| replaces the one waiting or rejected. Verifying the business accepts them
| and starts their 3 months. The page says where each stands, and the
| dashboard checklist ticks once both are sent.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 10:00:00');
    app()->setLocale('en');
    Storage::fake(BusinessDocument::DISK);
    Storage::fake('public');
    config(['punchcard.documents.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'punchcard.documents.previous_keys' => '']);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['status' => BusinessStatus::Pending, 'verified_at' => null])->save());
    $this->owner = $this->tenants->member(User::factory()->create(), $this->tenants->a1, BusinessRole::Owner);

    $this->as = fn (User $user, ?Business $business = null) => $this->actingAs($user)->withSession([SetTenant::SESSION_KEY => 'business:'.($business ?? $this->tenants->a1)->id]);
    $this->send = fn (DocumentKind $kind, UploadedFile $file, ?User $as = null) => ($this->as)($as ?? $this->owner)
        ->post(route('verification.store', $kind), ['file' => $file]);
    $this->documents = fn (): array => $this->context->bypass(fn (): array => BusinessDocument::query()->orderBy('id')->get()->all());
    $this->pdf = fn (string $name = 'id.pdf', int $kb = 200): UploadedFile => UploadedFile::fake()->create($name, $kb, 'application/pdf');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** A file as a browser sends it: its type is read from its content, whatever its name. */
function documentAsSent(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

it('keeps a document encrypted, under a random name, on the private disk only', function (): void {
    $file = UploadedFile::fake()->image('my passport.jpg', 800, 600);
    $plain = (string) file_get_contents($file->getRealPath());

    ($this->send)(DocumentKind::IdDocument, $file)->assertRedirect(route('verification.show'))
        ->assertInertiaFlash('toast.type', 'success');

    [$document] = ($this->documents)();
    $stored = Storage::disk(BusinessDocument::DISK)->get($document->path);

    expect($document->kind)->toBe(DocumentKind::IdDocument)
        ->and($document->status)->toBe(DocumentStatus::Submitted)
        ->and($document->business_id)->toBe($this->tenants->a1->id)
        ->and($document->organization_id)->toBe($this->tenants->orgA->id)
        ->and($document->uploaded_by)->toBe($this->owner->id)
        ->and($document->mime)->toBe('image/jpeg')
        ->and($document->path)->toStartWith($this->tenants->a1->id.'/')->not->toContain('passport')
        ->and($stored)->not->toBe($plain)->not->toContain('JFIF')
        ->and(app(DocumentCipher::class)->decrypt((string) $stored))->toBe($plain)
        ->and($document->key_id)->toBe(app(DocumentCipher::class)->keyId())
        // The raw bytes, encrypted: the size of the file, not twice it.
        ->and(strlen((string) $stored))->toBe(strlen($plain) + 29)
        ->and(fn () => Crypt::decryptString((string) $stored))->toThrow(DecryptException::class)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(config('filesystems.disks.documents'))->not->toHaveKeys(['serve', 'url'])
        ->and(config('filesystems.disks.documents.root'))->not->toStartWith((string) config('filesystems.disks.local.root'));
});

it('stores nothing without the documents\' key, and reads a file after the key is rotated', function (): void {
    config(['punchcard.documents.key' => null]);
    expect(fn () => app(SubmitDocument::class)->handle($this->tenants->a1, $this->owner, DocumentKind::IdDocument, ($this->pdf)()))
        ->toThrow(RuntimeException::class, 'DOCUMENTS_KEY')
        ->and(Storage::disk(BusinessDocument::DISK)->allFiles())->toBe([]);

    $old = 'base64:'.base64_encode(str_repeat('o', 32));
    config(['punchcard.documents.key' => $old]);
    $stored = app(DocumentCipher::class)->encrypt('the scan');
    config(['punchcard.documents.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'punchcard.documents.previous_keys' => $old]);

    expect(app(DocumentCipher::class)->decrypt($stored))->toBe('the scan')
        // One bit of the last byte flipped: always a change (replacing it with "x" left it as it was once in 256 runs).
        ->and(fn () => app(DocumentCipher::class)->decrypt(substr($stored, 0, -1).chr(ord($stored[-1]) ^ 1)))->toThrow(DecryptException::class);
});

it('tells the owner sending is not set up yet when the key is missing, and reports it', function (): void {
    config(['punchcard.documents.key' => '']);
    Exceptions::fake();

    ($this->send)(DocumentKind::IdDocument, ($this->pdf)())->assertRedirect(route('verification.show'))
        ->assertInertiaFlash('toast.type', 'error');

    Exceptions::assertReported(DocumentKeyMissing::class);
    expect(($this->documents)())->toBe([])
        ->and(Storage::disk(BusinessDocument::DISK)->allFiles())->toBe([]);
});

it('sets DOCUMENTS_KEY in the environment file once, never replacing a usable one', function (string $placeholder): void {
    $dir = sys_get_temp_dir().'/punchcard-env-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.env', "APP_NAME=punchcard\n{$placeholder}\n");
    app()->useEnvironmentPath($dir);

    $this->artisan('punchcard:documents-key')->assertSuccessful();
    $first = (string) file_get_contents($dir.'/.env');
    $this->artisan('punchcard:documents-key')->assertSuccessful();

    expect($first)->toMatch('/^DOCUMENTS_KEY=base64:[A-Za-z0-9+\/]{43}=$/m')->toContain('APP_NAME=punchcard')
        ->and(substr_count($first, 'DOCUMENTS_KEY='))->toBe(1)
        ->and(file_get_contents($dir.'/.env'))->toBe($first);
})->with(['DOCUMENTS_KEY=', 'DOCUMENTS_KEY=""', 'DOCUMENTS_KEY=changeme']);

it('reads documents under the current key though an old key is malformed, and says so', function (): void {
    Exceptions::fake();
    $stored = app(DocumentCipher::class)->encrypt('the scan');
    config(['punchcard.documents.previous_keys' => 'not-a-key']);

    expect(app(DocumentCipher::class)->decrypt($stored))->toBe('the scan');
    Exceptions::assertReported(DocumentKeyMissing::class);
});

it('offers no upload to a business that is closed, and tells what the server dropped', function (): void {
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['archived_at' => now()])->save());
    expect(app(DescribeVerification::class)->handle($this->tenants->a1)['canSend'])->toBeFalse();

    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['archived_at' => null])->save());
    $dropped = new UploadedFile((string) tempnam(sys_get_temp_dir(), 'doc'), 'id.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
    ($this->send)(DocumentKind::IdDocument, $dropped)->assertSessionHasErrors(['file' => 'The file is larger than this server takes. Try a smaller one.']);
});

it('accepts the documents waiting when the business is verified, deleting them on the day 3 months later', function (): void {
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());
    ($this->send)(DocumentKind::ProofOfAddress, ($this->pdf)('bill.pdf'));

    app(VerifyBusiness::class)->handle($this->tenants->a1, array_map(fn (BusinessDocument $d): int => $d->id, ($this->documents)()));

    expect(array_map(fn (BusinessDocument $d): array => [$d->status, $d->decided_at?->toDateTimeString(), $d->deletes_at?->toDateTimeString()], ($this->documents)()))
        ->toBe(array_fill(0, 2, [DocumentStatus::Accepted, '2026-10-22 10:00:00', '2027-01-22 00:00:00']));
});

it('replaces a rejected one too, row and file', function (): void {
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());
    [$first] = ($this->documents)();
    $this->context->bypass(fn () => $first->forceFill(['status' => DocumentStatus::Rejected, 'decided_at' => now(), 'deletes_at' => now()->addMonths(3)])->save());

    ($this->send)(DocumentKind::IdDocument, ($this->pdf)('sharper.pdf'));

    expect(($this->documents)())->toHaveCount(1)
        ->and(($this->documents)()[0]->status)->toBe(DocumentStatus::Submitted)
        ->and(Storage::disk(BusinessDocument::DISK)->exists($first->path))->toBeFalse();
});

it('refuses an archived business, a kind it doesn\'t know, and a request without a file', function (): void {
    ($this->as)($this->owner)->post('/business/verification/selfie', ['file' => ($this->pdf)()])->assertNotFound();
    ($this->as)($this->owner)->post(route('verification.store', DocumentKind::IdDocument))->assertSessionHasErrors('file');

    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['archived_at' => now()])->save());
    expect(fn () => app(SubmitDocument::class)->handle($this->tenants->a1, $this->owner, DocumentKind::IdDocument, ($this->pdf)()))
        ->toThrow(DocumentRefused::class);

    expect(($this->documents)())->toBe([])
        ->and(Storage::disk(BusinessDocument::DISK)->allFiles())->toBe([]);
});

it('lets the platform admin signed in as the owner read the page, never send', function (): void {
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));
    $this->actingAs($admin);
    app(StartImpersonation::class)->handle($admin, $this->tenants->a1, $this->owner, app('session')->driver());

    $this->get(route('verification.show'))->assertOk();
    $this->post(route('verification.store', DocumentKind::IdDocument), ['file' => ($this->pdf)()])->assertRedirect();

    expect(($this->documents)())->toBe([])
        ->and(Storage::disk(BusinessDocument::DISK)->allFiles())->toBe([]);
});

it('keeps a franchisee to its own business, whatever business its session names', function (): void {
    ($this->as)($this->owner, $this->tenants->a2)->post(route('verification.store', DocumentKind::IdDocument), ['file' => ($this->pdf)()]);

    expect(array_map(fn (BusinessDocument $d): int => $d->business_id, ($this->documents)()))->not->toContain($this->tenants->a2->id);
});

it('shows a verified business nothing to send, and its checklist done without documents', function (): void {
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['status' => BusinessStatus::Verified, 'verified_at' => now()])->save());

    ($this->as)($this->owner)->get(route('verification.show'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('verified', true)->where('canSend', false));
    ($this->as)($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('checklist.documentsSent', true));
});

it('replaces the one waiting, deleting its file, and keeps the other kind', function (): void {
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());
    ($this->send)(DocumentKind::ProofOfAddress, ($this->pdf)('bill.pdf'));
    [$first, $address] = ($this->documents)();

    ($this->send)(DocumentKind::IdDocument, ($this->pdf)('sharper.pdf'));
    $now = ($this->documents)();

    expect(array_map(fn (BusinessDocument $d): int => $d->id, $now))->toContain($address->id)->not->toContain($first->id)
        ->and($now)->toHaveCount(2)
        ->and(Storage::disk(BusinessDocument::DISK)->exists($first->path))->toBeFalse()
        ->and(Storage::disk(BusinessDocument::DISK)->exists($address->path))->toBeTrue();
});

it('keeps an accepted document until its date, whatever is sent after it', function (): void {
    // Pending again (reinstated after a suspension, say) with an ID the platform accepted before.
    $accepted = $this->context->bypass(fn (): BusinessDocument => BusinessDocument::factory()->for($this->tenants->a1)->create([
        'status' => DocumentStatus::Accepted, 'decided_at' => now(), 'deletes_at' => now()->addMonths(3),
    ]));

    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());

    expect(array_map(fn (BusinessDocument $d): int => $d->id, ($this->documents)()))->toContain($accepted->id)->toHaveCount(2);
});

it('refuses what is not a photo or a PDF of 10 MB at most, writing nothing', function (UploadedFile $file): void {
    ($this->send)(DocumentKind::IdDocument, $file)->assertSessionHasErrors('file');

    expect(($this->documents)())->toBe([])
        ->and(Storage::disk(BusinessDocument::DISK)->allFiles())->toBe([]);
})->with([
    // A real upload: its type is read from the content (a fake file's would come from its name).
    'an SVG named .png' => fn (): UploadedFile => documentAsSent('id.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'HTML named .pdf' => fn (): UploadedFile => documentAsSent('id.pdf', '<html><body><script>alert(1)</script></body></html>'),
    'a program' => fn () => UploadedFile::fake()->create('id.exe', 10, 'application/x-msdownload'),
    'a PDF over 10 MB' => fn () => UploadedFile::fake()->create('id.pdf', 10241, 'application/pdf'),
]);

it('takes documents only from whoever runs the business', function (Closure $who, int $status): void {
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)(), $who->call($this))->assertStatus($status);

    expect(($this->documents)())->toBe([]);
})->with([
    'staff' => [fn (): User => $this->tenants->member(User::factory()->create(), $this->tenants->a1), 403],
    'a customer' => [fn (): User => User::factory()->create(), 403],
]);

it('keeps each owner\'s documents to the business they work in', function (): void {
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)())->assertRedirect();

    $this->context->bypass(fn () => $this->tenants->b1->forceFill(['status' => BusinessStatus::Pending, 'verified_at' => null])->save());
    $other = $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner);
    ($this->as)($other, $this->tenants->b1)->post(route('verification.store', DocumentKind::IdDocument), ['file' => ($this->pdf)()])->assertRedirect();

    expect(array_map(fn (BusinessDocument $d): int => $d->business_id, ($this->documents)()))->toBe([$this->tenants->a1->id, $this->tenants->b1->id]);
});

it('takes nothing once the business is verified or suspended', function (BusinessStatus $status): void {
    $this->context->bypass(fn () => $this->tenants->a1->forceFill(['status' => $status])->save());

    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());

    expect(($this->documents)())->toBe([])
        ->and(Storage::disk(BusinessDocument::DISK)->allFiles())->toBe([]);
})->with([BusinessStatus::Verified, BusinessStatus::Suspended]);

it('shows where each document stands, never a file or where it is', function (): void {
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());
    $this->context->bypass(fn () => BusinessDocument::factory()->for($this->tenants->a1)->create([
        'kind' => DocumentKind::ProofOfAddress, 'status' => DocumentStatus::Rejected, 'rejection_reason' => 'The bill is older than 3 months.',
        'decided_at' => now(), 'deletes_at' => now()->addMonths(3),
    ]));

    $response = ($this->as)($this->owner)->get(route('verification.show'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('business/verification')
            ->where('verified', false)
            ->where('canSend', true)
            ->where('retentionMonths', 3)
            ->where('waitingMonths', 12)
            ->where('documents', [
                ['kind' => 'id_document', 'status' => 'submitted', 'sentOn' => '2026-10-22', 'reason' => null, 'deletesOn' => null],
                ['kind' => 'proof_of_address', 'status' => 'rejected', 'sentOn' => '2026-10-22', 'reason' => 'The bill is older than 3 months.', 'deletesOn' => '2027-01-22'],
            ]));

    $path = $this->context->bypass(fn (): string => BusinessDocument::query()->where('kind', DocumentKind::IdDocument)->value('path'));
    expect($response->getContent())->not->toContain($path);
});

it('shows the page to whoever runs the business only', function (): void {
    ($this->as)($this->tenants->member(User::factory()->create(), $this->tenants->a1))->get(route('verification.show'))->assertForbidden();
});

it('ticks the checklist once both are sent, and unticks it when one is asked for again', function (): void {
    $sent = fn (): bool => ($this->as)($this->owner)->get(route('dashboard'))->viewData('page')['props']['checklist']['documentsSent'];

    expect($sent())->toBeFalse();
    ($this->send)(DocumentKind::IdDocument, ($this->pdf)());
    expect($sent())->toBeFalse();
    ($this->send)(DocumentKind::ProofOfAddress, ($this->pdf)('bill.pdf'));
    expect($sent())->toBeTrue();

    $this->context->bypass(fn () => BusinessDocument::query()->where('kind', DocumentKind::IdDocument)->update(['status' => DocumentStatus::Rejected, 'decided_at' => now()]));
    expect($sent())->toBeFalse();
});
