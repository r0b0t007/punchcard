<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Auth\MailedLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A business's page from its emails (CHW-295, MailedLink::toBusiness): the
 * link opens for the user it was mailed to alone (its signed `for`), so one
 * held by anyone else (a former co-owner) never switches another member's
 * business.
 *
 * Anyone else signed in is told so (CHW-147, design 10e): whom it was sent
 * to, half hidden, and who is signed in, with a way to sign out and come
 * back to it as the right account. Nothing about the business is shown. A
 * link whose account is gone is a 404.
 */
final class OpenBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->integer('for') === $this->user()->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): never
    {
        $user = $this->user();
        $recipient = User::query()->find($this->integer('for'));

        if (! $user instanceof User || ! $recipient instanceof User) {
            throw new NotFoundHttpException;
        }

        throw new HttpResponseException(Inertia::render('auth/other-account', [
            'sentTo' => MailedLink::maskedEmail($recipient->email),
            'signedInAs' => $user->email,
            'name' => $user->name,
            // The signed link itself, its own path and query, which "Sign out" posts to (OpenBusinessController).
            'action' => $this->getRequestUri(),
        ])->toResponse($this)->setStatusCode(403));
    }
}
