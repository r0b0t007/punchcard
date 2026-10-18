<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One change of a customer's consent (CHW-58), append-only: never updated.
 * Deleted only with the account (DeleteAccount, or the foreign key's
 * cascade).
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind one of the KIND_ constants
 * @property bool $granted
 * @property string $source one of the SOURCE_ constants
 * @property Carbon $created_at
 */
final class ConsentEvent extends Model
{
    /** News from punchcard itself (users.marketing_opt_in_at). */
    public const string KIND_PUNCHCARD_NEWS = 'punchcard_news';

    public const string SOURCE_SIGN_UP = 'sign_up';

    public const string SOURCE_SETTINGS = 'settings';

    public const null UPDATED_AT = null;

    /**
     * Records a change of consent, dated now.
     */
    public static function record(int $userId, string $kind, bool $granted, string $source): self
    {
        $event = (new self)->forceFill(['user_id' => $userId, 'kind' => $kind, 'granted' => $granted, 'source' => $source]);
        $event->save();

        return $event;
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('A consent change is never rewritten: record a new one.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
