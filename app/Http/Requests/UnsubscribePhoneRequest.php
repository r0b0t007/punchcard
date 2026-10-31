<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use NotificationChannels\WebPush\PushSubscription;

/** A phone's notifications turned off (CHW-41): its push subscription's endpoint, as the browser gives it. */
final class UnsubscribePhoneRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH]];
    }

    public function endpoint(): string
    {
        return $this->string('endpoint')->toString();
    }
}
