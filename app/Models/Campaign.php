<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\GuardsTenantWrites;
use App\Support\Tenancy\ArchivedSites;
use App\Support\Tenancy\TenantBuilder;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantModel;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A notification a business's owner sends its followers (CHW-42, B9): site
 * data, so a franchisee sees its own, the org admin the organization's. The
 * owner creates it, scheduled (CreateCampaign); its status and counts are its
 * delivery's (DeliverCampaign), in bypass(). Never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $business_id
 * @property int|null $created_by
 * @property string $idempotency_key the composer's, so a double tap on Send is one campaign
 * @property string $title the owner's title, after the business's name in the notification (CampaignText)
 * @property string $body
 * @property int|null $card_id the card it opens, the recipient's own enrollment of it
 * @property CampaignSegment $segment
 * @property CampaignStatus $status
 * @property Carbon $send_at
 * @property Carbon|null $sent_at
 * @property int $audience_count who could get it when it was created: followers in the segment with a phone
 * @property int $sent_count
 * @property int $open_count results (B10, CHW-48); 0 until then
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[UseEloquentBuilder(TenantBuilder::class)]
class Campaign extends Model implements TenantModel
{
    use BelongsToBusiness {
        assertTenantInsert as assertSiteDataInsert;
    }
    use GuardsTenantWrites;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /** @var array<string, mixed> The database defaults, also in memory before a refresh. */
    protected $attributes = ['status' => 'scheduled', 'audience_count' => 0, 'sent_count' => 0, 'open_count' => 0];

    /**
     * Created scheduled and unsent by the owner of an open business; any other status, and what was sent, are its delivery's.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertTenantInsert(array $values): void
    {
        $this->assertSiteDataInsert($values);
        ArchivedSites::assertOpen($values['business_id'] ?? null, null, 'A campaign', lock: true);

        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        $status = $values['status'] ?? CampaignStatus::Scheduled->value;
        $unsent = ($values['sent_at'] ?? null) === null && (int) ($values['sent_count'] ?? 0) === 0 && (int) ($values['open_count'] ?? 0) === 0;

        if (($status instanceof CampaignStatus ? $status : CampaignStatus::tryFrom((string) $status)) !== CampaignStatus::Scheduled || ! $unsent) {
            throw new LogicException('A campaign is created scheduled and unsent; its status and what was sent move with its delivery, in TenantContext::bypass().');
        }
    }

    /**
     * Sent words stay as they were sent, and the counts are its delivery's.
     *
     * @param  'update'|'delete'  $operation
     * @param  array<string, mixed>  $values
     */
    public function assertTenantWrite(string $operation, array $values): void
    {
        if (app(TenantContext::class)->isBypassed()) {
            return;
        }

        throw new LogicException($operation === 'delete'
            ? 'A campaign is kept: a scheduled one is canceled, in TenantContext::bypass().'
            : 'A campaign changes with its delivery only, in TenantContext::bypass().');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segment' => CampaignSegment::class,
            'status' => CampaignStatus::class,
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
