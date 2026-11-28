<?php

namespace App\Http\Controllers;

use App\Services\WhatsNew;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['notify_new_requests' => ['nullable', 'boolean'], 'email_updates' => ['nullable', 'in:all,essential']]);
        $user = $request->user();
        $user->forceFill([
            'notify_new_requests' => $user->isIt() ? $request->boolean('notify_new_requests') : $user->notify_new_requests,
            'email_updates' => $validated['email_updates'] ?? $user->email_updates,
        ])->save();

        return back()->with('success', 'Preferences saved.');
    }

    /** "Got it" on the dashboard's What's new: hidden until the next release. */
    public function dismissWhatsNew(Request $request, WhatsNew $whatsNew): RedirectResponse
    {
        $whatsNew->dismiss($request->user());

        return back();
    }
}
