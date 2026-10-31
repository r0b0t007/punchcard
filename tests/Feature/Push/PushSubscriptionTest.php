<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Models\User;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\Support\Tenants;

/*
|--------------------------------------------------------------------------
| Notifications on this phone (CHW-41)
|--------------------------------------------------------------------------
|
| A phone the customer turned notifications on for keeps one push
| subscription (its endpoint). It is theirs alone: the same phone signed in
| as someone else moves to them, turning off removes only their own, and
| the push service's "gone" (404/410) deletes it. Settings say whether this
| phone can have them, and whether rewards ready are wanted.
|
*/

beforeEach(function (): void {
    app()->setLocale('en');
    $this->customer = User::factory()->create();
    $this->subscription = fn (string $endpoint = 'https://fcm.googleapis.com/fcm/send/phone-1'): array => [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BPublicKeyOfThePhone', 'auth' => 'AuthSecret'],
        'contentEncoding' => 'aes128gcm',
    ];
    $this->phones = fn (?User $user = null): array => PushSubscription::query()
        ->when($user, fn ($query) => $query->where('subscribable_id', $user->id))
        ->orderBy('id')->pluck('endpoint')->all();
});

it('keeps one subscription per phone, its keys updated when it subscribes again', function (): void {
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)())->assertNoContent();
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), [...($this->subscription)(), 'keys' => ['p256dh' => 'BNewKey', 'auth' => 'NewSecret']])->assertNoContent();
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)('https://web.push.apple.com/phone-2'))->assertNoContent();

    expect(($this->phones)($this->customer))->toBe(['https://fcm.googleapis.com/fcm/send/phone-1', 'https://web.push.apple.com/phone-2'])
        ->and(PushSubscription::query()->where('endpoint', 'https://fcm.googleapis.com/fcm/send/phone-1')->value('public_key'))->toBe('BNewKey');
});

it('gives a phone to whoever turned notifications on there last', function (): void {
    $before = User::factory()->create();
    $this->actingAs($before)->postJson(route('push.subscribe'), ($this->subscription)());

    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)())->assertNoContent();

    expect(($this->phones)($before))->toBe([])->and(($this->phones)($this->customer))->toBe(['https://fcm.googleapis.com/fcm/send/phone-1']);
});

it('takes a phone another account turned on in the same instant', function (): void {
    $other = User::factory()->create();
    $raced = new stdClass;
    $raced->done = false;
    // Between this account's look for the endpoint and its insert, the other account's row lands (the unique index refuses a second).
    PushSubscription::creating(function () use ($other, $raced): void {
        if (! $raced->done) {
            $raced->done = true;
            DB::table('push_subscriptions')->insert(['subscribable_type' => $other->getMorphClass(), 'subscribable_id' => $other->id,
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/phone-1', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)())->assertNoContent();

    expect(($this->phones)($this->customer))->toBe(['https://fcm.googleapis.com/fcm/send/phone-1'])->and(($this->phones)($other))->toBe([]);
});

it('refuses what is not a browser\'s push subscription', function (array $change, string $field): void {
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), array_replace_recursive(($this->subscription)(), $change))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(($this->phones)())->toBe([]);
})->with([
    'an http endpoint' => [['endpoint' => 'http://fcm.googleapis.com/fcm/send/phone-1'], 'endpoint'],
    'no endpoint' => [['endpoint' => ''], 'endpoint'],
    'no public key' => [['keys' => ['p256dh' => '']], 'keys.p256dh'],
    'no auth secret' => [['keys' => ['auth' => '']], 'keys.auth'],
    'an unknown encoding' => [['contentEncoding' => 'gzip'], 'contentEncoding'],
]);

it('turns off only the customer\'s own phone', function (): void {
    $other = User::factory()->create();
    $this->actingAs($other)->postJson(route('push.subscribe'), ($this->subscription)('https://web.push.apple.com/theirs'));
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)());

    $this->actingAs($this->customer)->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://web.push.apple.com/theirs'])->assertNoContent();
    $this->actingAs($this->customer)->deleteJson(route('push.unsubscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/phone-1'])->assertNoContent();
    $this->actingAs($this->customer)->deleteJson(route('push.unsubscribe'), ['endpoint' => ''])->assertUnprocessable()->assertJsonValidationErrors('endpoint');

    expect(($this->phones)())->toBe(['https://web.push.apple.com/theirs']);
});

it('subscribes nobody signed out, and no account being deleted', function (): void {
    $this->postJson(route('push.subscribe'), ($this->subscription)())->assertUnauthorized();

    $this->customer->forceFill(['anonymised_at' => now()])->save();
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)())->assertNoContent();

    expect(($this->phones)())->toBe([]);
});

