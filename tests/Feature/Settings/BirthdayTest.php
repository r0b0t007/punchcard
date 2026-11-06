<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| A customer's birthday (CHW-46, C12, design 17a)
|--------------------------------------------------------------------------
|
| Optional, on Settings → Profile: the day and the month, never the year,
| for a birthday treat from the places they collect stamps at. Both or
| neither, and a day that month has (29 February is one). Saved with the
| rest of the profile; removed by clearing both. When it was given is kept
| (CHW-370): a birthday counts for a treat 30 days after it is added or
| changed, so saving the same date again keeps that moment.
|
*/

beforeEach(function (): void {
    $this->user = User::factory()->create(['name' => 'Salma Tazi']);
    $this->save = fn (array $birthday) => $this->actingAs($this->user)->patch(route('profile.update'), [
        'name' => 'Salma Tazi', 'email' => $this->user->email, ...$birthday,
    ]);
});

it('saves the day and the month with the profile, and shows them back', function (): void {
    ($this->save)(['birthday_day' => '14', 'birthday_month' => '3'])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    expect($this->user->refresh()->only(['birthday_day', 'birthday_month']))->toBe(['birthday_day' => 14, 'birthday_month' => 3]);
    $this->actingAs($this->user)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('birthday', ['day' => 14, 'month' => 3])->where('birthdayWaitDays', 30));
});

it('takes 29 February, a birthday every leap year', function (): void {
    ($this->save)(['birthday_day' => 29, 'birthday_month' => 2])->assertSessionHasNoErrors();

    expect($this->user->refresh()->birthday_day)->toBe(29);
});

it('removes it when both are cleared, and leaves it out when never given', function (): void {
    $this->user->forceFill(['birthday_day' => 14, 'birthday_month' => 3, 'birthday_set_at' => now()->subYear()])->save();

    ($this->save)(['birthday_day' => '', 'birthday_month' => ''])->assertSessionHasNoErrors();
    expect($this->user->refresh()->only(['birthday_day', 'birthday_month', 'birthday_set_at']))->toBe(['birthday_day' => null, 'birthday_month' => null, 'birthday_set_at' => null]);

    $this->actingAs($this->user)->get(route('profile.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('birthday', null));
});

it('keeps it when a save sends neither field, as a page from before them does', function (): void {
    $this->user->forceFill(['birthday_day' => 14, 'birthday_month' => 3, 'birthday_set_at' => '2026-01-10 09:00:00'])->save();

    ($this->save)(['name' => 'Salma T.'])->assertSessionHasNoErrors();

    expect($this->user->refresh()->only(['name', 'birthday_day', 'birthday_month']))->toBe(['name' => 'Salma T.', 'birthday_day' => 14, 'birthday_month' => 3])
        ->and($this->user->birthday_set_at?->toDateTimeString())->toBe('2026-01-10 09:00:00');
});

it('keeps when a birthday was given: set by a first one or a different date, kept by the same one', function (): void {
    Carbon::setTestNow('2026-11-06 10:00:00');
    ($this->save)(['birthday_day' => 14, 'birthday_month' => 3])->assertSessionHasNoErrors();
    expect($this->user->refresh()->birthday_set_at?->toDateTimeString())->toBe('2026-11-06 10:00:00');

    // The same date again, a week later: the wait does not start again.
    Carbon::setTestNow('2026-11-13 10:00:00');
    ($this->save)(['birthday_day' => '14', 'birthday_month' => '3'])->assertSessionHasNoErrors();
    expect($this->user->refresh()->birthday_set_at?->toDateTimeString())->toBe('2026-11-06 10:00:00');

    // Another day, or another month: it does.
    Carbon::setTestNow('2026-11-20 10:00:00');
    ($this->save)(['birthday_day' => 15, 'birthday_month' => 3])->assertSessionHasNoErrors();
    expect($this->user->refresh()->birthday_set_at?->toDateTimeString())->toBe('2026-11-20 10:00:00');

    Carbon::setTestNow('2026-11-27 10:00:00');
    ($this->save)(['birthday_day' => 15, 'birthday_month' => 4])->assertSessionHasNoErrors();
    expect($this->user->refresh()->birthday_set_at?->toDateTimeString())->toBe('2026-11-27 10:00:00');

    Carbon::setTestNow();
});

it('refuses a day that month never has, or one without the other, on that one field, and saves nothing', function (array $birthday, string $error): void {
    ($this->save)([...$birthday])
        ->assertSessionHasErrors($error)
        ->assertSessionDoesntHaveErrors($error === 'birthday_day' ? 'birthday_month' : 'birthday_day');

    expect($this->user->refresh()->birthday_day)->toBeNull();
})->with([
    '31 April' => [['birthday_day' => 31, 'birthday_month' => 4], 'birthday_day'],
    '30 February' => [['birthday_day' => 30, 'birthday_month' => 2], 'birthday_day'],
    'a day alone' => [['birthday_day' => 14], 'birthday_month'],
    'a month alone' => [['birthday_month' => 3], 'birthday_day'],
    'no such month' => [['birthday_day' => 1, 'birthday_month' => 13], 'birthday_month'],
    'no such day' => [['birthday_day' => 0, 'birthday_month' => 1], 'birthday_day'],
    'not a number' => [['birthday_day' => 'soon', 'birthday_month' => 1], 'birthday_day'],
]);

it('says why a date that does not exist is refused, in the page\'s language', function (): void {
    $this->user->forceFill(['locale' => 'fr'])->save();

    ($this->save)(['birthday_day' => 31, 'birthday_month' => 4])
        ->assertSessionHasErrors(['birthday_day' => 'Cette date n’existe pas. Choisissez un autre jour.']);
});

it('keeps a date that does not exist, or half of one, out of the database too', function (?int $day, ?int $month): void {
    // Each in its own savepoint: a failed statement aborts the transaction it is in, and every later one with it.
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $this->user->id)->update(['birthday_day' => $day, 'birthday_month' => $month, 'birthday_set_at' => now()])))
        ->toThrow(function (QueryException $refused): void {
            expect($refused->getCode())->toBe('23514');
        });
})->with([
    '31 April' => [31, 4],
    'no such month' => [1, 13],
    'a day alone' => [14, null],
    'a month alone' => [null, 3],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'A Postgres CHECK.');

it('lets a real date, 29 February too, and no birthday at all into the database', function (?int $day, ?int $month): void {
    expect(DB::table('users')->where('id', $this->user->id)->update(['birthday_day' => $day, 'birthday_month' => $month, 'birthday_set_at' => $month === null ? null : now()]))->toBe(1);
})->with([
    '29 February' => [29, 2],
    '31 December' => [31, 12],
    'none' => [null, null],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'A Postgres CHECK.');

it('keeps a birthday and when it was given together in the database', function (?int $month, bool $setAt): void {
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $this->user->id)
        ->update(['birthday_day' => $month === null ? null : 14, 'birthday_month' => $month, 'birthday_set_at' => $setAt ? now() : null])))
        ->toThrow(function (QueryException $refused): void {
            expect($refused->getCode())->toBe('23514');
        });
})->with([
    'a birthday without when' => [3, false],
    'when without a birthday' => [null, true],
])->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'A Postgres CHECK.');
