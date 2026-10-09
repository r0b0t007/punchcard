<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsPlatformData;
use App\Support\Tenancy\PlatformBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One thing the platform admin did (CHW-34): verify, suspend or reinstate a
 * business, register, move, re-key or retire a tag. Platform data: read and
 * written in TenantContext::bypass(), by App\Actions\Admin\RecordAudit only,
 * and never changed or deleted (database triggers). actor_label is "admin
 * #id" or "console" for a command, never an email: an admin with entries is
 * anonymised rather than deleted (DeleteAccount), so actor_id stays valid.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $actor_label
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 */
#[UseEloquentBuilder(PlatformBuilder::class)]
class AuditLog extends Model
{
    use IsPlatformData;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'actor_id' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
