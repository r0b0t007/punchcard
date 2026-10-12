<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CardEnrollment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One of the signed-in customer's cards (CHW-27, C6), read in bypass() and
 * kept to their own: anyone else's is a 404, never a 403 that would say it
 * exists (as CustomerRewardRequest).
 */
class MyCardRequest extends FormRequest
{
    private ?CardEnrollment $enrollment = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    public function enrollment(): CardEnrollment
    {
        $user = $this->user();
        assert($user instanceof User);

        return $this->enrollment ??= app(TenantContext::class)->bypass(
            fn (): CardEnrollment => CardEnrollment::query()->where('user_id', $user->id)->findOrFail((int) $this->route('enrollment')),
        );
    }
}
