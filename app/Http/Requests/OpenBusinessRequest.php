<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A business's page from its emails (CHW-295, MailedLink::toBusiness): the
 * link names the user it was mailed to (its signed `for`). A link whose
 * account is gone (deleted, or anonymised and kept for the stamp ledger) is
 * a 404. OpenBusinessController opens it for that user alone, so one held by
 * anyone else (a former co-owner) never switches another member's business;
 * they are told whom it is for (CHW-147, design 10e).
 */
final class OpenBusinessRequest extends FormRequest
{
    private ?User $recipient = null;

    public function authorize(): bool
    {
        return $this->recipient instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** The user the link was mailed to, if their account is still in use. */
    public function recipient(): User
    {
        assert($this->recipient instanceof User);

        return $this->recipient;
    }

    /** Whether the one signed in is the user the link was mailed to. */
    public function isForSignedInUser(): bool
    {
        return $this->user() instanceof User && $this->user()->is($this->recipient);
    }

    protected function prepareForValidation(): void
    {
        // From the query string alone, which the signature covers: input() would let a JSON body name anyone.
        $this->recipient = User::query()->notAnonymised()->find($this->query->getInt('for'));
    }

    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }
}
