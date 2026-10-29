<?php

declare(strict_types=1);

use App\Support\Database\Outermost;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Work that commits on its own (CHW-310)
|--------------------------------------------------------------------------
|
| The tap Actions and an account deletion run in Outermost::transaction: it
| refuses a caller's transaction, and runs the work again when the database
| aborts it to break a deadlock, rolled back whole each time. No rows: these
| run outside RefreshDatabase, as in production.
|
*/

it('runs the work again when the database picked it as a deadlock victim', function (): void {
    $runs = new stdClass;
    $runs->count = 0;

    $result = Outermost::transaction('Work', function () use ($runs): string {
        $runs->count++;

        if ($runs->count === 1) {
            throw new RuntimeException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        }

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($runs->count)->toBe(2)
        ->and(DB::transactionLevel())->toBe(0);
});

it('gives up after its attempts', function (): void {
    $runs = new stdClass;
    $runs->count = 0;

    expect(fn (): mixed => Outermost::transaction('Work', function () use ($runs): void {
        $runs->count++;

        throw new RuntimeException('deadlock detected');
    }))->toThrow(RuntimeException::class);
    expect($runs->count)->toBe(Outermost::ATTEMPTS);
});

it('never runs work again for any other failure', function (): void {
    $runs = new stdClass;
    $runs->count = 0;

    expect(fn (): mixed => Outermost::transaction('Work', function () use ($runs): void {
        $runs->count++;

        throw new RuntimeException('Card refused');
    }))->toThrow(RuntimeException::class, 'Card refused');
    expect($runs->count)->toBe(1);
});

it('refuses to run inside a caller\'s transaction', function (): void {
    // Tests count one transaction as none (RefreshDatabase's), so two are a caller's.
    DB::beginTransaction();
    DB::beginTransaction();

    try {
        expect(fn (): mixed => Outermost::transaction('Work', fn (): bool => true))
            ->toThrow(LogicException::class, 'Work runs outside any transaction');
    } finally {
        DB::rollBack();
        DB::rollBack();
    }
});
