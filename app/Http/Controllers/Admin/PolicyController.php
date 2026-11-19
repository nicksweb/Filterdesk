<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Policy\PolicyListParser;
use App\Services\Policy\PolicyReconciler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PolicyController extends Controller
{
    public function index(PolicyReconciler $reconciler): View
    {
        return view('admin.policy', ['report' => $reconciler->report()]);
    }

    public function master(Request $request, PolicyListParser $parser, PolicyReconciler $reconciler, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'master_allowlist' => ['nullable', 'string', 'max:200000'],
            'master_blocklist' => ['nullable', 'string', 'max:200000'],
        ]);
        $allow = $parser->parse($validated['master_allowlist'] ?? '', 'master_allowlist');
        $block = $parser->parse($validated['master_blocklist'] ?? '', 'master_blocklist');
        $reconciler->update(['master_allowlist' => $allow, 'master_blocklist' => $block]);
        $audit->log('policy.master_updated', metadata: ['allow_count' => count($allow), 'block_count' => count($block)]);

        return back()->with('success', 'Master allow and block policy saved.');
    }

    public function compare(Request $request, PolicyListParser $parser, PolicyReconciler $reconciler, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'current_allowlist' => ['nullable', 'string', 'max:200000'],
            'current_blocklist' => ['nullable', 'string', 'max:200000'],
        ]);
        $allow = $parser->parse($validated['current_allowlist'] ?? '', 'current_allowlist');
        $block = $parser->parse($validated['current_blocklist'] ?? '', 'current_blocklist');
        $reconciler->update([
            'current_allowlist' => $allow,
            'current_blocklist' => $block,
            'compared_at' => now()->toIso8601String(),
        ]);
        $audit->log('policy.unifi_compared', metadata: ['allow_count' => count($allow), 'block_count' => count($block)]);

        return back()->with('success', 'UniFi lists compared with the managed policy.');
    }
}
