<?php

namespace App\Http\Controllers;

use App\Enums\AccessScope;
use App\Enums\RequestStatus;
use App\Models\RequestEvent;
use App\Models\WebsiteRequest;
use App\Services\AuditLogger;
use App\Services\Domains\DomainNormalizer;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebsiteRequestController extends Controller
{
    public function index(Request $request): View
    {
        $query = WebsiteRequest::query()->with(['requester', 'assignedTo'])->latest();
        if (! $request->user()->isIt()) {
            $query->where('requester_id', $request->user()->id);
        }

        return view('requests.index', ['requests' => $query->paginate(20)]);
    }

    public function create(Request $request, SettingsRepository $settings, DomainNormalizer $normalizer): View
    {
        $requestedDomains = $request->query('domains', []);
        $requestedDomains = is_array($requestedDomains) ? $requestedDomains : [$requestedDomains];
        $prefillDomains = '';

        if ($requestedDomains !== []) {
            try {
                $prefillDomains = implode("\n", $normalizer->many(implode("\n", $requestedDomains)));
            } catch (ValidationException) {
                $prefillDomains = '';
            }
        }

        return view('requests.create', [
            'categories' => $settings->get('approval.categories', ['Teaching and learning', 'Research', 'Professional development', 'Administration', 'Other']),
            'prefillDomains' => $prefillDomains,
        ]);
    }

    public function store(
        Request $request,
        DomainNormalizer $normalizer,
        AuditLogger $audit,
        RequestWorkflow $workflow,
        SettingsRepository $settings,
    ): RedirectResponse {
        $validated = $request->validate([
            'domains' => ['required', 'string', 'max:4000'],
            'access_scope' => ['required', 'in:staff,staff_students'],
            'category' => ['required', 'string', 'max:100'],
            'use_case' => ['required', 'string', 'min:20', 'max:5000'],
            'standards_confirmed' => ['accepted'],
            'requested_from' => ['nullable', 'date'],
            'requested_until' => ['nullable', 'date', 'after:requested_from'],
        ]);

        $websiteRequest = DB::transaction(function () use ($request, $validated, $normalizer): WebsiteRequest {
            $websiteRequest = WebsiteRequest::query()->create([
                'reference' => 'PENDING-'.str()->uuid(),
                'requester_id' => $request->user()->id,
                'domains' => $normalizer->many($validated['domains']),
                'access_scope' => AccessScope::from($validated['access_scope']),
                'category' => $validated['category'], 'use_case' => $validated['use_case'],
                'standards_confirmed' => true, 'status' => RequestStatus::Pending,
                'requested_from' => $validated['requested_from'] ?? null,
                'requested_until' => $validated['requested_until'] ?? null,
            ]);
            $websiteRequest->forceFill([
                'reference' => sprintf('WEB-%s-%05d', now()->format('Y'), $websiteRequest->id),
            ])->save();
            RequestEvent::query()->create(['website_request_id' => $websiteRequest->id, 'actor_id' => $request->user()->id, 'type' => 'submitted', 'message' => 'Website access request submitted.']);

            return $websiteRequest;
        });

        $audit->log('website_request.created', $websiteRequest, ['scope' => $websiteRequest->access_scope->value]);

        $automaticallyApproved = $websiteRequest->access_scope === AccessScope::Staff
            && (bool) $settings->get('approval.auto_approve_staff', false);

        if ($automaticallyApproved) {
            $workflow->approve(
                $websiteRequest,
                $request->user(),
                'Automatically approved under the configured staff-only access policy.'
            );
        }

        return redirect()->route('requests.show', $websiteRequest)->with(
            'success',
            $automaticallyApproved ? 'Staff-only request approved under the configured policy.' : 'Request submitted to IT.'
        );
    }

    public function show(Request $request, WebsiteRequest $websiteRequest): View
    {
        abort_unless($request->user()->isIt() || $websiteRequest->requester_id === $request->user()->id, 403);

        return view('requests.show', ['websiteRequest' => $websiteRequest->load(['requester', 'assignedTo', 'events.actor'])]);
    }

    public function reply(Request $request, WebsiteRequest $websiteRequest): RedirectResponse
    {
        abort_unless($websiteRequest->requester_id === $request->user()->id && $websiteRequest->status === RequestStatus::NeedsInformation, 403);
        $validated = $request->validate(['message' => ['required', 'string', 'min:10', 'max:5000']]);
        RequestEvent::query()->create(['website_request_id' => $websiteRequest->id, 'actor_id' => $request->user()->id, 'type' => 'information_provided', 'message' => $validated['message']]);
        $websiteRequest->forceFill(['status' => RequestStatus::Pending])->save();

        return back()->with('success', 'Your response has been sent to IT.');
    }
}
