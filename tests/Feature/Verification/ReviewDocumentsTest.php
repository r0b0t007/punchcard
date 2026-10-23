<?php

declare(strict_types=1);

use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\VerifyBusiness;
use App\Actions\Verification\DocumentRefused;
use App\Actions\Verification\ReadDocument;
use App\Actions\Verification\RequestNewDocuments;
use App\Actions\Verification\SubmitDocument;
use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\PlatformRole;
use App\Filament\Resources\Businesses\Pages\ViewBusiness;
use App\Http\Middleware\SetTenant;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\User;
use App\Notifications\NewDocumentsNeededMail;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Reviewing verification documents in A1 (CHW-145)
|--------------------------------------------------------------------------
|
| The business's page in the admin panel lists its documents, each with a
| link that works for 5 minutes and only for the platform admin: opening it
| shows the decrypted file, never cached, never run (images inline, any
| other type saved), and writes an audit entry. Anyone else, the business's
| own owner included, an expired or changed link, and an admin signed in
| as an owner, get nothing. "Ask for new documents" marks the chosen
| waiting documents rejected with a reason, dated for deletion, audited,
| and emails the owners in their language.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 10:00:00');
    app()->setLocale('en');
    Storage::fake(BusinessDocument::DISK);
    Notification::fake();
    config(['punchcard.documents.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'punchcard.documents.previous_keys' => '']);
    $this->tenants = Tenants::make();
    $this->context = app(TenantContext::class);
    $this->business = $this->tenants->a1;
    $this->context->bypass(fn () => $this->business->forceFill(['status' => BusinessStatus::Pending, 'verified_at' => null])->save());
    $this->owner = $this->tenants->member(User::factory()->create(['name' => 'Yasmine Alaoui', 'locale' => 'fr']), $this->business, BusinessRole::Owner);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->admin->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web'));

    $this->send = function (DocumentKind $kind, string $name = 'id.png', ?string $bytes = null): BusinessDocument {
        $bytes ??= base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $path = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $bytes);

        return app(SubmitDocument::class)->handle($this->business, $this->owner, $kind, new UploadedFile($path, $name, null, null, true));
    };
    $this->link = fn (BusinessDocument $document): string => ReadDocument::link($document);
    $this->audits = fn (string $action): array => $this->context->bypass(fn (): array => AuditLog::query()->where('action', $action)->orderBy('id')
        ->get(['actor_id', 'subject_type', 'subject_id', 'reason', 'context'])->map(fn (AuditLog $entry): array => $entry->only(['actor_id', 'subject_type', 'subject_id', 'reason', 'context']))->all());
    $this->fresh = fn (BusinessDocument $document): BusinessDocument => $this->context->bypass(fn (): BusinessDocument => $document->fresh() ?? throw new LogicException('Gone.'));
    $this->panel = function (): void {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Filament::setServingStatus();
    };
    // As the panel's requests run: in bypass (PlatformAdminWorksAcrossTenants), each call on the page too.
    $this->page = fn (Closure $then): mixed => $this->context->bypass(fn (): mixed => $then(Livewire::test(ViewBusiness::class, ['record' => $this->business->getRouteKey()])));
});

afterEach(function (): void {
    Filament::setServingStatus(false);
});

