<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * "Fewer emails" from the link in a request email, without signing in. The
 * link is signed for one person; opening it only shows the choice (so mail
 * scanners that follow links change nothing), and the button saves it.
 */
class EmailPreferenceController extends Controller
{
    /** The link for the foot of a request email. */
    public static function link(User $user): string
    {
        return URL::signedRoute('email.preferences', ['user' => $user->id]);
    }

    public function show(User $user): View
    {
        return view('email-preferences', ['user' => $user]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate(['email_updates' => ['required', 'in:all,essential']]);
        if ($user->email_updates !== $validated['email_updates']) {
            $user->forceFill(['email_updates' => $validated['email_updates']])->save();
            $audit->log('user.email_updates_changed', $user, ['to' => $validated['email_updates'], 'from_email_link' => true]);
        }

        return redirect()->to(self::link($user))->with('success', $validated['email_updates'] === 'essential'
            ? 'Done. You will only be emailed when a request is decided, IT has a question, or access is removed.'
            : 'Done. You will get every update about your requests.');
    }
}
