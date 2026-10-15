<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\DeleteAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Notifications\EmailChangedMail;
use App\Support\Auth\RecentConfirmation;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            // Without one (signed up by email link, CHW-23), deleting the account asks to confirm it's them instead.
            'hasPassword' => $request->user()->password !== null,
            // A new address asks to confirm it's them first, unless they did lately (ProfileUpdateRequest).
            'recentlyConfirmed' => RecentConfirmation::holds($request->session()),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $oldEmail = $request->user()->email;
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // The new address is confirmed from its inbox at once (until then it signs in by no link), and the old one
        // hears of the change (CHW-23).
        if ($request->user()->wasChanged('email')) {
            $request->user()->sendEmailVerificationNotification();
            Notification::route('mail', $oldEmail)->notify((new EmailChangedMail($request->user()->email))->locale($request->user()->preferredLocale() ?? app()->getLocale()));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account: deleted outright, or anonymised when the
     * stamp ledger keeps their history (DeleteAccount). Logs out first, so
     * the guard never touches the row afterwards.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $deleteAccount->handle($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
