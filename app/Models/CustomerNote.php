<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A note on a customer, for the business's team (CHW-44, B5): site data, so
 * a franchisee's are its own and the org admin sees the organization's. The
 * customer never sees it, but gets it in their data export, its author as
 * "staff". Written once, then kept or deleted, never edited.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int $user_id the customer it is about
 * @property int|null $author_id null once the author's account is gone
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['organization_id', 'business_id', 'user_id', 'author_id', 'body'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class CustomerNote extends Model implements TenantModel
{
    use BelongsToBusiness;
    use GuardsTenantWrites;

    public const int MAX_LENGTH = 1000;

    public const ?string UPDATED_AT = null;

    /**
     * Deleted, never edited: what the team wrote stays as written.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if ($operation === 'update') {
            throw new LogicException('A customer note is written once, then kept or deleted.');
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
