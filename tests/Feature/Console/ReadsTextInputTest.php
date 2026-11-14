<?php

declare(strict_types=1);

use App\Console\Commands\Concerns\ReadsTextInput;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Text from console input (ReadsTextInput)
|--------------------------------------------------------------------------
|
| Text as typed; a number as PHP writes it, as code or a test may pass one;
| a flag, a list or an option left out as no text.
|
*/

beforeEach(function (): void {
    Artisan::registerCommand(new #[Signature('test:reads-text {name} {--label=} {--flag} {--list=*}')] class extends Command
    {
        use ReadsTextInput;

        public function handle(): int
        {
            $this->line((string) json_encode([
                'name' => $this->textArgument('name'),
                'label' => $this->textOption('label'),
                'flag' => $this->textOption('flag'),
                'list' => $this->textOption('list'),
            ]));

            return self::SUCCESS;
        }
    });
});

it('reads text as given, a number as its digits, and a flag or a list as no text', function (array $input, array $read): void {
    $this->artisan('test:reads-text', $input)->expectsOutput((string) json_encode($read))->assertSuccessful();
})->with([
    'text' => [['name' => 'Till 2', '--label' => 'Front'], ['name' => 'Till 2', 'label' => 'Front', 'flag' => null, 'list' => null]],
    'numbers' => [['name' => 5, '--label' => 7], ['name' => '5', 'label' => '7', 'flag' => null, 'list' => null]],
    'a decimal' => [['name' => 5.5], ['name' => '5.5', 'label' => null, 'flag' => null, 'list' => null]],
    'a flag and a list' => [['name' => 'a', '--flag' => true, '--list' => ['x', 'y']], ['name' => 'a', 'label' => null, 'flag' => null, 'list' => null]],
    'an empty option' => [['name' => '', '--label' => ''], ['name' => '', 'label' => '', 'flag' => null, 'list' => null]],
]);
