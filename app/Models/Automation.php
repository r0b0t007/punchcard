<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationTrigger;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantModel;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One of a business's automations (CHW-46, B11): site data, so a franchisee
 * sets up its own and the org admin sees the organization's. At most one per
 * trigger; off until the owner turns it on, and sent only while the platform
 * lets the business use automations (App\Features\Automations). Never
 * deleted, nor its trigger changed, even in bypass(): its sends point at it,
 * and a new one would send each occasion again. Turned off instead.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property AutomationTrigger $trigger
 * @property bool $active
 * @property int $days the reward reminder's: a reward unused this many days
 * @property int $stamps the birthday's bonus stamps, 1 to 3
 * @property string|null $title the owner's title, or null for ours
 * @property string|null $body the owner's message, or null for ours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'trigger', 'active', 'days', 'stamps', 'title', 'body'])]
#[UseEloquentBuilder(TenantBuilder::class)]
class Automation extends Model implements TenantModel
{
    use BelongsToBusiness;
    use GuardsTenantWrites;

    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    /** @var array<string, mixed> The database defaults, also in memory before a refresh. */
    protected $attributes = ['active' => false, 'days' => 7, 'stamps' => 1];

    /**
     * Kept, and kept for its trigger: turned off rather than deleted.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if ($operation === 'delete' || array_key_exists('trigger', $values)) {
            throw new LogicException('An automation is kept for its trigger: turn it off instead.');
        }
    }

    /**
     * @return HasMany<AutomationSend, $this>
     */
    public function sends(): HasMany
    {
        return $this->hasMany(AutomationSend::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => AutomationTrigger::class,
            'active' => 'boolean',
            'days' => 'integer',
            'stamps' => 'integer',
        ];
    }
}
