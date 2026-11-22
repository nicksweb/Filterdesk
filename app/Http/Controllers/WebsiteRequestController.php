<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\NetworkType;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Approval\TwoPersonRule;
use App\Services\Domains\DomainCategorizer;
use App\Services\Domains\DomainNormalizer;
use App\Services\Network\DomainRules;
use App\Services\Policy\AccessExplorer;
use App\Services\Policy\AccessPlanner;
use App\Services\Policy\ControllerPusher;
use App\Services\Policy\DomainRecommender;
use App\Services\Policy\PolicyEngine;
use App\Services\Requests\InvalidRequestTransition;
use App\Services\Requests\RequestFormSettings;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Support\Reach;
use App\Support\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebsiteRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,granted,action_required,closed,all'],
            'assigned' => ['nullable', 'in:me,unassigned,all'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $status = $filters['status'] ?? ($user->isIt() ? 'open' : 'all');
        $assigned = $filters['assigned'] ?? 'all';
        $search = trim($filters['q'] ?? '');

        $base = WebsiteRequest::query()->when(! $user->isIt(), fn (Builder $query) => $query->where('requester_id', $user->id));
        $statusGroups = [
            'open' => RequestStatus::open(),
            'action_required' => [RequestStatus::Approved, RequestStatus::ActionRequired, RequestStatus::Failed],
            'granted' => [RequestStatus::Active],
            'closed' => [RequestStatus::Rejected, RequestStatus::Expired, RequestStatus::Revoked],
        ];

        $query = (clone $base)->with(['requester', 'assignedTo'])
            ->when($status !== 'all', fn (Builder $query) => $query->whereIn('status', $statusGroups[$status]))
            ->when($user->isIt() && $assigned === 'me', fn (Builder $query) => $query->where('assigned_to_id', $user->id))
            ->when($user->isIt() && $assigned === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_to_id'))
            ->when($search !== '', function (Builder $query) use ($search, $user): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], strtolower($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('lower(reference) like ?', [$like])
                    ->orWhereRaw('lower(cast(domains as text)) like ?', [$like])
                    ->when($user->isIt(), fn (Builder $query) => $query->orWhereHas('requester', fn (Builder $query) => $query->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]))));
            })
            ->orderByRaw("case when status in ('pending','needs_information') then 0 when status in ('approved','action_required','failed') then 1 else 2 end")
            ->latest('updated_at');

        $counts = collect($statusGroups)->map(fn (array $statuses) => (clone $base)->whereIn('status', $statuses)->count());

        return view('requests.index', [
            'requests' => $query->paginate(20)->withQueryString(),
            'counts' => $counts,
            'filters' => ['status' => $status, 'assigned' => $assigned, 'q' => $search],
        ]);
    }

    public function create(Request $request, SettingsRepository $settings, DomainNormalizer $normalizer, RequestFormSettings $form): View|RedirectResponse
    {
        if (! $this->canRequest($request->user(), $settings)) {
            return redirect()->route('checks.create')->with('error', 'Students cannot submit website requests. Ask a teacher to request access.');
        }

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
            'block' => $request->query('type') === 'block',
            'categories' => $form->categories($request->user()),
            'reasonLabel' => $form->label($request->query('type') === 'block'),
            'reasonHint' => $form->hint($request->query('type') === 'block'),
            'reasonRequired' => $form->required($request->user()),
            'reasonMinLength' => $form->minLength(),
            'prefillDomains' => $prefillDomains,
            'defaultTemporaryDays' => (int) $settings->get('approval.default_temporary_days', 30),
            'networkTypes' => NetworkType::query()->where('requestable', true)->ordered()->get(),
            'siteCategories' => $prefillDomains !== '' ? rescue(fn () => app(DomainCategorizer::class)->many(explode("\n", $prefillDomains)), [], false) : [],
        ]);
    }

    public function store(Request $request, DomainNormalizer $normalizer, RequestWorkflow $workflow, SettingsRepository $settings, RequestFormSettings $form): RedirectResponse
    {
        abort_unless($this->canRequest($request->user(), $settings), 403);

        $categories = $form->categories($request->user());
        $block = $request->input('request_type') === 'block';
        $validated = $request->validate([
            'request_type' => ['nullable', 'in:allow,block'],
            'domains' => ['required', 'string', 'max:4000'],
            'network_types' => ['required_without:access_scope', 'array', 'min:1'],
            'network_types.*' => ['string', Rule::exists('network_types', 'key')->where('requestable', true)],
            'access_scope' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100', ...($categories ? [Rule::in($categories)] : [])],
            'use_case' => [$form->required($request->user()) ? 'required' : 'nullable', 'string', 'min:'.$form->minLength(), 'max:5000'],
            'standards_confirmed' => ['accepted'],
            'access_duration' => ['required', 'in:permanent,temporary,recurring'],
            'schedule' => ['nullable', 'array'],
            'requested_from' => ['nullable', 'date'],
            'requested_until' => ['nullable', 'required_if:access_duration,temporary', 'date'],
        ]);

        // network_types[] from the form; access_scope ("staff,student") from older links and API clients.
        $scope = Reach::tryFrom(isset($validated['network_types']) ? implode(',', $validated['network_types']) : ($validated['access_scope'] ?? null));
        $requestable = NetworkType::query()->where('requestable', true)->pluck('key')->all();
        if (! $scope || $scope->siteWide || array_diff($scope->types, $requestable) !== []) {
            throw ValidationException::withMessages(['network_types' => 'Choose the networks this request is for.']);
        }

        // Recurring: the pattern decides when entries are in place; the request spans its whole range.
        $schedule = $validated['access_duration'] === 'recurring' ? Schedule::fromInput((array) ($validated['schedule'] ?? [])) : null;

        $temporary = $validated['access_duration'] === 'temporary';
        $from = $temporary ? $settings->parseLocal($validated['requested_from'] ?? null) : null;
        $until = $temporary ? $settings->parseLocal($validated['requested_until'] ?? null) : null;
        if ($until && $until->isPast()) {
            throw ValidationException::withMessages(['requested_until' => 'The end time must be in the future.']);
        }
        if ($from && $until && $until->lte($from)) {
            throw ValidationException::withMessages(['requested_until' => 'The end time must be after the start time.']);
        }

        $websiteRequest = $workflow->submit($request->user(), [
            'request_type' => $block ? 'block' : 'allow',
            'domains' => $normalizer->many($validated['domains']),
            'access_scope' => $scope,
            'category' => $validated['category'],
            'use_case' => $validated['use_case'] ?? null,
            // Stored in UTC like every other time (Laravel keeps a Carbon's clock time as given).
            'requested_from' => $schedule ? $schedule->firstOpening()?->utc() : $from,
            'requested_until' => $schedule ? $schedule->lastClosing()->utc() : $until,
            'schedule' => $schedule?->toArray(),
        ]);

        // Auto-approval applies when every chosen network type allows it (by default only Staff).
        $autoTypes = NetworkType::query()->where('auto_approve', true)->pluck('key')->all();
        // Never for site-wide access or a sensitive category: those need two IT officers.
        $automaticallyApproved = ! $block && ! $scope->siteWide && $scope->types !== [] && array_diff($scope->types, $autoTypes) === []
            && (bool) $settings->get('approval.auto_approve_staff', false)
            && app(TwoPersonRule::class)->reasonsForRequest($websiteRequest->refresh(), $scope, $websiteRequest->domains) === [];

        if ($automaticallyApproved) {
            $workflow->approve($websiteRequest, $request->user(), 'Automatically approved under the configured staff-only access policy.');
        }

        return redirect()->route('requests.show', $websiteRequest)->with(
            'success',
            $automaticallyApproved ? 'Staff-only request approved under the configured policy.' : ($block ? 'Block request submitted to IT.' : 'Request submitted to IT.')
        );
    }

    public function show(Request $request, WebsiteRequest $websiteRequest, PolicyEngine $policy, DomainRecommender $recommender): View
    {
        $user = $request->user();
        abort_unless($user->isIt() || $websiteRequest->requester_id === $user->id, 403);

        $websiteRequest->load(['requester', 'assignedTo', 'approvedBy', 'events' => fn ($query) => $query->when(! $user->isIt(), fn ($query) => $query->where('internal', false))->with('actor')]);
        $data = ['websiteRequest' => $websiteRequest];

        if ($user->isIt()) {
            $rules = $policy->rules();
            $data += [
                'canPush' => app(ControllerPusher::class)->enabled(),
                'itStaff' => User::query()->itStaff()->get(['id', 'name', 'email', 'role']),
                'rules' => $rules,
                'recommendations' => $websiteRequest->status->isOpen()
                    ? $this->recommendations($websiteRequest, $recommender, $policy, $rules)
                    : [],
                'existing' => collect($websiteRequest->domains)->mapWithKeys(fn (string $domain) => [$domain => $policy->locate($domain)])->filter->isNotEmpty(),
                'policies' => $policy->policiesFor($websiteRequest->effectiveScope()),
                'accessPlan' => ! $websiteRequest->isBlock() && in_array($websiteRequest->status, RequestStatus::open(), true) && app(AccessPlanner::class)->available()
                    ? rescue(fn () => app(AccessPlanner::class)->plan($websiteRequest->domains, $websiteRequest->access_scope, timed: $websiteRequest->requested_until !== null), null, false)
                    : null,
                'entries' => $websiteRequest->policyEntries()->with('policyList')->get(),
                // Why each requested site is (or is not) reachable now, for the networks it was requested for.
                'explained' => $websiteRequest->status->isOpen() ? rescue(fn () => $this->explainNow($websiteRequest), [], false) : [],
            ];
        }

        return view('requests.show', $data);
    }

    /** @return list<array{domain: string, type: string, verdict: string, reason: string}> */
    private function explainNow(WebsiteRequest $websiteRequest): array
    {
        $explorer = app(AccessExplorer::class);
        $scope = $websiteRequest->access_scope;
        $types = NetworkType::query()->ordered()->get()->filter(fn (NetworkType $type) => $scope->siteWide ? $type->requestable : $scope->includes($type->key));
        $rows = [];
        foreach (array_slice($websiteRequest->domains, 0, 3) as $domain) {
            foreach ($types as $type) {
                $explained = $explorer->explain($domain, $type);
                $rows[] = ['domain' => $domain, 'type' => $type->name, 'verdict' => $explained['verdict'], 'reason' => $explained['reason']];
            }
        }

        return $rows;
    }

    /**
     * Hostnames to suggest on approval. For a block, the site's own
     * hostnames are selected; third-party hosts (CDNs, fonts, sign-in) are
     * listed but not selected, because blocking them breaks other sites.
     *
     * @return list<array{domain: string, kind: string, reason: string, selected: bool}>
     */
    private function recommendations(WebsiteRequest $websiteRequest, DomainRecommender $recommender, PolicyEngine $policy, DomainRules $rules): array
    {
        if (! $websiteRequest->isBlock()) {
            return $recommender->recommend($websiteRequest->domains, $websiteRequest->related_domains ?? [], $rules, $policy->alreadyAllowed($websiteRequest->access_scope));
        }

        return array_map(fn (array $item) => $item['kind'] === 'third_party'
            ? ['selected' => false, 'reason' => 'Third-party host the page loads. Usually shared with other sites, so not blocked by default.'] + $item
            : ['selected' => true] + $item, $recommender->recommend($websiteRequest->domains, $websiteRequest->related_domains ?? [], $rules));
    }

    public function reply(Request $request, WebsiteRequest $websiteRequest, RequestWorkflow $workflow): RedirectResponse
    {
        abort_unless($websiteRequest->requester_id === $request->user()->id, 403);
        $validated = $request->validate(['message' => ['required', 'string', 'min:10', 'max:5000']]);

        try {
            $workflow->reply($websiteRequest, $request->user(), $validated['message']);
        } catch (InvalidRequestTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Your response has been sent to IT.');
    }

    private function canRequest(User $user, SettingsRepository $settings): bool
    {
        return ! $user->isStudent() || (bool) $settings->get('approval.students_can_request', false);
    }
}
