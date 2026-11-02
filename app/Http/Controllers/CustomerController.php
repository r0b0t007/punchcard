<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Customers\DescribeCustomer;
use App\Actions\Customers\ListCustomers;
use App\Actions\Customers\WriteNote;
use App\Actions\Stamps\AddStamps;
use App\Actions\Stamps\StampRejected;
use App\Enums\StampRejection;
use App\Http\Requests\Customers\CustomerRequest;
use App\Http\Requests\Customers\CustomersRequest;
use App\Http\Requests\Customers\DeleteNoteRequest;
use App\Http\Requests\Customers\StampCustomerRequest;
use App\Http\Requests\Customers\StoreNoteRequest;
use App\Models\Business;
use App\Models\BusinessCustomer;
use App\Models\CardEnrollment;
use App\Models\CustomerNote;
use App\Models\User;
use App\Support\LocalMoment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A business's customers (CHW-44), for whoever runs it:
 *
 * - GET /business/customers: the list (B4), by tab, first name and page;
 * - GET /business/customers/{customer}: one of them (B5), their history at
 *   the business and the team's notes. Another business's is a 404.
 * - POST …/stamps and …/corrections (throttled): stamps added by hand, or
 *   taken back, with a reason (AddStamps, as the owner); a refusal is an
 *   error on the field to change.
 * - POST …/notes and DELETE …/notes/{note} (throttled): the team's notes.
 */
final class CustomerController extends Controller
{
    public function index(CustomersRequest $request, ListCustomers $listCustomers): Response
    {
        /** @var Business $business authorized by the request */
        $business = $request->business();

        return Inertia::render('business/customers/index', $listCustomers->handle($business, $request->tab(), $request->search(), $request->page(), CarbonImmutable::now()));
    }

    public function show(CustomerRequest $request, DescribeCustomer $describeCustomer): Response
    {
        /** @var Business $business the customer's, found in it */
        $business = $request->business();

        return Inertia::render('business/customers/show', [
            ...$describeCustomer->handle($business, $request->customer(), $request->shown(), CarbonImmutable::now()),
            'maxQty' => StampCustomerRequest::MAX_QTY,
            'noteMax' => CustomerNote::MAX_LENGTH,
        ]);
    }

    public function stamp(StampCustomerRequest $request, AddStamps $addStamps): RedirectResponse
    {
        /** @var CardEnrollment $enrollment validated */
        $enrollment = $request->enrollment();

        try {
            $result = $addStamps->handle($enrollment, $request->stampRequest());
        } catch (StampRejected $rejected) {
            throw ValidationException::withMessages($this->rejected($rejected, $request));
        }

        $count = abs($result->event->qty);
        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $request->takesBack() => trans_choice(':count stamp taken back.|:count stamps taken back.', $count),
            $result->rewards !== [] => trans_choice(':count stamp added, and a reward unlocked.|:count stamps added, and a reward unlocked.', $count),
            default => trans_choice(':count stamp added.|:count stamps added.', $count),
        }]);

        // Every stamp given here taken back: no longer the business's customer, once the summary has followed.
        return BusinessCustomer::query()->whereKey($request->customer()->id)->exists()
            ? to_route('customers.show', $request->customer())
            : to_route('customers.index');
    }

    public function note(StoreNoteRequest $request, WriteNote $writeNote): RedirectResponse
    {
        /** @var User $author */
        $author = $request->user();
        $writeNote->handle($request->customer(), $author, $request->body());

        return to_route('customers.show', $request->customer());
    }

    public function deleteNote(DeleteNoteRequest $request): RedirectResponse
    {
        $request->note()->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Note deleted.')]);

        return to_route('customers.show', $request->customer());
    }

    /**
     * Why AddStamps refused, on the field to change: how many, which card or where.
     *
     * @return array<string, string>
     */
    private function rejected(StampRejected $rejected, StampCustomerRequest $request): array
    {
        return match ($rejected->rejection) {
            StampRejection::Cooldown => ['qty' => __('Too soon after their last stamp on this card: the next one can be given from :date.', [
                'date' => $rejected->availableAt?->copy()->setTimezone(LocalMoment::timezoneOf($request->location()))->translatedFormat('l j F, H:i'),
            ])],
            StampRejection::DailyCap => ['qty' => __('That goes over today’s limit on this card here.')],
            StampRejection::CorrectionExceedsGiven => ['qty' => __('That is more than this business gave on this card.')],
            StampRejection::CorrectionBelowZero => ['qty' => __('The card doesn’t have that many stamps.')],
            StampRejection::CardInactive, StampRejection::NotHonoured => ['enrollment' => __('This card isn’t running here: nothing was added.')],
            StampRejection::SiteClosed => ['location' => __('This site is closed: nothing was added.')],
            StampRejection::AccountDeleted => ['qty' => __('This customer\'s account was deleted: nothing was added.')],
            // The same press, already recorded with other details: a retry with this key can only be refused again.
            StampRejection::IdempotencyConflict => ['qty' => __('This was already recorded. Reload the page to see it.')],
            StampRejection::CardMisconfigured => ['enrollment' => __('This card’s rewards need fixing in the card builder: nothing was added.')],
            default => ['qty' => __('The stamp could not be added. Try again.')],
        };
    }
}
