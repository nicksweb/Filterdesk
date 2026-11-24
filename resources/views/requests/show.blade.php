<x-layouts.app title="{{ $websiteRequest->reference }}">
    @php($isIt = auth()->user()->isIt())
    @php($status = $websiteRequest->status)
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ $websiteRequest->reference }} · {{ $websiteRequest->isBlock() ? 'Block request' : 'Access request' }}</p>
            <h1>{{ implode(', ', $websiteRequest->domains) }}</h1>
            <p>Requested by {{ $websiteRequest->requester->name }}{{ $websiteRequest->department ? ' ('.$websiteRequest->department.')' : '' }} · {{ $websiteRequest->created_at->local()->format('j M Y, g:i a') }}</p>
        </div>
        <x-status :status="$status" />
    </div>

    <div class="detail-grid">
        <div class="stack">
            <section class="panel">
                <h2>Request details</h2>
                <dl class="details">
                    <div><dt>{{ $websiteRequest->isBlock() ? 'Block for' : 'Requested access' }}</dt><dd>{{ $websiteRequest->access_scope?->label() }}</dd></div>
                    <div><dt>Category</dt><dd>{{ $websiteRequest->category }}</dd></div>
                    @if($websiteRequest->domain_categories)
                        <div class="wide"><dt>What the sites are</dt><dd>@foreach($websiteRequest->domain_categories as $domain => $lookup)<x-site-categories :lookup="$lookup" :domain="$domain" :show-policies="$isIt" />@endforeach<small class="hint">From the website category service when the request was submitted; a guide, not UniFi's own categorisation.</small></dd></div>
                    @endif
                    <div><dt>Assigned to</dt><dd>{{ $websiteRequest->assignedTo?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt>Requested period</dt><dd>@if($websiteRequest->requested_until){{ $websiteRequest->requested_from?->local()->format('j M Y g:i a') ?? 'As soon as approved' }} – {{ $websiteRequest->requested_until->local()->format('j M Y g:i a') }}@else Permanent @endif</dd></div>
                    @if($websiteRequest->approved_at)<div><dt>Access window</dt><dd>{{ $websiteRequest->windowLabel() }}@if($websiteRequest->windowChanged())<small>Set by IT</small>@endif</dd></div>@endif
                    @if($websiteRequest->approved_at)
                        <div><dt>Approved</dt><dd>{{ $websiteRequest->approved_at->local()->format('j M Y g:i a') }}{{ $websiteRequest->approvedBy ? ' by '.$websiteRequest->approvedBy->name : '' }}</dd></div>
                        <div><dt>Applied to</dt><dd>{{ $websiteRequest->effectiveScope()->label() }}</dd></div>
                    @endif
                </dl>
                <h3>Use case</h3>
                <p class="prose">{{ $websiteRequest->use_case ?: 'No reason given.' }}</p>
                @if($websiteRequest->approved_domains)
                    <h3>{{ $websiteRequest->isBlock() ? 'Blocked hostnames' : 'Allowed hostnames' }}</h3>
                    <div class="chip-list">@foreach($websiteRequest->approved_domains as $domain)<span class="chip">{{ $domain }}</span>@endforeach</div>
                @endif
                @if($websiteRequest->resolution)<h3>Resolution</h3><p class="prose">{{ $websiteRequest->resolution }}</p>@endif
                @php($accessGuide = app(\App\Services\Requests\AccessGuide::class))
                @if(! $isIt && $accessGuide->appliesTo($websiteRequest, auth()->user()))
                    <div class="notice access-guide"><strong>Site still looks blocked?</strong> {{ $accessGuide->settings()['intro'] }}
                        <ol>@foreach($accessGuide->steps() as $step)<li>{{ $step }}</li>@endforeach</ol></div>
                @endif
            </section>

            @if($isIt && $status->isOpen())
                <section class="panel">
                    <div class="panel-heading"><div><h2>{{ $websiteRequest->isBlock() ? 'Approve block' : 'Approve' }}</h2><p>{{ $websiteRequest->isBlock() ? 'Choose the hostnames to block. Third-party hosts are not selected because other sites share them. A block for a network type overrides an older allow at the same level and any site-wide allow.' : 'Choose the hostnames to allow. DNS filtering only sees hostnames, so include the bare domain and every subdomain the site needs.' }}</p></div></div>
                    @if(!empty($explained))
                        <div class="explained-now"><strong>Right now</strong>
                            <ul class="plain-list">@foreach($explained as $row)<li><code>{{ $row['domain'] }}</code> on {{ $row['type'] }}: <span class="verdict verdict-{{ $row['verdict'] }}">{{ ucfirst($row['verdict']) }}</span><small>{{ $row['reason'] }}</small></li>@endforeach</ul>
                            <a href="{{ route('admin.policy.explorer', ['site' => $websiteRequest->domains[0]]) }}">Full trace in the access explorer</a>
                        </div>
                    @endif
                    @php($first = $websiteRequest->first_approval)
                    @php($firstScope = $first ? \App\Support\Reach::tryFrom((string) ($first['scope'] ?? '')) : null)
                    @if($first)
                        <div class="notice two-person"><strong>Approved by {{ $first['name'] }}</strong> {{ \Illuminate\Support\Carbon::parse($first['at'])->local()->format('j M Y g:i a') }}: {{ implode(', ', $first['domains'] ?? []) }} for {{ $firstScope?->label() }}.
                            <small>Needs a second IT officer: {{ implode('; ', $first['reasons'] ?? []) }}. The form below shows the first approval; confirm it as it is or adjust it.@if(filled($first['note'] ?? null)) First approver's note: “{{ $first['note'] }}”@endif</small></div>
                    @endif
                    <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}" class="approve-form">
                        @csrf
                        <input type="hidden" name="action" value="approve">
                        @if(!empty($accessPlan))
                            <div class="access-plan">
                                <label class="choice compact"><input type="hidden" name="smart" value="0"><input type="checkbox" name="smart" value="1" checked><span><strong>Only add allow entries where needed</strong><small>Uses each site's category and the lists that block it. Untick to add an allow entry for every hostname, as before. Changing the networks or the access window below can change this plan; it is worked out again when you approve.</small></span></label>
                                <ul>
                                    @foreach($accessPlan as $domain => $step)
                                        <li class="plan-{{ $step['action'] }}"><strong>{{ $domain }}</strong> <span class="status status-{{ ['allow' => 'pending', 'unblock' => 'ok', 'none' => 'ok'][$step['action']] }}">{{ ['allow' => 'Add allow entry', 'unblock' => 'Remove block', 'none' => 'Nothing needed'][$step['action']] }}</span><small>{{ $step['reason'] }}</small></li>
                                    @endforeach
                                </ul>
                                <small class="hint">Removed blocks are not put back if this access is later revoked. Categories come from Cloudflare, and UniFi's category database can differ; if a site still does not open, add an allow entry from the policy page.</small>
                            </div>
                        @endif
                        <div class="recommendations">
                            @foreach($recommendations as $item)
                                <label class="choice recommendation {{ $item['kind'] }}">
                                    <input type="checkbox" name="domains[]" value="{{ $item['domain'] }}" @checked($first ? in_array($item['domain'], $first['domains'] ?? [], true) : $item['selected'])>
                                    <span><strong>{{ $item['domain'] }}</strong><small><span class="tag">{{ str($item['kind'])->replace('_', ' ')->title() }}</span> {{ $item['reason'] }}</small></span>
                                </label>
                            @endforeach
                        </div>
                        @if(empty($websiteRequest->related_domains))
                            <p class="hint">No page-load data yet. Run a website check for these domains to discover the CDN and API hosts the site depends on.
                                <a href="{{ route('checks.create', ['websites' => implode("\n", $websiteRequest->domains)]) }}">Check now</a></p>
                        @endif
                        <label>Additional hostnames <span class="optional">one per line</span><textarea name="extra_domains" rows="2" placeholder="cdn.example.net">{{ old('extra_domains') }}</textarea></label>
                        @php($margins = \App\Models\PolicyEntry::margins())
                        @if($websiteRequest->recurrence())
                            <div class="notice"><strong>Recurring:</strong> {{ $websiteRequest->recurrence()->label() }}. Entries switch on and off automatically; set-hour windows open {{ \App\Support\Schedule::margin() }} minutes early and close {{ \App\Support\Schedule::margin() }} minutes late.</div>
                        @else
                        <fieldset class="window-fields"><legend>Access window <span class="optional">school time · blank end = permanent</span></legend>
                            <div class="fields two">
                                <label>Allow from<input type="datetime-local" name="access_from" value="{{ old('access_from', ($first ? (isset($first['from']) ? \Illuminate\Support\Carbon::parse($first['from']) : null) : $websiteRequest->requested_from)?->local()->format('Y-m-d\TH:i')) }}"></label>
                                <label>Allow until<input type="datetime-local" name="access_until" value="{{ old('access_until', ($first ? (isset($first['until']) ? \Illuminate\Support\Carbon::parse($first['until']) : null) : $websiteRequest->requested_until)?->local()->format('Y-m-d\TH:i')) }}"></label>
                            </div>
                            <p class="hint">Pre-filled with what was requested; change it to approve a different period. Timed access is put in place {{ $margins['lead'] }} min before the start and taken out {{ $margins['lag'] }} min after the end.</p>
                        </fieldset>
                        @endif
                        <div class="fields two">
                            @php($requestedScope = $websiteRequest->access_scope)
                            @php($baseScope = $firstScope ?? $requestedScope)
                            @php($chosenScope = old('scope', $baseScope?->siteWide ? ['site'] : ($baseScope?->types ?? [])))
                            <fieldset class="scope-choice"><legend>Apply to</legend>
                                @foreach(\App\Models\NetworkType::query()->ordered()->get() as $type)
                                    <label class="choice compact"><input type="checkbox" name="scope[]" value="{{ $type->key }}" @checked(in_array($type->key, (array) $chosenScope, true))><span>{{ $type->name }}{{ $requestedScope?->includes($type->key) && ! $requestedScope->siteWide ? ' (requested)' : '' }}</span></label>
                                @endforeach
                                <label class="choice compact"><input type="checkbox" name="scope[]" value="site" @checked(in_array('site', (array) $chosenScope, true))><span>Site-wide (every network){{ $requestedScope?->siteWide ? ' (requested)' : '' }}</span></label>
                            </fieldset>
                            <label>Note to requester <span class="optional">optional</span><input name="message" value="{{ old('action') === 'approve' ? old('message') : '' }}"></label>
                        </div>
                        @php($hoursPolicies = $websiteRequest->isBlock() ? collect() : \App\Models\FilterPolicy::query()->main()->where('enabled', true)->whereNotNull('provider_reference')->whereHas('hoursLayer', fn ($query) => $query->where('enabled', true)->whereNotNull('provider_reference'))->with('hoursLayer')->get())
                        @if($hoursPolicies->isNotEmpty())
                            <fieldset class="hours-choice"><legend>When</legend>
                                <label class="choice compact"><input type="radio" name="hours" value="all" @checked(old('hours', $websiteRequest->hours ?? 'all') !== 'outside')><span><strong>All hours</strong><small>Added to {{ $hoursPolicies->map(fn ($policy) => $policy->provider_name.' and '.$policy->hoursLayer->provider_name)->join('; ') }}, so the school-hours filter does not block it.</small></span></label>
                                <label class="choice compact"><input type="radio" name="hours" value="outside" @checked(old('hours', $websiteRequest->hours) === 'outside')><span><strong>Outside school hours only</strong><small>Kept out of {{ $hoursPolicies->map(fn ($policy) => $policy->hoursLayer->provider_name.' ('.($policy->hoursLayer->sync_details['schedule'] ?? 'its UniFi schedule').')')->join(', ') }}: still blocked there while it is on, if it blocks the site.</small></span></label>
                            </fieldset>
                        @endif
                        <ul class="rule-notes">@foreach($rules->notes as $note)<li>{{ $note }}</li>@endforeach</ul>
                        @php($rule = app(\App\Services\Approval\TwoPersonRule::class))
                        @php($mineFirst = $first && (int) $first['user_id'] === auth()->id())
                        @if(($first ? $mineFirst : ! empty($twoPerson)) && $rule->possible() && $rule->overrideAllowed())
                            <details class="override" @if(old('override')) open @endif>
                                <summary>No other IT officer is available?</summary>
                                <label class="choice compact"><input type="hidden" name="override" value="0"><input type="checkbox" name="override" value="1" @checked(old('override'))><span><strong>Approve without a second IT officer</strong><small>This needs two IT officers ({{ implode('; ', $first['reasons'] ?? $twoPerson) }}). Use it only when nobody else can approve in time. The other IT officers are emailed so someone reviews it, and it is recorded in the audit log.</small></span></label>
                                <label>Why no one else is available<input name="override_reason" maxlength="1000" value="{{ old('override_reason') }}" placeholder="Only IT officer on site during exams; the other is on leave"></label>
                            </details>
                        @endif
                        @if($mineFirst)
                            <div class="form-actions"><button class="button primary">Approve alone (tick the box above)</button></div>
                        @else
                            <div class="form-actions"><button class="button primary">{{ $first ? 'Give the second approval' : ($websiteRequest->isBlock() ? 'Block selected hostnames' : 'Approve selected hostnames') }}</button></div>
                        @endif
                    </form>
                </section>
            @endif

            @if($isIt && $entries->isNotEmpty())
                <section class="panel">
                    <div class="panel-heading"><div><h2>Policy entries</h2><p>Where this request's hostnames sit in the managed lists.</p></div><a href="{{ route('admin.policy.index') }}">Policies</a></div>
                    <div class="table-wrap compact"><table><thead><tr><th>Hostname</th><th>List</th><th>Window</th></tr></thead><tbody>
                        @foreach($entries as $entry)
                            <tr><td><strong>{{ $entry->domain }}</strong></td><td>{{ $entry->policyList->name }}<small>{{ $entry->policyList->scopeLabel() }}</small></td><td>{{ $entry->starts_at?->local()->format('j M g:i a') ?? 'Now' }} → {{ $entry->expires_at?->local()->format('j M Y g:i a') ?? 'Permanent' }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                </section>
            @endif

            @if($isIt && $existing->isNotEmpty())
                <section class="panel">
                    <h2>Already in policy</h2>
                    <p class="section-copy">These requested hostnames, or a parent domain, already appear in a managed list.@if(!$websiteRequest->isBlock() && $existing->flatten()->contains(fn ($entry) => $entry->policyList->kind === \App\Enums\ListKind::Block)) Approving overrides a block that came from a feed or was imported from the controller. A block IT added on purpose to a more specific list (one policy) still wins; remove it there if the site should open.@endif</p>
                    @foreach($existing as $domain => $found)
                        <div class="list-row static"><span><strong>{{ $domain }}</strong><small>@foreach($found as $entry){{ $entry->domain }} in {{ $entry->policyList->name }} ({{ $entry->policyList->kind->value }})@if(!$loop->last) · @endif @endforeach</small></span></div>
                    @endforeach
                </section>
            @endif

            @if(!$isIt && $status === \App\Enums\RequestStatus::NeedsInformation && $websiteRequest->requester_id === auth()->id())
                <section class="panel reply-panel">
                    <h2>IT needs more information</h2>
                    <form method="post" action="{{ route('requests.reply', $websiteRequest) }}">@csrf
                        <label>Your response<textarea name="message" rows="4" minlength="10" required></textarea></label>
                        <div class="form-actions"><button class="button primary">Send response</button></div>
                    </form>
                </section>
            @endif

            <section class="panel timeline">
                <h2>Activity</h2>
                @foreach($websiteRequest->events->sortByDesc('created_at') as $event)
                    <div @class(['timeline-row', 'internal' => $event->internal])>
                        <span class="timeline-dot"></span>
                        <div>
                            <strong>{{ str($event->type)->replace('_', ' ')->title() }}</strong>@if($event->internal)<span class="tag">Internal</span>@endif
                            <p class="prose">{{ $event->message }}</p>
                            <small>{{ $event->actor?->name ?? 'System' }} · {{ $event->created_at->local()->format('j M Y, g:i a') }}</small>
                        </div>
                    </div>
                @endforeach
            </section>
        </div>

        @if($isIt)
            <aside class="stack">
                <section class="panel action-panel">
                    <h2>Assignment</h2>
                    <p class="section-copy">{{ $websiteRequest->assignedTo ? 'Assigned to '.$websiteRequest->assignedTo->name.($websiteRequest->assigned_at ? ' · '.$websiteRequest->assigned_at->diffForHumans() : '') : 'Nobody owns this request yet.' }}</p>
                    @if($websiteRequest->assigned_to_id !== auth()->id())
                        <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf<input type="hidden" name="action" value="assign_me"><button class="button secondary full">Assign to me</button></form>
                    @endif
                    <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                        <input type="hidden" name="action" value="assign">
                        <label>Reassign to
                            <select name="assignee_id" required>
                                <option value="">Choose IT staff…</option>
                                @foreach($itStaff as $member)
                                    <option value="{{ $member->id }}" @selected($websiteRequest->assigned_to_id === $member->id)>{{ $member->name }} · {{ $member->email }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="button secondary full">Reassign</button>
                    </form>
                    @if($websiteRequest->assigned_to_id)
                        <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf<input type="hidden" name="action" value="unassign"><button class="link-button muted">Remove assignment</button></form>
                    @endif
                </section>

                @if($status->isOpen())
                    <section class="panel action-panel">
                        <h2>Ask or decline</h2>
                        <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                            <label>Message to requester<textarea name="message" rows="4" required>{{ in_array(old('action'), ['information', 'reject'], true) ? old('message') : '' }}</textarea></label>
                            <div class="button-row">
                                <button class="button secondary" name="action" value="information">Request information</button>
                                <button class="button danger" name="action" value="reject" data-confirm="Reject this request?">Reject</button>
                            </div>
                        </form>
                    </section>
                @endif

                @if(in_array($status, [\App\Enums\RequestStatus::Approved, \App\Enums\RequestStatus::ActionRequired, \App\Enums\RequestStatus::Failed], true))
                    <section class="panel action-panel highlight">
                        <h2>Apply in the controller</h2>
                        @if($canPush)
                            <p class="section-copy">Push the change to every linked content filter. The request is marked applied once each one allows these hostnames.</p>
                            <form method="post" action="{{ route('admin.policy.networks.push') }}">@csrf<button class="button primary full" data-confirm="Push pending changes to every linked content filter?">Push to linked filters</button></form>
                            <p class="hint">Or apply by hand:</p>
                        @else
                            <p class="section-copy">Add these hostnames to the content-filter {{ $websiteRequest->isBlock() ? 'block' : 'allow' }} list of each policy below, then mark the request applied.</p>
                        @endif
                        <ul class="policy-targets">@foreach($policies as $target)<li><a href="{{ route('admin.policy.policies.show', $target) }}">{{ $target->name }}</a> <small>{{ $target->typeLabel() }}{{ $target->provider_reference ? ' · '.$target->provider_reference : '' }}</small></li>@endforeach</ul>
                        <textarea class="mono" rows="4" readonly id="apply-domains">{{ implode("\n", $websiteRequest->effectiveDomains()) }}</textarea>
                        <button type="button" class="button small secondary" data-copy="#apply-domains">Copy hostnames</button>
                        <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                            <input type="hidden" name="action" value="applied">
                            <label>Note <span class="optional">optional</span><input name="message"></label>
                            <button class="button primary full">Mark applied</button>
                        </form>
                    </section>
                @endif

                @if($status->isGranted())
                    <section class="panel action-panel">
                        <h2>Access window</h2>
                        <p class="section-copy">{{ $websiteRequest->windowLabel() }}</p>
@unless($websiteRequest->recurrence())
                        <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                            <input type="hidden" name="action" value="window">
                            <label>Allow from<input type="datetime-local" name="access_from" value="{{ $websiteRequest->access_from?->local()->format('Y-m-d\TH:i') }}"></label>
                            <label>Allow until <span class="optional">blank = permanent</span><input type="datetime-local" name="access_until" value="{{ $websiteRequest->expires_at?->local()->format('Y-m-d\TH:i') }}"></label>
                            <label>Note to requester <span class="optional">optional</span><input name="message"></label>
                            <button class="button secondary full">Change window</button>
                        </form>
                        @endunless
                    </section>

                    <section class="panel action-panel">
                        <h2>Revoke access</h2>
                        <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                            <input type="hidden" name="action" value="revoke">
                            <label>Reason<textarea name="message" rows="3" required></textarea></label>
                            <button class="button danger full" data-confirm="Revoke this access and remove its policy entries?">Revoke</button>
                        </form>
                    </section>
                @endif

                <section class="panel action-panel">
                    <h2>Internal note</h2>
                    <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}">@csrf
                        <input type="hidden" name="action" value="note">
                        <label>Visible to IT only<textarea name="message" rows="3" required></textarea></label>
                        <button class="button secondary full">Add note</button>
                    </form>
                </section>
            </aside>
        @endif
    </div>
</x-layouts.app>
