<?php

namespace App\Http\Controllers;

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
        $request->validate(['notify_new_requests' => ['nullable', 'boolean']]);
        $request->user()->forceFill(['notify_new_requests' => $request->boolean('notify_new_requests')])->save();

        return back()->with('success', 'Preferences saved.');
    }
}
