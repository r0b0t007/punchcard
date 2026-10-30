<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Models\User;
use App\Support\Auth\MailedLink;
use App\Support\Tenancy\TenantContext;

/**
 * Works in one business by its id (CHW-295, a business's emails): the
 * tenant switcher's choice "business:{id}", honoured as ResolveTenant
 * honours it, only while the user works there and the business operates.
 * True when the TenantContext is now that business; otherwise the context
 * is left as it was, and the caller keeps the choice it had.
 */
final readonly class SelectBusiness
{
    /** The pages a link opens, by the name in its URL. */
    public const array PAGES = ['verification' => 'verification.show', 'stampers' => 'stampers.index'];

    public function __construct(private ResolveTenant $resolveTenant, private TenantContext $context) {}

    /**
     * The link a business's email carries to one of its pages (OpenBusinessController): signed for good, as it opens
     * only what its user may open anyway, and for the user it was mailed to alone, so one held by anyone else (a
     * former co-owner) can't switch another member's business. Anyone else the mail goes to gets the page itself.
     *
     * @param  key-of<self::PAGES>  $page
     */
    public static function link(int $businessId, string $page, object $for): string
    {
        return $for instanceof User
            ? MailedLink::signedForGood('business.open', ['business' => $businessId, 'page' => $page, 'for' => $for->id])
            : route(self::PAGES[$page]);
    }

    public function handle(User $user, int $businessId): bool
    {
        $before = $this->context->snapshot();
        $this->resolveTenant->handle($user, 'business:'.$businessId);

        if ($this->context->businessId() === $businessId) {
            return true;
        }

        $this->context->restore($before);

        return false;
    }
}
