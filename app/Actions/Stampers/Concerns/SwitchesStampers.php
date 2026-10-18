<?php

declare(strict_types=1);

namespace App\Actions\Stampers\Concerns;

use App\Actions\Admin\RecordAudit;
use App\Enums\StamperStatus;
use App\Models\AuditLog;
use App\Models\NfcTag;
use App\Models\Stamper;
use App\Support\Tenancy\TenantContext;

/**
 * Switching a locked stamper on or off, as the platform admin
 * (SetStamperStatus) and its owner (ChangeStamperStatus) both do: switching
 * off ends an arming, so stamps armed before never land on the first tap
 * after it is on again, and a change of status is audited on the tag. The
 * platform's own columns (its hold) come in $extra.
 *
 * @property-read RecordAudit $recordAudit
 * @property-read TenantContext $context
 */
trait SwitchesStampers
{
    /** @param  array<string, mixed>  $extra */
    private function switchStamper(Stamper $stamper, NfcTag $tag, StamperStatus $status, array $extra = []): void
    {
        $previous = $stamper->status;
        $stamper->forceFill(['status' => $status, ...$extra] + ($status === StamperStatus::Disabled ? Stamper::DISARMED : []))->save();

        if ($previous !== $status) {
            // The audit log is platform data.
            $this->context->bypass(fn (): AuditLog => $this->recordAudit->handle(
                $status === StamperStatus::Disabled ? 'stamper.disabled' : 'stamper.enabled',
                $tag,
                context: ['stamper_id' => $stamper->id],
            ));
        }
    }
}
