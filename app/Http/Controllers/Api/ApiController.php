<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\NetworkType;
use App\Models\PolicyRelease;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Domains\DomainNormalizer;
use App\Services\Policy\AccessExplorer;
use App\Services\Requests\InvalidRequestTransition;
use App\Services\Requests\RequestWorkflow;
use App\Support\Reach;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The public API (v1): requests, decisions, policy versions and the access
 * explorer. Every action is recorded against the token's IT officer, and
 * the same rules apply as in the app (including two-person approval).
 */
class ApiController extends Controller
{
    public function requests(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::enum(RequestStatus::class)], 'since' => ['nullable', 'date']]);
        $page = WebsiteRequest::query()->with('requester:id,name,email')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['since'] ?? null, fn ($query, $since) => $query->where('updated_at', '>=', $since))
            ->latest('id')->paginate(50);

        return response()->json(['data' => $page->getCollection()->map(fn (WebsiteRequest $item) => $this->present($item))->all(), 'next' => $page->nextPageUrl()]);
    }

    public function show(string $reference): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($reference))]);
    }

    /** Submit a request on behalf of an existing user (for example from a ticketing system). */
    public function store(Request $request, RequestWorkflow $workflow, DomainNormalizer $normalizer): JsonResponse
    {
        $validated = $request->validate([
            'requester_email' => ['required', 'email', Rule::exists('users', 'email')->where('enabled', true)],
            'websites' => ['required', 'array', 'min:1', 'max:20'], 'websites.*' => ['string', 'max:2000'],
            'network_types' => ['required', 'array', 'min:1'], 'network_types.*' => ['string', Rule::in(NetworkType::query()->where('requestable', true)->pluck('key')->all())],
            'category' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'until' => ['nullable', 'date', 'after:now'],
            'type' => ['nullable', 'in:access,block'],
        ]);
        $requester = User::query()->where('email', $validated['requester_email'])->firstOrFail();
        $created = $workflow->submit($requester, [
            'request_type' => $validated['type'] ?? 'access',
            'domains' => $normalizer->many(implode("\n", $validated['websites'])),
            'access_scope' => Reach::types(...$validated['network_types']),
            'category' => $validated['category'], 'use_case' => $validated['reason'] ?? null,
            'requested_from' => null, 'requested_until' => isset($validated['until']) ? now()->parse($validated['until']) : null,
            'department' => $requester->department,
        ]);

        return response()->json(['data' => $this->present($created->refresh())], 201);
    }

    public function decide(Request $request, string $reference, RequestWorkflow $workflow): JsonResponse
    {
        $validated = $request->validate(['decision' => ['required', 'in:approve,decline'], 'message' => ['nullable', 'required_if:decision,decline', 'string', 'max:5000']]);
        $item = $this->find($reference);

        try {
            $validated['decision'] === 'approve'
                ? $workflow->approve($item, $request->user(), $validated['message'] ?? null)
                : $workflow->reject($item, $request->user(), $validated['message']);
        } catch (InvalidRequestTransition $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->present($item->refresh())]);
    }

    public function versions(): JsonResponse
    {
        return response()->json(['data' => PolicyRelease::query()->whereNotNull('number')->latest('id')->limit(50)->get()
            ->map(fn (PolicyRelease $release) => ['version' => $release->number, 'status' => $release->status, 'trigger' => $release->trigger,
                'summary' => $release->summary, 'published_at' => $release->created_at->toIso8601String(), 'rollback_of' => $release->rollbackOf?->number])->all()]);
    }

    public function explain(Request $request, AccessExplorer $explorer): JsonResponse
    {
        $validated = $request->validate(['site' => ['required', 'string', 'max:253'], 'type' => ['required', Rule::exists('network_types', 'key')], 'at' => ['nullable', 'date']]);
        $result = $explorer->explain(strtolower($validated['site']), NetworkType::byKey($validated['type']), isset($validated['at']) ? now()->parse($validated['at']) : null);

        return response()->json(['data' => [
            'site' => $result['domain'], 'type' => $validated['type'], 'verdict' => $result['verdict'], 'reason' => $result['reason'], 'pending' => $result['pending'],
            'policies' => collect($result['policies'])->map(fn (array $policy) => ['policy' => $policy['policy']->name, 'verdict' => $policy['verdict'], 'deciding' => $policy['deciding'],
                'steps' => collect($policy['steps'])->map(fn (array $step) => ['step' => $step['label'], 'outcome' => $step['outcome'], 'detail' => $step['detail']])->all()])->all(),
        ]]);
    }

    private function find(string $reference): WebsiteRequest
    {
        return WebsiteRequest::query()->where('reference', $reference)->with('requester:id,name,email')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(WebsiteRequest $item): array
    {
        return [
            'reference' => $item->reference, 'type' => $item->isBlock() ? 'block' : 'access', 'status' => $item->status->value,
            'websites' => $item->domains, 'approved_websites' => $item->approved_domains, 'networks' => $item->effectiveScope()->value(),
            'category' => $item->category, 'department' => $item->department, 'reason' => $item->use_case,
            'requester' => ['name' => $item->requester?->name, 'email' => $item->requester?->email],
            'submitted_at' => $item->created_at?->toIso8601String(), 'approved_at' => $item->approved_at?->toIso8601String(),
            'access_from' => $item->access_from?->toIso8601String(), 'expires_at' => $item->expires_at?->toIso8601String(),
            'awaiting_second_approval' => $item->status === RequestStatus::AwaitingSecondApproval,
            'url' => route('requests.show', $item),
        ];
    }
}
