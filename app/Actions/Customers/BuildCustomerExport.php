<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerStatus;
use App\Enums\StampSource;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\Follow;
use App\Models\Location;
use App\Models\LoyaltyCard;
use App\Models\StampEvent;
use App\Models\User;
use App\Support\LocalMoment;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;
use ZipArchive;

/**
 * A business's customers and stamps for some days, as CSV (CHW-49, design 21): what its owner may already see, and
 * no more. Customers as on B4 (first name and initial, never an email, no birthday), those with a visit here in the
 * range; every stamp given here in the range, with who gave it as B5 names them. Nothing from another business, even
 * a sister franchisee: every query is limited to this business (in bypass(), as the job has no user).
 *
 * The range is the business's own days (its first open site's time); each stamp's time is its site's. Headers and
 * labels in the owner's language. UTF-8 with a BOM, for spreadsheets to read Arabic; a cell a spreadsheet would run
 * as a formula (=, +, -, @, a tab or a return first) starts with a quote.
 *
 * Rows are read a chunk at a time, with the names, follows and cards for that chunk in a few queries, and written to
 * temporary files, so a busy business's years fit in the job's time and memory. The two files go in a zip, encrypted
 * (Crypt, APP_KEY) on the `exports` disk, never served (DownloadCustomerExport reads it back), and deleted after a day
 * (DeleteExpiredExports).
 */
