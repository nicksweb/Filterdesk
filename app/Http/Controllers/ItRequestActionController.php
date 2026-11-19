<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Requests\RequestWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ItRequestActionController extends Controller
{
    public function __invoke(Request $request, WebsiteRequest $websiteRequest, RequestWorkflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject,information,assign,applied'],
            'message' => ['nullable', 'required_if:action,reject,information,applied', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'required_if:action,assign', 'exists:users,id'],
        ]);

        match ($validated['action']) {
            'approve' => $workflow->approve($websiteRequest, $request->user(), $validated['message'] ?? null),
            'reject' => $workflow->reject($websiteRequest, $request->user(), $validated['message']),
            'information' => $workflow->requestInformation($websiteRequest, $request->user(), $validated['message']),
            'assign' => $workflow->assign($websiteRequest, $request->user(), User::query()->findOrFail($validated['assignee_id'])),
            'applied' => $workflow->markApplied($websiteRequest, $request->user(), $validated['message']),
        };

        return back()->with('success', 'Request updated.');
    }
}
