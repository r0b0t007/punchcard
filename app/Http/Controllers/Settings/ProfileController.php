<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\DeleteAccount;
use App\Actions\Auth\RequestEmailChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Auth\RecentConfirmation;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'hasPassword' => $request->user()->hasPassword(),
            // A new address waiting to be confirmed from its inbox (RequestEmailChange).
            'pendingEmail' => $request->user()->pendingEmail(),
            // A new address asks to confirm it's them first, unless they did lately (ProfileUpdateRequest).
            'recentlyConfirmed' => RecentConfirmation::holds($request->session()),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * The name at once; a new address only once confirmed from its own inbox (RequestEmailChange, CHW-23): until
     * then the account keeps its address, so a mistyped one costs nothing. Over the limit for new addresses,
     * nothing is saved, the name neither.
     */
    public function update(ProfileUpdateRequest $request, RequestEmailChange $requestEmailChange): RedirectResponse
    {
        $user = $request->user();
        $email = (string) $request->validated('email');
        $emailChange = $requestEmailChange->handle($user, $email);

        if ($emailChange === RequestEmailChange::LIMITED) {
            return back()->withErrors(['email' => __('Too many address changes. Try again later.')]);
        }

        $user->fill(['name' => $request->validated('name')])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => $emailChange === RequestEmailChange::REQUESTED
            ? __('Check :email to confirm your new address.', ['email' => $email])
            : __('Profile updated.')]);

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
