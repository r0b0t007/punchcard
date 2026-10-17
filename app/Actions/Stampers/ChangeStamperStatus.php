<?php

declare(strict_types=1);

namespace App\Actions\Stampers;

use App\Actions\Admin\RecordAudit;
use App\Enums\StamperStatus;
use App\Models\AuditLog;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * An owner switches one of their stampers off at once, or on again (B12,
 * CHW-36): a tap on a disabled stamper is refused (ReceiveTap). With the
 * stamper row locked, as ReceiveTap locks it (after the tag, which this
 * never locks: no cycle), so a tap and the switch never cross. The caller
 * authorizes (StamperPolicy::update): who may switch it depends on its
 * business, which a stamper never changes. Switching off ends an arming. Audited like
 * the platform admin's switch (SetStamperStatus), on the tag, with the
 * owner as the actor; the same status again writes nothing. A stamper the
 * platform holds while its keys are changed (held_at) stays off: switching
 * it on is refused (StamperRefused::held).
 */
final readonly class ChangeStamperStatus
{
    public function __construct(private RecordAudit $recordAudit, private TenantContext $context) {}

    public function handle(Stamper $stamper, StamperStatus $status): Stamper
    {
        return DB::transaction(function () use ($stamper, $status): Stamper {
            $locked = Stamper::query()->current()->whereKey($stamper->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked;
            }

            if ($locked->held_at !== null) {
                throw StamperRefused::held();
            }

            $locked->forceFill(['status' => $status] + ($status === StamperStatus::Disabled ? Stamper::DISARMED : []))->save();

            // The audit log and the tag are platform data.
            $this->context->bypass(fn (): AuditLog => $this->recordAudit->handle(
                $status === StamperStatus::Disabled ? 'stamper.disabled' : 'stamper.enabled',
                NfcTag::query()->findOrFail($locked->nfc_tag_id),
                context: ['stamper_id' => $locked->id],
            ));

            return $locked;
        });
    }
}
