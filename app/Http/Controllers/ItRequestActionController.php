<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\NetworkType;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Policy\PolicyListParser;
use App\Services\Requests\InvalidRequestTransition;
use App\Services\Requests\RequestWorkflow;
use App\Services\SettingsRepository;
use App\Support\Reach;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItRequestActionController extends Controller
{
    public function __invoke(Request $request, WebsiteRequest $websiteRequest, RequestWorkflow $workflow, PolicyListParser $parser, SettingsRepository $settings): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject,information,assign,assign_me,unassign,applied,revoke,note,window'],
            'message' => ['nullable', 'required_if:action,reject,information,revoke,note', 'string', 'max:5000'],
            'assignee_id' => [
                'nullable', 'required_if:action,assign', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['it', 'admin'])->where('enabled', true)),
            ],
            'domains' => ['nullable', 'array', 'max:60'],
            'domains.*' => ['string', 'max:253'],
            'extra_domains' => ['nullable', 'string', 'max:4000'],
            'scope' => ['nullable'],
            'scope.*' => ['string', 'max:40'],
            'access_from' => ['nullable', 'date'],
            'access_until' => ['nullable', 'date'],
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'required_if_accepted:override', 'string', 'max:1000'],
        ], [
            'override_reason.required_if_accepted' => 'Say why no other IT officer is available.',
            'assignee_id.exists' => 'Choose an enabled IT staff member.',
            'message.required_if' => 'Add a note explaining this action.',
        ]);

        $actor = $request->user();

        try {
            match ($validated['action']) {
                'approve' => $workflow->approve(
                    $websiteRequest,
                    $actor,
                    $validated['message'] ?? null,
                    $this->approvedDomains($validated, $parser),
                    $this->scope($validated['scope'] ?? null),
                    $this->window($request, $settings),
                    smart: $request->boolean('smart'),
                    override: $request->boolean('override') ? $validated['override_reason'] : null,
                ),
                'window' => $workflow->changeWindow($websiteRequest, $actor, ...$this->window($request, $settings) ?? ['from' => null, 'until' => null], note: $validated['message'] ?? null),
                'reject' => $workflow->reject($websiteRequest, $actor, $validated['message']),
                'information' => $workflow->requestInformation($websiteRequest, $actor, $validated['message']),
                'assign' => $workflow->assign($websiteRequest, $actor, User::query()->findOrFail($validated['assignee_id'])),
                'assign_me' => $workflow->assign($websiteRequest, $actor, $actor),
                'unassign' => $workflow->assign($websiteRequest, $actor, null),
                'applied' => $workflow->markApplied($websiteRequest, $actor, $validated['message'] ?? null),
                'revoke' => $workflow->revoke($websiteRequest, $actor, $validated['message']),
                'note' => $workflow->note($websiteRequest, $actor, $validated['message']),
            };
        } catch (InvalidRequestTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($validated['action'] === 'approve' && $websiteRequest->refresh()->status === RequestStatus::AwaitingSecondApproval) {
            return back()->with('success', 'First approval recorded. A second IT officer must confirm it ('.implode('; ', $websiteRequest->first_approval['reasons'] ?? []).'); the other IT officers have been emailed.');
        }

        return back()->with('success', match ($validated['action']) {
            'approve' => 'Request approved and added to the policy lists.',
            'assign', 'assign_me' => 'Request assigned.',
            'note' => 'Internal note added.',
            'window' => 'Access window updated.',
            default => 'Request updated.',
        });
    }

    /**
     * The access window IT entered, in school time, or null when the form
     * did not include one (keep the requested window). A blank end is permanent.
     *
     * @return array{from: ?CarbonInterface, until: ?CarbonInterface}|null
     */
    private function window(Request $request, SettingsRepository $settings): ?array
    {
        if (! $request->has('access_until') && ! $request->has('access_from')) {
            return null;
        }

        $from = $settings->parseLocal($request->input('access_from'));
        $until = $settings->parseLocal($request->input('access_until'));
        if ($until && $until->isPast()) {
            throw ValidationException::withMessages(['access_until' => 'The end of the access window must be in the future.']);
        }
        if ($from && $until && $until->lte($from)) {
            throw ValidationException::withMessages(['access_until' => 'The end of the access window must be after its start.']);
        }

        return ['from' => $from, 'until' => $until];
    }

    /**
     * Selected recommendation checkboxes plus any extra hostnames typed by IT.
     *
     * @param  array<string, mixed>  $validated
     * @return list<string>|null
     */
    private function approvedDomains(array $validated, PolicyListParser $parser): ?array
    {
        if (! array_key_exists('domains', $validated) && blank($validated['extra_domains'] ?? null)) {
            return null;
        }

        $domains = $parser->parse(implode("\n", $validated['domains'] ?? [])."\n".($validated['extra_domains'] ?? ''), 'domains');

        if ($domains === []) {
            throw ValidationException::withMessages(['domains' => 'Select at least one hostname to allow.']);
        }

        return $domains;
    }

    /** "site", a list of network type keys, or (older forms) a single stored value. */
    private function scope(mixed $input): ?Reach
    {
        if ($input === null || $input === '' || $input === []) {
            return null;
        }
        $scope = is_array($input) ? rescue(fn () => Reach::fromInput($input), null, false) : Reach::tryFrom((string) $input);
        if (! $scope || (! $scope->siteWide && array_diff($scope->types, NetworkType::keys()) !== [])) {
            throw ValidationException::withMessages(['scope' => 'Choose site-wide or at least one network type.']);
        }

        return $scope;
    }
}