describe('opening a document', function (): void {
    it('shows the admin the decrypted image, inline, never cached or run, and records it', function (): void {
        $document = ($this->send)(DocumentKind::IdDocument);

        $response = $this->actingAs($this->admin)->get(($this->link)($document))->assertOk();

        expect($response->getContent())->toBe(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='))
            ->and($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="id_document-'.$this->business->id.'.png"')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox")
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
            ->and(($this->audits)('document.viewed'))->toBe([[
                'actor_id' => $this->admin->id, 'subject_type' => 'business', 'subject_id' => $this->business->id, 'reason' => null,
                'context' => ['document_id' => $document->id, 'kind' => 'id_document'],
            ]]);
    });

    it('saves a PDF to open outside the browser, never inline', function (): void {
        $document = ($this->send)(DocumentKind::ProofOfAddress, 'bill.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $response = $this->actingAs($this->admin)->get(($this->link)($document))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="proof_of_address-'.$this->business->id.'.pdf"')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('sends nothing and records nothing for a HEAD request', function (): void {
        $document = ($this->send)(DocumentKind::IdDocument);

        $this->actingAs($this->admin)->call('HEAD', ($this->link)($document))->assertStatus(405);
        expect(($this->audits)('document.viewed'))->toBe([]);
    });

    it('refuses anyone else, the business\'s own owner included, and records nothing', function (?string $who): void {
        $document = ($this->send)(DocumentKind::IdDocument);
        $user = match ($who) {
            'owner' => $this->owner,
            'staff' => $this->tenants->member(User::factory()->create(), $this->business, BusinessRole::Staff),
            'org admin' => $this->tenants->admin(User::factory()->create(), $this->tenants->orgA),
            'other owner' => $this->tenants->member(User::factory()->create(), $this->tenants->b1, BusinessRole::Owner),
            'admin without two-factor' => tap(User::factory()->create())->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web')),
            'admin with an unverified email' => tap(User::factory()->withTwoFactor()->unverified()->create())->assignRole(Role::findOrCreate(PlatformRole::Admin->value, 'web')),
            'customer' => User::factory()->create(),
            default => null,
        };

        $response = ($user === null ? $this : $this->actingAs($user))->get(($this->link)($document));

        $user === null ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        expect(($this->audits)('document.viewed'))->toBe([]);
    })->with(['owner', 'staff', 'org admin', 'other owner', 'admin without two-factor', 'admin with an unverified email', 'customer', 'guest' => [null]]);

    it('refuses a link that expired or was changed', function (): void {
        $document = ($this->send)(DocumentKind::IdDocument);
        $other = ($this->send)(DocumentKind::ProofOfAddress);
        $link = ($this->link)($document);
        $this->actingAs($this->admin);

        $this->get(str_replace('/'.$document->id.'?', '/'.$other->id.'?', $link))->assertForbidden();
        $this->get(route('documents.view', $document))->assertForbidden();
        $this->travel(ReadDocument::LINK_MINUTES)->minutes();
        $this->travel(1)->second();
        $this->get($link)->assertForbidden();
        expect(($this->audits)('document.viewed'))->toBe([]);
    });

    it('refuses the admin while they are signed in as an owner', function (): void {
        $document = ($this->send)(DocumentKind::IdDocument);
        $this->actingAs($this->admin);
        app(StartImpersonation::class)->handle($this->admin, $this->business, $this->owner, app('session')->driver());

        $this->get(($this->link)($document))->assertRedirect(route('dashboard'));
        expect(($this->audits)('document.viewed'))->toBe([]);
    });

    it('is not found once deleted, file or row', function (): void {
        $document = ($this->send)(DocumentKind::IdDocument);
        $link = ($this->link)($document);
        Storage::disk(BusinessDocument::DISK)->delete($document->path);

        $this->actingAs($this->admin)->get($link)->assertNotFound();
        $this->context->bypass(fn () => $document->delete());
        $this->get($link)->assertNotFound();
        expect(($this->audits)('document.viewed'))->toBe([]);
    });
});

describe('asking for new documents', function (): void {
    it('rejects the chosen waiting documents with the reason, dates their deletion and records which, never why', function (): void {
        $id = ($this->send)(DocumentKind::IdDocument);
        $address = ($this->send)(DocumentKind::ProofOfAddress);
        $this->actingAs($this->admin);

        app(RequestNewDocuments::class)->handle($this->business, [$address->id], '  The bill is older than 3 months.  ');

        expect(($this->fresh)($address)->only(['status', 'rejection_reason']))->toBe(['status' => DocumentStatus::Rejected, 'rejection_reason' => 'The bill is older than 3 months.'])
            ->and(($this->fresh)($address)->decided_at?->toDateTimeString())->toBe('2026-10-23 10:00:00')
            ->and(($this->fresh)($address)->deletes_at?->toDateTimeString())->toBe('2027-01-23 00:00:00')
            ->and(($this->fresh)($id)->status)->toBe(DocumentStatus::Submitted)
            ->and(($this->fresh)($id)->deletes_at)->toBeNull()
            ->and(($this->audits)('documents.rejected'))->toBe([[
                'actor_id' => $this->admin->id, 'subject_type' => 'business', 'subject_id' => $this->business->id, 'reason' => null,
                'context' => ['document_ids' => [$address->id], 'kinds' => ['proof_of_address']],
            ]]);
    });

    it('emails the owners which documents and why, in their language, with the day the unusable ones go', function (): void {
        $id = ($this->send)(DocumentKind::IdDocument);
        $address = ($this->send)(DocumentKind::ProofOfAddress);
        $english = $this->tenants->member(User::factory()->create(['name' => '[Omar](https://evil.test) Idrissi', 'locale' => 'en']), $this->business, BusinessRole::Owner);
        $staff = $this->tenants->member(User::factory()->create(), $this->business, BusinessRole::Staff);
        $this->actingAs($this->admin);

        $this->context->bypass(fn () => Business::query()->whereKey($this->business->id)->update(['name' => 'Café Hafa']));
        app(RequestNewDocuments::class)->handle($this->business, [$id->id, $address->id], "Both scans\nare too blurry [here](https://evil.test).");

        Notification::assertNotSentTo($staff, NewDocumentsNeededMail::class);
        Notification::assertSentTo($this->owner, NewDocumentsNeededMail::class);
        Notification::assertSentTo($english, function (NewDocumentsNeededMail $mail) use ($english): bool {
            $message = $mail->toMail($english);

            expect($message->subject)->toBe('We need new documents to verify Café Hafa')
                ->and($message->greeting)->toBe('Hello \[Omar\](https://evil.test),')
                ->and($message->introLines)->toBe([
                    'We couldn\'t verify Café Hafa with the ID and the proof of address you sent. Please send new ones.',
                    "Our reason: “\u{2068}Both scans are too blurry \\[here\\](https://evil.test).\u{2069}”",
                ])
                ->and($message->actionText)->toBe('Send new documents')
                ->and($message->actionUrl)->toBe(route('verification.show'))
                ->and($message->outroLines)->toBe(['Café Hafa keeps working while we check. The documents we couldn\'t use are deleted as soon as you send new ones, or on 23 January at the latest.']);

            return true;
        });

        app()->setLocale('fr');
        $french = (new NewDocumentsNeededMail($this->business->name, [DocumentKind::ProofOfAddress], 'Trop ancienne.', Carbon::parse('2027-01-23 00:00:00')->toImmutable(), 'Africa/Casablanca'))->toMail($this->owner);
        expect($french->subject)->toBe('Il nous faut un nouveau document pour vérifier '.$this->business->name)
            ->and($french->outroLines[0])->toContain('23 janvier');
    });

    it('refuses unless every chosen document is the business\'s own and still waiting, with a reason', function (): void {
        $id = ($this->send)(DocumentKind::IdDocument);
        $address = ($this->send)(DocumentKind::ProofOfAddress);
        $elsewhere = $this->context->bypass(fn (): BusinessDocument => BusinessDocument::factory()->for($this->tenants->b1)->create());
        $this->actingAs($this->admin);
        $request = fn (array $ids, string $reason = 'Blurry.'): Closure => fn () => app(RequestNewDocuments::class)->handle($this->business, $ids, $reason);

        expect($request([]))->toThrow(DocumentRefused::class)
            ->and($request([$id->id], '  '))->toThrow(DocumentRefused::class)
            ->and($request([$id->id, $elsewhere->id]))->toThrow(DocumentRefused::class)
            ->and(($this->fresh)($elsewhere)->status)->toBe(DocumentStatus::Submitted);

        // Replaced since the admin's page was read: the new one is never rejected unseen.
        $newAddress = ($this->send)(DocumentKind::ProofOfAddress);
        expect($request([$address->id]))->toThrow(DocumentRefused::class, 'These documents changed since you opened this page. Reload it and look again.')
            ->and(($this->fresh)($newAddress)->status)->toBe(DocumentStatus::Submitted);

        app(RequestNewDocuments::class)->handle($this->business, [$newAddress->id], 'Too old.');
        expect($request([$newAddress->id]))->toThrow(DocumentRefused::class)
            ->and(($this->fresh)($newAddress)->rejection_reason)->toBe('Too old.')
            ->and(($this->audits)('documents.rejected'))->toHaveCount(1)
            ->and(($this->audits)('documents.rejected')[0]['context'])->toBe(['document_ids' => [$newAddress->id], 'kinds' => ['proof_of_address']]);
        Notification::fake();

        foreach ([['status' => BusinessStatus::Suspended], ['status' => BusinessStatus::Verified], ['archived_at' => now()]] as $closed) {
            $this->context->bypass(fn () => $this->business->forceFill($closed)->save());
            expect($request([$id->id]))->toThrow(DocumentRefused::class, 'Only a business waiting to be verified can be asked for new documents.')
                ->and(($this->fresh)($id)->status)->toBe(DocumentStatus::Submitted);
            $this->context->bypass(fn () => $this->business->forceFill(['status' => BusinessStatus::Pending, 'archived_at' => null])->save());
        }

        app(VerifyBusiness::class)->handle($this->business);

        expect($request([$id->id]))->toThrow(DocumentRefused::class)
            ->and(($this->fresh)($id)->status)->toBe(DocumentStatus::Accepted)
            ->and(($this->audits)('documents.rejected'))->toHaveCount(1);
        Notification::assertNothingSentTo($this->owner, NewDocumentsNeededMail::class);
    });

    it('shows the owner the reason, and a new upload replaces the rejected one', function (): void {
        $address = ($this->send)(DocumentKind::ProofOfAddress);
        $this->actingAs($this->admin);
        app(RequestNewDocuments::class)->handle($this->business, [$address->id], 'The bill is older than 3 months.');

        $this->actingAs($this->owner)->withSession([SetTenant::SESSION_KEY => 'business:'.$this->business->id])
            ->get(route('verification.show'))->assertInertia(fn ($page) => $page
            ->where('documents.1', ['kind' => 'proof_of_address', 'status' => 'rejected', 'sentOn' => '2026-10-23', 'reason' => 'The bill is older than 3 months.', 'deletesOn' => '2027-01-23']));

        $new = ($this->send)(DocumentKind::ProofOfAddress);
        expect($this->context->bypass(fn (): array => BusinessDocument::query()->where('kind', DocumentKind::ProofOfAddress)->pluck('id')->all()))->toBe([$new->id]);
        Storage::disk(BusinessDocument::DISK)->assertMissing($address->path);
    });
});

describe('the business\'s page in A1', function (): void {
    it('lists its documents, each with a link that opens it', function (): void {
        $document = ($this->send)(DocumentKind::IdDocument);
        ($this->panel)();

        ($this->page)(fn (Testable $page): Testable => $page->assertSee('Verification documents')->assertSee('ID card or passport')->assertSee('Waiting')
            ->assertSee(e(ReadDocument::link($document)), false));
    });

    it('asks for new documents, offered only while some wait on a pending business', function (): void {
        ($this->panel)();
        ($this->page)(fn (Testable $page): Testable => $page->assertActionHidden('requestNewDocuments')->assertSee('None sent'));

        $address = ($this->send)(DocumentKind::ProofOfAddress);
        ($this->page)(fn (Testable $page): Testable => $page->assertActionVisible('requestNewDocuments')
            ->callAction('requestNewDocuments', ['documents' => [], 'reason' => ''])->assertHasActionErrors(['documents' => 'required', 'reason' => 'required']));

        ($this->page)(fn (Testable $page): Testable => $page->callAction('requestNewDocuments', ['documents' => [$address->id], 'reason' => 'Older than 3 months.'])
            ->assertHasNoActionErrors()->assertNotified()->assertActionHidden('requestNewDocuments'));

        expect(($this->fresh)($address)->status)->toBe(DocumentStatus::Rejected);
        Notification::assertSentTo($this->owner, NewDocumentsNeededMail::class);

        ($this->send)(DocumentKind::ProofOfAddress);
        $this->context->bypass(fn () => $this->business->forceFill(['archived_at' => now()])->save());
        ($this->page)(fn (Testable $page): Testable => $page->assertActionHidden('requestNewDocuments'));
    });
});
