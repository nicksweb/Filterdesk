<?php

namespace App\Http\Controllers;

use App\Enums\AccessScope;
use App\Models\User;
use App\Models\WebsiteRequest;
use App\Services\Policy\PolicyListParser;
use App\Services\Requests\InvalidRequestTransition;
use App\Services\Requests\RequestWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItRequestActionController extends Controller
{
    public function __invoke(Request $request, WebsiteRequest $websiteRequest, RequestWorkflow $workflow, PolicyListParser $parser): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject,information,assign,assign_me,unassign,applied,revoke,note'],
            'message' => ['nullable', 'required_if:action,reject,information,revoke,note', 'string', 'max:5000'],
            'assignee_id' => [
                'nullable', 'required_if:action,assign', 'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['it', 'admin'])->where('enabled', true)),
            ],
            'domains' => ['nullable', 'array', 'max:60'],
            'domains.*' => ['string', 'max:253'],
            'extra_domains' => ['nullable', 'string', 'max:4000'],
            'scope' => ['nullable', Rule::enum(AccessScope::class)],
        ], [
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
                    isset($validated['scope']) ? AccessScope::from($validated['scope']) : null,
                ),
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

        return back()->with('success', match ($validated['action']) {
            'approve' => 'Request approved and added to the policy lists.',
            'assign', 'assign_me' => 'Request assigned.',
            'note' => 'Internal note added.',
            default => 'Request updated.',
        });
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
}
