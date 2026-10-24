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
        ->expectsOutputToContain('Previous key 1: 1 document.')
        ->expectsOutputToContain('Documents key OK: 1 previous, current key: 1 document.')
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

it('fails when a documents key is APP_KEY or one of its previous keys', function (string $where, Closure $setUp): void {
    $setUp($this);

    $this->artisan('punchcard:documents:check')->expectsOutputToContain($where.' is APP_KEY or one of APP_PREVIOUS_KEYS')->assertFailed();
})->with([
    'the current key, APP_KEY' => ['DOCUMENTS_KEY', fn ($test) => config(['app.key' => $test->current])],
    'the current key, a previous APP_KEY' => ['DOCUMENTS_KEY', fn ($test) => config(['app.previous_keys' => [$test->old, $test->current]])],
    'a previous key, APP_KEY' => ['A key in DOCUMENTS_PREVIOUS_KEYS', fn ($test) => config(['app.key' => $test->old, 'punchcard.documents.previous_keys' => $test->old])],
]);

it('fails on a previous key that is not a key, naming its place as written only', function (): void {
    config(['punchcard.documents.previous_keys' => $this->old.', ,base64:short']);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('Key 3 in DOCUMENTS_PREVIOUS_KEYS is not "base64:" and 32 bytes')
        ->doesntExpectOutputToContain('base64:short')
        ->assertFailed();
});

it('fails when stored documents were encrypted with a key no longer configured', function (): void {
    ($this->stored)($this->current);
    ($this->stored)($this->old);
    ($this->stored)($this->old);

    $lost = ($this->key)();
    ($this->stored)($lost);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('3 documents can\'t be read: the key is in neither DOCUMENTS_KEY nor DOCUMENTS_PREVIOUS_KEYS')
        ->expectsOutputToContain('key id '.DocumentCipher::idOf($this->old).': 2 documents')
        ->expectsOutputToContain('key id '.DocumentCipher::idOf($lost).': 1 document')
        ->doesntExpectOutputToContain('Documents key OK')
        ->assertFailed();
});

it('says how many documents each previous key still covers, so one at 0 can go', function (): void {
    $older = ($this->key)();
    config(['punchcard.documents.previous_keys' => $this->old.','.$older]);
    ($this->stored)($this->old);
    ($this->stored)($this->old);

    $this->artisan('punchcard:documents:check')
        ->expectsOutputToContain('Previous key 1: 2 documents.')
        ->expectsOutputToContain('Previous key 2: 0 documents.')
        ->expectsOutputToContain('Documents key OK: 2 previous, current key: 0 documents.')
        ->assertSuccessful();
});

it('only warns about documents nobody can read after a lost key, and still fails on the rest', function (): void {
    ($this->stored)($this->old);

    $this->artisan('punchcard:documents:check', ['--allow-unreadable' => true])
        ->expectsOutputToContain('1 document can\'t be read')
        ->expectsOutputToContain('Documents key OK')
        ->assertSuccessful();

    config(['app.key' => $this->current]);
    $this->artisan('punchcard:documents:check', ['--allow-unreadable' => true])->expectsOutputToContain('is APP_KEY')->assertFailed();

    config(['punchcard.documents.key' => '']);
    $this->artisan('punchcard:documents:check', ['--allow-unreadable' => true])->expectsOutputToContain('DOCUMENTS_KEY is missing')->assertFailed();
});
