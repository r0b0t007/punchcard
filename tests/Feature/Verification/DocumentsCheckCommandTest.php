<?php

declare(strict_types=1);

use App\Models\BusinessDocument;
use App\Support\Tenancy\TenantContext;
use App\Support\Verification\DocumentCipher;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| punchcard:documents:check
|--------------------------------------------------------------------------
|
| Run after each deploy (docs/runbooks/verification-documents.md), so a
| missing, shared or lost documents key fails the deploy, not an owner's
| upload or the admin's look. It never prints key material.
|
*/

beforeEach(function (): void {
    $this->key = fn (): string => 'base64:'.base64_encode(random_bytes(32));
    $this->current = ($this->key)();
    $this->old = ($this->key)();
    config(['punchcard.documents.key' => $this->current, 'punchcard.documents.previous_keys' => '']);
    $this->tenants = Tenants::make();
    $this->stored = fn (string $key): BusinessDocument => app(TenantContext::class)->bypass(fn (): BusinessDocument => BusinessDocument::factory()
        ->for($this->tenants->a1)->create(['key_id' => DocumentCipher::idOf($key)]));
});

it('passes when every stored document has its key, current or previous', function (): void {
    config(['punchcard.documents.previous_keys' => ' '.$this->old.' ,']);
    ($this->stored)($this->current);
    ($this->stored)($this->old);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('Documents key OK: 1 previous')
        ->doesntExpectOutputToContain($this->current)
        ->doesntExpectOutputToContain($this->old)
        ->assertSuccessful();
});

it('passes with no documents stored yet', function (): void {
    $this->artisan('punchcard:documents:check')->expectsOutputToContain('Documents key OK: 0 previous')->assertSuccessful();
});

it('fails when DOCUMENTS_KEY is missing or not a key', function (?string $key): void {
    config(['punchcard.documents.key' => $key]);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('DOCUMENTS_KEY is missing or not "base64:" and 32 bytes')
        ->assertFailed();
})->with([
    'missing' => [null],
    'empty' => [''],
    'not base64:' => [str_repeat('k', 32)],
    '16 bytes' => ['base64:'.base64_encode(str_repeat('k', 16))],
]);

it('fails when DOCUMENTS_KEY is APP_KEY', function (): void {
    config(['app.key' => $this->current]);

    $this->artisan('punchcard:documents:check')->expectsOutputToContain('DOCUMENTS_KEY is APP_KEY')->assertFailed();
});

it('fails on a previous key that is not a key, naming its place only', function (): void {
    config(['punchcard.documents.previous_keys' => $this->old.',base64:short']);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('Key 2 in DOCUMENTS_PREVIOUS_KEYS is not "base64:" and 32 bytes')
        ->doesntExpectOutputToContain('base64:short')
        ->assertFailed();
});

it('fails when stored documents were encrypted with a key no longer configured', function (): void {
    ($this->stored)($this->current);
    ($this->stored)($this->old);
    ($this->stored)($this->old);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('2 stored documents were encrypted with a key in neither DOCUMENTS_KEY nor DOCUMENTS_PREVIOUS_KEYS')
        ->assertFailed();
});
