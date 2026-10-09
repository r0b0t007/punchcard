<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Records what the platform admin did (CHW-34) in the audit log: the action,
 * its subject, the reason given and any context, with who did it: the
 * signed-in user's id ("admin #12", never an email: the log can't be changed,
 * and outlives the account), or "console" for a command. Callers run it
 * inside their own transaction, so an action and its record commit, or roll
 * back, together. Never pass key material in the context.
 */
final readonly class RecordAudit
{
    public function __construct(private TenantContext $context) {}

    /** @param  array<string, mixed>  $context */
    public function handle(string $action, Model $subject, ?string $reason = null, array $context = []): AuditLog
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An audit entry is recorded in its action\'s transaction, so the two commit or roll back together.');
        }

        $actor = Auth::user();
        $reason = trim((string) $reason);

        return $this->context->bypass(function () use ($action, $subject, $reason, $context, $actor): AuditLog {
            $entry = (new AuditLog)->forceFill([
                'actor_id' => $actor instanceof User ? $actor->id : null,
                'actor_label' => $actor instanceof User ? 'admin #'.$actor->id : 'console',
                'action' => $action,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'reason' => $reason === '' ? null : $reason,
                'context' => $context === [] ? null : $context,
            ]);
            $entry->save();

            return $entry;
        });
    }
}
