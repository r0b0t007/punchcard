<?php

declare(strict_types=1);

use App\Models\User;
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
| rest of the profile; removed by clearing both.
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
        ->assertInertia(fn (Assert $page): Assert => $page->where('birthday', ['day' => 14, 'month' => 3]));
});

it('takes 29 February, a birthday every leap year', function (): void {
    ($this->save)(['birthday_day' => 29, 'birthday_month' => 2])->assertSessionHasNoErrors();

    expect($this->user->refresh()->birthday_day)->toBe(29);
});

it('removes it when both are cleared, and leaves it out when never given', function (): void {
    $this->user->forceFill(['birthday_day' => 14, 'birthday_month' => 3])->save();

    ($this->save)(['birthday_day' => '', 'birthday_month' => ''])->assertSessionHasNoErrors();
    expect($this->user->refresh()->only(['birthday_day', 'birthday_month']))->toBe(['birthday_day' => null, 'birthday_month' => null]);

    ($this->save)([])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->get(route('profile.edit'))->assertInertia(fn (Assert $page): Assert => $page->where('birthday', null));
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

it('keeps a date that does not exist out of the database too', function (): void {
    expect(fn () => DB::table('users')->where('id', $this->user->id)->update(['birthday_day' => 31, 'birthday_month' => 4]))->toThrow(Exception::class)
        ->and(fn () => DB::table('users')->where('id', $this->user->id)->update(['birthday_day' => 14, 'birthday_month' => null]))->toThrow(Exception::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'A Postgres CHECK.');
