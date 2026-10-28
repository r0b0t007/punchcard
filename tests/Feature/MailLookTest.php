<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\LoginLinkMail;

/*
|--------------------------------------------------------------------------
| The emails' look (CHW-221, Claude Design "Punchcard Emails")
|--------------------------------------------------------------------------
|
| Every notification renders in punchcard's mail layout: the wordmark in
| the header (never Laravel's logo), the cream page and card, the espresso
| button, punchcard's footer, the fonts where a client loads them, and
| Arabic still right to left with the logo left to right.
|
*/

beforeEach(function (): void {
    $this->html = function (string $locale): string {
        app()->setLocale($locale);

        return (string) (new LoginLinkMail('https://punchcard.test/login/link/abc'))->toMail(User::factory()->make())->render();
    };
});

it('heads every email with the punchcard wordmark, never Laravel\'s logo', function (): void {
    $html = ($this->html)('en');

    expect($html)->toContain('class="wordmark-dot"')
        ->toContain('background-color: #f2a541')
        ->toMatch('/<span class="wordmark-dot"[^>]*><\/span>punchcard\s*<\/a>/')
        ->not->toContain('notification-logo')
        ->not->toContain('Laravel');
});

it('draws the design\'s page, card, button and footer, with its fonts', function (): void {
    $html = ($this->html)('en');

    expect($html)
        // The page and the card at radius 16 with its hairline.
        ->toContain('background-color: #f6eee3')
        ->toMatch('/class="inner-body"[^>]*style="[^"]*background-color: #fffdf9;[^"]*border: 1px solid #e6d9c8;[^"]*border-radius: 16px/')
        // The espresso button at radius 12.
        ->toMatch('/class="button button-primary"[^>]*style="[^"]*border-radius: 12px;[^"]*color: #fbf6ee;[^"]*background-color: #3b2a20/')
        // The greeting in the display font, at 22px.
        ->toMatch("/<h1 style=\"[^\"]*font-family: 'Baloo Bhaijaan 2'[^\"]*font-size: 22px/")
        ->toContain('fonts.googleapis.com/css2?family=Baloo+Bhaijaan+2')
        ->toContain('© '.date('Y').' punchcard. All rights reserved.');
});

it('keeps an Arabic email right to left, the wordmark left to right', function (): void {
    $html = ($this->html)('ar');

    expect($html)->toContain('<body dir="rtl"')
        ->toMatch('/<a href="[^"]*" dir="ltr"[^>]*>\s*<span class="wordmark-dot"/')
        ->toContain('© '.date('Y').' punchcard. '.__('All rights reserved.', locale: 'ar'));
});
