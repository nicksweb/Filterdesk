<?php

namespace App\Http\Controllers;

use App\Models\WebsiteRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page behind "approve" and "decline" links in IT emails and Teams cards:
 * a short summary and one confirm button. The link is signed and expires; the
 * person still signs in as IT, and the decision goes through the normal
 * action (so two-person approval and every check still apply).
 */
class QuickDecisionController extends Controller
{
    public function __invoke(Request $request, WebsiteRequest $websiteRequest, string $decision): View
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);

        return view('requests.quick', ['websiteRequest' => $websiteRequest->load('requester'), 'decision' => $decision]);
    }
}
