<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Contracts\View\View;

/**
 * The offline My cards page (CHW-35): the shell the service worker
 * precaches and serves when a page of My cards can't load. The same for
 * everyone, so it carries no session: the cards come from the phone. For
 * a phone with none kept, it brings its few words in every language, and
 * the page picks the phone's (else the app's default).
 */
final class OfflinePageController extends Controller
{
    /** @var list<string> */
    private const array NOTHING_KEPT = [
        "You're offline",
        'Your cards show here once you are back online.',
        'Try again',
    ];

    public function __invoke(): View
    {
        return view('offline', [
            'nothingKept' => Locales::phrasebook(self::NOTHING_KEPT),
            'defaultLocale' => Locales::default(),
        ]);
    }
}