it('forgets a phone the push service says is gone', function (int $status, bool $kept): void {
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)());
    // The push service answers with $status (the channel's own WebPush is bound for it alone, so it is replaced whole).
    $webPush = Mockery::mock(WebPush::class);
    $webPush->shouldReceive('queueNotification')->once();
    $webPush->shouldReceive('flush')->once()->andReturnUsing(function () use ($status): Generator {
        yield new MessageSentReport(new PsrRequest('POST', 'https://fcm.googleapis.com/fcm/send/phone-1'), new PsrResponse($status), $status < 300);
    });
    $this->app->instance(WebPushChannel::class, new WebPushChannel($webPush, app(ReportHandler::class)));

    $this->customer->notifyNow(new class extends Notification
    {
        /** @return list<class-string> */
        public function via(object $notifiable): array
        {
            return [WebPushChannel::class];
        }

        public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
        {
            return (new WebPushMessage)->title('Café Hafa')->body('Your reward is ready');
        }
    });

    expect(($this->phones)())->toBe($kept ? ['https://fcm.googleapis.com/fcm/send/phone-1'] : []);
})->with([
    'gone (410)' => [410, false],
    'not found (404)' => [404, false],
    'delivered (201)' => [201, true],
    'a passing failure (500)' => [500, true],
]);

it('lets the customer choose rewards ready, and tells settings what this phone can have', function (): void {
    config(['webpush.vapid.public_key' => null]);
    $this->actingAs($this->customer)->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('push', ['publicKey' => null, 'rewards' => true, 'phones' => []]));

    // Half a key pair can only fail: nothing offered either.
    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => null]);
    $this->actingAs($this->customer)->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('push.publicKey', null));

    // The private half from a PEM file instead.
    config(['webpush.vapid.pem_file' => '/keys/vapid.pem']);
    $this->actingAs($this->customer)->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('push.publicKey', 'BAppPublicKey'));

    config(['webpush.vapid.public_key' => 'BAppPublicKey', 'webpush.vapid.private_key' => 'app-private-key', 'webpush.vapid.pem_file' => null]);
    $this->actingAs($this->customer)->patch(route('privacy.notifications'), ['rewards' => false])->assertRedirect(route('privacy.edit'));
    $this->actingAs($this->customer)->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('push', ['publicKey' => 'BAppPublicKey', 'rewards' => false, 'phones' => []]));

    $this->actingAs($this->customer)->patch(route('privacy.notifications'), ['rewards' => 'maybe'])->assertSessionHasErrors('rewards');
    expect($this->customer->fresh()?->push_rewards)->toBeFalse();
});

it('tells settings which phones are the customer\'s own, never their addresses', function (): void {
    $other = User::factory()->create();
    $this->actingAs($other)->postJson(route('push.subscribe'), ($this->subscription)('https://web.push.apple.com/theirs'));
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)());

    // A phone reads "On" only when it is in this list: the same phone signed in as someone else reads "Off" (lib/push.ts).
    $this->actingAs($this->customer)->get(route('privacy.edit'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('push.phones', [hash('sha256', 'https://fcm.googleapis.com/fcm/send/phone-1')]));
});

it('forgets every phone of a deleted account, with history or without', function (bool $history): void {
    $this->actingAs($this->customer)->postJson(route('push.subscribe'), ($this->subscription)());

    if ($history) {
        $tenants = Tenants::make();
        $tenants->stamp($tenants->enroll($this->customer, $tenants->cardA), $tenants->a1);
    }

    app(DeleteAccount::class)->handle($this->customer);

    expect(($this->phones)())->toBe([]);
})->with(['no history (deleted)' => false, 'history (anonymised)' => true]);
