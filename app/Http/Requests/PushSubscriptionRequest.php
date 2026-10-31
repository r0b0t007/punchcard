<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Minishlink\WebPush\ContentEncoding;
use NotificationChannels\WebPush\PushSubscription;

/**
 * A phone's push subscription, as the browser gives it (PushSubscription
 * .toJSON(): endpoint, keys.p256dh, keys.auth), and the encoding it
 * supports. The endpoint is the push service's, always https.
 */
final class PushSubscriptionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::enum(ContentEncoding::class)],
        ];
    }

    public function endpoint(): string
    {
        return $this->string('endpoint')->toString();
    }

    public function publicKey(): string
    {
        return $this->string('keys.p256dh')->toString();
    }

    public function authToken(): string
    {
        return $this->string('keys.auth')->toString();
    }

    public function contentEncoding(): string
    {
        return $this->filled('contentEncoding') ? $this->string('contentEncoding')->toString() : ContentEncoding::aes128gcm->value;
    }
}
