<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Reward;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer's request about one of their rewards (CHW-26). The reward is
 * found among their own (Reward::ownedBy), in bypass(): anyone else's, or
 * one that does not exist, is a 404, never a hint that it exists. That scoped
 * lookup is the authorization (no Policy: owning the card is the only right,
 * and a Policy's 403 would confirm the reward exists); OpenRedeemWindow checks
 * ownership again under the lock.
 */
class CustomerRewardRequest extends FormRequest
{
    private ?Reward $reward = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    public function reward(): Reward
    {
        $user = $this->user();
        assert($user instanceof User);

        return $this->reward ??= app(TenantContext::class)->bypass(
            fn (): Reward => Reward::query()->ownedBy($user)->findOrFail((int) $this->route('reward')),
        );
    }
}
