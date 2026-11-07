<?php

declare(strict_types=1);

namespace App\Actions\Customers;

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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * The two files go in a zip, encrypted (Crypt, APP_KEY) on the `exports` disk, never served (DownloadCustomerExport
 * reads it back), and deleted after a day (DeleteExpiredExports).
 */
final readonly class BuildCustomerExport
{
    public const string DISK = 'exports';

    /** A cell starting with one of these is a formula to a spreadsheet. */
    private const array FORMULA = ['=', '+', '-', '@', "\t", "\r"];

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
        return $this->context->bypass(function () use ($businessId, $from, $to, $locale, $now): array {
            $timezone = LocalMoment::timezoneOfBusiness($businessId);
            $start = LocalMoment::dayStart($timezone, CarbonImmutable::parse($from, $timezone));
            $end = LocalMoment::dayEnd($timezone, CarbonImmutable::parse($to, $timezone));

            [$customers, $customerCount] = $this->customers($businessId, $start, $end, $timezone, $locale, $now);
            [$stamps, $stampCount] = $this->stamps($businessId, $start, $end, $locale);

            $export = (string) Str::ulid();
            $zip = tempnam(sys_get_temp_dir(), 'pcx');

            try {
                $archive = new ZipArchive;

                if ($zip === false || $archive->open($zip, ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('The export could not be zipped.');
                }

                $archive->addFromString('customers.csv', $customers);
                $archive->addFromString('stamps.csv', $stamps);
                $archive->close();

                Storage::disk(self::DISK)->put(self::path($businessId, $export), Crypt::encryptString((string) file_get_contents($zip)));
            } finally {
                if (is_string($zip) && is_file($zip)) {
                    unlink($zip);
                }
            }

            return ['export' => $export, 'customers' => $customerCount, 'stamps' => $stampCount];
        });
    }

    /**
     * The business's customers with a visit (a presence stamp) here in the range.
     *
     * @return array{0: string, 1: int}
     */
    private function customers(int $businessId, CarbonImmutable $start, CarbonImmutable $end, string $timezone, string $locale, CarbonImmutable $now): array
    {
        $csv = $this->open([__('Reference', [], $locale), __('Name', [], $locale), __('Visits', [], $locale), __('First visit', [], $locale),
            __('Last visit', [], $locale), __('Usual gap (days)', [], $locale), __('Status', [], $locale), __('Rewards redeemed', [], $locale),
            __('Follows for offers', [], $locale)]);
        $organizationId = (int) Business::query()->whereKey($businessId)->value('organization_id');
        $visitors = CardEnrollment::query()->select('user_id')->whereIn('id', StampEvent::query()->select('enrollment_id')
            ->where('business_id', $businessId)->whereIn('source', StampSource::presenceValues())
            ->where('created_at', '>=', $start)->where('created_at', '<', $end));
        $count = 0;

        foreach (BusinessCustomer::query()->where('business_id', $businessId)->whereIn('user_id', $visitors)->orderBy('id')->lazyById(500) as $customer) {
            $user = User::query()->whereKey($customer->user_id)->first(['id', 'name', 'anonymised_at']);
            $follows = Follow::query()->where('organization_id', $organizationId)->where('user_id', $customer->user_id)->where('notify', true)->exists();

            $this->row($csv, [
                $customer->id,
                $user instanceof User && ! $user->isAnonymised() ? (string) $user->shortName() : '',
                $customer->visits,
                $customer->first_visit_at->setTimezone($timezone)->format('Y-m-d'),
                $customer->last_visit_at->setTimezone($timezone)->format('Y-m-d'),
                $customer->usual_gap_days ?? '',
                __(match ($customer->status($now)->value) {
                    'lost' => 'Lost',
                    'quiet' => 'Going quiet',
                    default => 'Active',
                }, [], $locale),
                $customer->rewards_redeemed,
                $follows ? __('Yes', [], $locale) : __('No', [], $locale),
            ]);
            $count++;
        }

        return [$this->close($csv), $count];
    }

    /**
     * Every stamp given here in the range, oldest first, in its site's time.
     *
     * @return array{0: string, 1: int}
     */
    private function stamps(int $businessId, CarbonImmutable $start, CarbonImmutable $end, string $locale): array
    {
        $csv = $this->open([__('Date', [], $locale), __('Time', [], $locale), __('Site', [], $locale), __('Card', [], $locale),
            __('Customer reference', [], $locale), __('How', [], $locale), __('Stamps', [], $locale), __('Reason', [], $locale),
            __('Stamped by', [], $locale)]);
        $sites = Location::query()->where('business_id', $businessId)->get(['id', 'name', 'timezone'])->keyBy('id');
        $references = [];
        $cards = [];
        $staff = [];
        $count = 0;

        foreach (StampEvent::query()->where('business_id', $businessId)->where('created_at', '>=', $start)->where('created_at', '<', $end)->lazyById(1000) as $stamp) {
            if (! array_key_exists($stamp->enrollment_id, $references)) {
                $enrollment = CardEnrollment::query()->whereKey($stamp->enrollment_id)->first(['id', 'user_id', 'card_id']);
                $references[$stamp->enrollment_id] = $enrollment instanceof CardEnrollment
                    ? BusinessCustomer::query()->where('business_id', $businessId)->where('user_id', $enrollment->user_id)->value('id')
                    : null;
                $cardId = $enrollment?->card_id;
                $cards[$stamp->enrollment_id] = $cardId === null ? '' : ($cards['card:'.$cardId] ??= (string) LoyaltyCard::query()->whereKey($cardId)->value('name'));
            }

            if ($stamp->staff_id !== null && ! array_key_exists($stamp->staff_id, $staff)) {
                $person = User::query()->whereKey($stamp->staff_id)->first(['id', 'name', 'anonymised_at']);
                $staff[$stamp->staff_id] = $person instanceof User && ! $person->isAnonymised() ? (string) $person->shortName() : '';
            }

            $site = $sites->get($stamp->location_id);
            $at = $stamp->created_at->copy()->setTimezone(LocalMoment::timezoneOf($site instanceof Location ? $site : null));

            $this->row($csv, [
                $at->format('Y-m-d'),
                $at->format('H:i'),
                $site instanceof Location ? $site->name : '',
                $cards[$stamp->enrollment_id],
                $references[$stamp->enrollment_id] ?? '',
                __($this->how($stamp->source), [], $locale),
                $stamp->qty,
                $stamp->reason ?? '',
                $stamp->staff_id === null ? '' : $staff[$stamp->staff_id],
            ]);
            $count++;
        }

        return [$this->close($csv), $count];
    }

    /** How a stamp was given, as B5 says it. */
    private function how(StampSource $source): string
    {
        return match ($source) {
            StampSource::Nfc => 'Tap',
            StampSource::Qr => 'QR scan',
            StampSource::Manual => 'By hand',
            StampSource::Bonus => 'Bonus',
            StampSource::Birthday => 'Birthday',
            StampSource::Referral => 'Referral',
            StampSource::Correction => 'Correction',
        };
    }

    /**
     * A CSV in memory, its byte order mark and headers written.
     *
     * @param  list<string>  $headers
     * @return resource
     */
    private function open(array $headers)
    {
        $csv = fopen('php://temp', 'w+b');

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

    /** @param resource $csv */
    private function close($csv): string
    {
        rewind($csv);
        $contents = (string) stream_get_contents($csv);
        fclose($csv);

        return $contents;
    }
}
