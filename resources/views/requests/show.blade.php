<x-layouts.app title="{{ $websiteRequest->reference }}">
    @php($isIt = auth()->user()->isIt())
    @php($status = $websiteRequest->status)
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ $websiteRequest->reference }}</p>
            <h1>{{ implode(', ', $websiteRequest->domains) }}</h1>
            <p>Requested by {{ $websiteRequest->requester->name }} · {{ $websiteRequest->created_at->local()->format('j M Y, g:i a') }}</p>
        </div>
        <x-status :status="$status" />
    </div>

    <div class="detail-grid">
        <div class="stack">
            <section class="panel">
                <h2>Request details</h2>
                <dl class="details">
                    <div><dt>Requested access</dt><dd>{{ $websiteRequest->access_scope->label() }}</dd></div>
                    <div><dt>Category</dt><dd>{{ $websiteRequest->category }}</dd></div>
                    <div><dt>Assigned to</dt><dd>{{ $websiteRequest->assignedTo?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt>Period</dt><dd>@if($websiteRequest->requested_until){{ $websiteRequest->requested_from?->local()->format('j M Y g:i a') ?? 'As soon as approved' }} – {{ $websiteRequest->requested_until->local()->format('j M Y g:i a') }}@else Permanent @endif</dd></div>
                    @if($websiteRequest->approved_at)
                        <div><dt>Approved</dt><dd>{{ $websiteRequest->approved_at->local()->format('j M Y g:i a') }}{{ $websiteRequest->approvedBy ? ' by '.$websiteRequest->approvedBy->name : '' }}</dd></div>
                        <div><dt>Applied to</dt><dd>{{ $websiteRequest->effectiveScope()->label() }}</dd></div>
                    @endif
                </dl>
                <h3>Use case</h3>
                <p class="prose">{{ $websiteRequest->use_case }}</p>
                @if($websiteRequest->approved_domains)
                    <h3>Allowed hostnames</h3>
                    <div class="chip-list">@foreach($websiteRequest->approved_domains as $domain)<span class="chip">{{ $domain }}</span>@endforeach</div>
                @endif
                @if($websiteRequest->resolution)<h3>Resolution</h3><p class="prose">{{ $websiteRequest->resolution }}</p>@endif
            </section>

            @if($isIt && $status->isOpen())
                <section class="panel">
                    <div class="panel-heading"><div><h2>Approve</h2><p>Choose the hostnames to allow. DNS filtering only sees hostnames, so include the bare domain and every subdomain the site needs.</p></div></div>
                    <form method="post" action="{{ route('it.requests.action', $websiteRequest) }}" class="approve-form">
                        @csrf
                        <input type="hidden" name="action" value="approve">
                        <div class="recommendations">
                            @foreach($recommendations as $item)
                                <label class="choice recommendation {{ $item['kind'] }}">
                                    <input type="checkbox" name="domains[]" value="{{ $item['domain'] }}" @checked($item['selected'])>
                                    <span><strong>{{ $item['domain'] }}</strong><small><span class="tag">{{ str($item['kind'])->replace('_', ' ')->title() }}</span> {{ $item['reason'] }}</small></span>
                                </label>
                            @endforeach
                        </div>
                        @if(empty($websiteRequest->related_domains))
                            <p class="hint">No page-load data yet. Run a website check for these domains to discover the CDN and API hosts the site depends on.
                                <a href="{{ route('checks.create', ['websites' => implode("\n", $websiteRequest->domains)]) }}">Check now</a></p>
                        @endif
                        <label>Additional hostnames <span class="optional">one per line</span><textarea name="extra_domains" rows="2" placeholder="cdn.example.net">{{ old('extra_domains') }}</textarea></label>
                        <div class="fields two">
                            <label>Apply to
                                <select name="scope">
                                    @foreach(\App\Enums\AccessScope::cases() as $scope)
                                        <option value="{{ $scope->value }}" @selected(old('scope', $websiteRequest->access_scope->value) === $scope->value)>{{ $scope->label() }}{{ $scope === $websiteRequest->access_scope ? ' (requested)' : '' }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Note to requester <span class="optional">optional</span><input name="message" value="{{ old('action') === 'approve' ? old('message') : '' }}"></label>
                        </div>
                        <ul class="rule-notes">@foreach($rules->notes as $note)<li>{{ $note }}</li>@endforeach</ul>
                        <div class="form-actions"><button class="button primary">Approve selected hostnames</button></div>
                    </form>
                </section>
            @endif

            @if($isIt && $entries->isNotEmpty())
                <section class="panel">
                    <div class="panel-heading"><div><h2>Policy entries</h2><p>Where this request's hostnames sit in the managed lists.</p></div><a href="{{ route('admin.policy.index') }}">Policy lists</a></div>
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
                    <p class="section-copy">These requested hostnames, or a parent domain, already appear in a managed list.</p>
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
                            <p class="section-copy">Add these hostnames to the content-filter allow list of each policy below, then mark the request applied.</p>
                        @endif
                        <ul class="policy-targets">@foreach($policies as $target)<li><a href="{{ route('admin.policy.policies.show', $target) }}">{{ $target->name }}</a> <small>{{ $target->audience->label() }}{{ $target->provider_reference ? ' · '.$target->provider_reference : '' }}</small></li>@endforeach</ul>
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
