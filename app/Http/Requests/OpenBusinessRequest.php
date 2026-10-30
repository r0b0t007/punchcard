<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A business's page from its emails (CHW-295, MailedLink::toBusiness): the
 * link opens for the user it was mailed to alone (its signed `for`), so one
 * held by anyone else (a former co-owner) never switches another member's
 * business. Anyone else gets a 404, as for a business they don't work in.
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
        throw new NotFoundHttpException;
    }
}