final readonly class BuildCustomerExport
{
    use Localizable;

    public const string DISK = 'exports';

    /** A cell starting with one of these is a formula to a spreadsheet. */
    private const array FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    private const int CHUNK = 1000;

    public function __construct(private TenantContext $context) {}

    /** Where an export is kept: by business, named by its id alone. */
    public static function path(int $businessId, string $export): string
    {
        return $businessId.'/'.$export.'.zip.enc';
    }

    /**
     * @return array{export: string, customers: int, stamps: int}
     */
    public function handle(int $businessId, string $from, string $to, string $locale, CarbonImmutable $now): array
    {
        return $this->withLocale($locale, fn (): array => $this->context->bypass(function () use ($businessId, $from, $to, $now): array {
            $timezone = LocalMoment::timezoneOfBusiness($businessId);
            $start = LocalMoment::dayStart($timezone, CarbonImmutable::parse($from, $timezone));
            $end = LocalMoment::dayEnd($timezone, CarbonImmutable::parse($to, $timezone));
            $export = (string) Str::ulid();
            $temporary = [];

            try {
                $temporary[] = $customersCsv = $this->temporary();
                $temporary[] = $stampsCsv = $this->temporary();
                $temporary[] = $zip = $this->temporary();

                $customers = $this->customers($customersCsv, $businessId, $start, $end, $timezone, $now);
                $stamps = $this->stamps($stampsCsv, $businessId, $start, $end);

                $archive = new ZipArchive;

                if ($archive->open($zip, ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('The export could not be zipped.');
                }

                $archive->addFile($customersCsv, 'customers.csv');
                $archive->addFile($stampsCsv, 'stamps.csv');

                if (! $archive->close()) {
                    throw new RuntimeException('The export could not be zipped.');
                }

                Storage::disk(self::DISK)->put(self::path($businessId, $export), Crypt::encryptString((string) file_get_contents($zip)));
            } finally {
                foreach ($temporary as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }

            return ['export' => $export, 'customers' => $customers, 'stamps' => $stamps];
        }));
    }

    /**
     * The business's customers with a visit (a presence stamp) here in the range; how many.
     */
    private function customers(string $path, int $businessId, CarbonImmutable $start, CarbonImmutable $end, string $timezone, CarbonImmutable $now): int
    {
        $csv = $this->open($path, [__('Reference'), __('Name'), __('Visits'), __('First visit'), __('Last visit'), __('Usual gap (days)'),
            __('Status'), __('Rewards redeemed'), __('Follows for offers')]);
        $organizationId = (int) Business::query()->whereKey($businessId)->value('organization_id');
        $visitors = CardEnrollment::query()->select('user_id')->whereIn('id', StampEvent::query()->select('enrollment_id')
            ->where('business_id', $businessId)->whereIn('source', StampSource::presenceValues())
            ->where('created_at', '>=', $start)->where('created_at', '<', $end));
        $count = 0;

        foreach (BusinessCustomer::query()->where('business_id', $businessId)->whereIn('user_id', $visitors)->lazyById(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $userIds = $chunk->pluck('user_id')->all();
            $names = $this->names($userIds);
            $followers = Follow::query()->where('organization_id', $organizationId)->whereIn('user_id', $userIds)->where('notify', true)
                ->pluck('user_id')->flip();

            foreach ($chunk as $customer) {
                $this->row($csv, [
                    $customer->id,
                    $names[$customer->user_id] ?? '',
                    $customer->visits,
                    $customer->first_visit_at->setTimezone($timezone)->format('Y-m-d'),
                    $customer->last_visit_at->setTimezone($timezone)->format('Y-m-d'),
                    $customer->usual_gap_days ?? '',
                    match ($customer->status($now)) {
                        CustomerStatus::Lost => __('Lost'),
                        CustomerStatus::Quiet => __('Going quiet'),
                        CustomerStatus::Active => __('Active'),
                    },
                    $customer->rewards_redeemed,
                    $followers->has($customer->user_id) ? __('Yes') : __('No'),
                ]);
                $count++;
            }
        }

        fclose($csv);

        return $count;
    }

    /**
     * Every stamp given here in the range, oldest first, in its site's time; how many.
     */
    private function stamps(string $path, int $businessId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $csv = $this->open($path, [__('Date'), __('Time'), __('Site'), __('Card'), __('Customer reference'), __('How'), __('Stamps'),
            __('Reason'), __('Stamped by')]);
        $sites = Location::query()->where('business_id', $businessId)->get(['id', 'name', 'timezone'])->keyBy('id');
        /** @var array<int, array{reference: int|null, card: string}> $enrollments */
        $enrollments = [];
        /** @var array<int, string> $cardNames */
        $cardNames = [];
        /** @var array<int, string> $staff */
        $staff = [];
        $count = 0;

        foreach (StampEvent::query()->where('business_id', $businessId)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->lazyById(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            $newEnrollments = CardEnrollment::query()->whereKey($chunk->pluck('enrollment_id')->unique()->diff(array_keys($enrollments))->values())
                ->get(['id', 'user_id', 'card_id']);
            $references = BusinessCustomer::query()->where('business_id', $businessId)->whereIn('user_id', $newEnrollments->pluck('user_id')->unique())
                ->pluck('id', 'user_id');
            $cardNames += LoyaltyCard::query()->whereKey($newEnrollments->pluck('card_id')->unique()->diff(array_keys($cardNames))->values())
                ->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();

            foreach ($newEnrollments as $enrollment) {
                $enrollments[$enrollment->id] = ['reference' => $references->get($enrollment->user_id), 'card' => $cardNames[$enrollment->card_id] ?? ''];
            }

            $staff += $this->names($chunk->pluck('staff_id')->filter()->unique()->diff(array_keys($staff))->values()->all());

            foreach ($chunk as $stamp) {
                $site = $sites->get($stamp->location_id);
                $at = $stamp->created_at->copy()->setTimezone(LocalMoment::timezoneOf($site instanceof Location ? $site : null));
                $enrollment = $enrollments[$stamp->enrollment_id] ?? ['reference' => null, 'card' => ''];

                $this->row($csv, [
                    $at->format('Y-m-d'),
                    $at->format('H:i'),
                    $site instanceof Location ? $site->name : '',
                    $enrollment['card'],
                    $enrollment['reference'] ?? '',
                    $stamp->source->getLabel(),
                    $stamp->qty,
                    $stamp->reason ?? '',
                    $stamp->staff_id === null ? '' : ($staff[$stamp->staff_id] ?? ''),
                ]);
                $count++;
            }
        }

        fclose($csv);

        return $count;
    }

    /**
     * People as B4 and B5 name them (first name and initial), blank once anonymised; by id.
     *
     * @param  list<int>|array<int, int>  $userIds
     * @return array<int, string>
     */
    private function names(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var Collection<int, User> $users */
        $users = User::query()->whereKey($userIds)->get(['id', 'name', 'anonymised_at']);

        return $users->mapWithKeys(fn (User $user): array => [$user->id => $user->isAnonymised() ? '' : (string) $user->shortName()])->all();
    }

    /** A temporary file, deleted by handle() whatever happens. */
    private function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pcx');

        if ($path === false) {
            throw new RuntimeException('The export could not be written.');
        }

        return $path;
    }

    /**
     * A CSV file opened for writing, its byte order mark and headers written.
     *
     * @param  list<string>  $headers
     * @return resource
     */
    private function open(string $path, array $headers)
    {
        $csv = fopen($path, 'wb');

        if ($csv === false) {
            throw new RuntimeException('The export could not be written.');
        }

        fwrite($csv, "\u{FEFF}");
        $this->row($csv, $headers);

        return $csv;
    }

    /**
     * @param  resource  $csv
     * @param  list<string|int|null>  $cells
     */
    private function row($csv, array $cells): void
    {
        fputcsv($csv, array_map(self::cell(...), $cells), ',', '"', '');
    }

    /** A cell as written: text a spreadsheet would run as a formula starts with a quote. Numbers stay numbers. */
    public static function cell(string|int|null $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        return $value !== '' && in_array($value[0], self::FORMULA, true) ? "'".$value : $value;
    }
}
