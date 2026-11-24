<x-layouts.app title="Quick block or allow">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Quick block or allow</h1>
            <p>Block or allow a site on several networks at once, without a request. You see exactly what changes before anything does.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.policy.index') }}">Policies &amp; lists</a>
    </div>

    @if(session('quick_publish'))
        <div class="notice">Automatic pushing is off, so UniFi doesn't have this yet.
            <form method="post" action="{{ route('admin.policy.changes.publish') }}" class="publish-inline">@csrf<button class="button primary small">Publish now</button></form>
            <a href="{{ route('admin.policy.changes.index') }}">See every waiting change</a> (Publish now sends all of them).</div>
    @endif

    <form class="panel" method="post" action="{{ route('admin.policy.quick.preview') }}">@csrf
        <label>Hostnames <span class="optional">one per line</span><textarea name="domains" rows="3" required class="mono" placeholder="gemini.google.com">{{ old('domains', $input['domains']) }}</textarea></label>
        <label class="choice compact"><input type="hidden" name="include_www" value="0"><input type="checkbox" name="include_www" value="1" @checked(old('include_www', $input['include_www'] ?? true))><span><strong>Include www. and the bare domain</strong></span></label>
        <fieldset class="hours-choice"><legend>Action</legend>
            <label class="choice compact"><input type="radio" name="kind" value="block" @checked(old('kind', $input['kind']) === 'block')><span><strong>Block</strong></span></label>
            <label class="choice compact"><input type="radio" name="kind" value="allow" @checked(old('kind', $input['kind']) === 'allow')><span><strong>Allow</strong></span></label>
        </fieldset>
        <fieldset class="scope-choice"><legend>On</legend>
            @foreach($types as $type)
                <label class="choice compact"><input type="checkbox" name="types[]" value="{{ $type->key }}" @checked(in_array($type->key, (array) old('types', $input['types']), true))><span>{{ $type->name }}</span></label>
            @endforeach
            <label class="choice compact"><input type="checkbox" name="types[]" value="site" @checked(in_array('site', (array) old('types', $input['types']), true))><span><strong>Everywhere</strong> (site-wide; needs a second IT officer)</span></label>
            @error('types')<p class="field-error">{{ $message }}</p>@enderror
        </fieldset>
        @if($hoursLayers->isNotEmpty())
            <fieldset class="hours-choice"><legend>When</legend>
                <label class="choice compact"><input type="radio" name="when" value="all" @checked(old('when', $input['when']) === 'all')><span><strong>All the time</strong></span></label>
                <label class="choice compact"><input type="radio" name="when" value="school" @checked(old('when', $input['when']) === 'school')><span><strong>School hours only</strong> <small>blocks only: into {{ $hoursLayers->flatten()->map(fn ($layer) => ($layer->provider_name ?: $layer->name).' ('.($layer->sync_details['schedule'] ?? 'its schedule').')')->join(', ') }}</small></span></label>
                <label class="choice compact"><input type="radio" name="when" value="outside" @checked(old('when', $input['when']) === 'outside')><span><strong>Outside school hours only</strong> <small>allows only: kept out of the school-hours filters</small></span></label>
            </fieldset>
        @endif
        <div class="form-actions"><button class="button primary">Preview</button></div>
    </form>

    @if($plan)
        <section class="panel">
            <div class="panel-heading"><div><h2>What this changes</h2>
                <p><strong>{{ $plan['kind']->value === 'block' ? 'Block' : 'Allow' }}</strong> {{ implode(', ', $plan['domains']) }}
                    on {{ $plan['scope']->label() }}{{ $plan['when'] === 'school' ? ', in school hours only' : ($plan['when'] === 'outside' ? ', outside school hours only' : ', all the time') }}.</p></div></div>
            <form method="post" action="{{ route('admin.policy.quick.apply') }}">@csrf
                <input type="hidden" name="domains" value="{{ $input['domains'] }}">
                <input type="hidden" name="kind" value="{{ $input['kind'] }}">
                @foreach($input['types'] as $type)<input type="hidden" name="types[]" value="{{ $type }}">@endforeach
                <input type="hidden" name="when" value="{{ $input['when'] }}">
                <input type="hidden" name="include_www" value="{{ $input['include_www'] ? 1 : 0 }}">

                <h3>Lists</h3>
                @if($plan['lists']->isEmpty())
                    <p class="alert error">No list takes this: none of the chosen networks has a school-hours filter. Choose <em>All the time</em>, or other networks.</p>
                @else
                    <ul>@foreach($plan['lists'] as $list)<li>{{ $list->name }} <small class="hint">{{ $list->scopeLabel() }}</small></li>@endforeach</ul>
                @endif
                @if($plan['skipped'])<p class="hint">{{ implode(', ', $plan['skipped']) }}: no school-hours filter, so nothing is added there.</p>@endif
                @if($plan['already'])<p class="hint">Already there: {{ implode(', ', $plan['already']) }}.</p>@endif

                <h3>UniFi filters that change when published</h3>
                <ul>@forelse($plan['filters'] as $filter)<li>{{ $filter['name'] }}@if($filter['schedule']) <small class="hint">{{ $filter['schedule'] }}</small>@endif</li>@empty<li class="hint">None linked yet.</li>@endforelse</ul>

                @if($plan['categories'])
                    <h3>Categories</h3>
                    @foreach($plan['categories'] as $domain => $hits)
                        <p class="hint">{{ $domain }}: {{ $plan['kind']->value === 'block' ? 'already blocked by' : 'blocked by category' }} {{ collect($hits)->map(fn ($hit) => $hit['category'].' in '.$hit['filter'].($hit['school_hours'] ? ' (school hours)' : ''))->unique()->join(', ') }}.{{ $plan['kind']->value === 'allow' ? ' The allow entry lets it through there.' : ' An entry may not be needed.' }}</p>
                    @endforeach
                @endif

                @if($plan['conflicts'])
                    <h3>Entries that say the opposite</h3>
                    <p class="hint">An entry added on purpose to a more specific list beats this change. Tick the ones to remove.</p>
                    <ul class="check-list">
                        @foreach($plan['conflicts'] as $conflict)
                            @php($entry = $conflict['entry'])
                            <li>
                                @if($conflict['removable'])
                                    <label class="choice compact"><input type="checkbox" name="remove[]" value="{{ $entry->id }}" checked><span><strong>{{ $entry->domain }}</strong> in {{ $entry->policyList->name }}<small>{{ $entry->source->value }}{{ $entry->note ? ' · '.$entry->note : '' }}</small></span></label>
                                @elseif($entry->websiteRequest)
                                    <strong>{{ $entry->domain }}</strong> in {{ $entry->policyList->name }}: from <a href="{{ route('requests.show', $entry->websiteRequest) }}">{{ $entry->websiteRequest->reference }}</a>. Revoke the request to remove it.
                                @else
                                    <strong>{{ $entry->domain }}</strong> in {{ $entry->policyList->name }}{{ $conflict['feed'] ? ' (a blocklist feed). This change outranks it.' : '.' }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="fields two">
                    <label>Until <span class="optional">blank = permanent</span><input type="datetime-local" name="expires_at"></label>
                    <label>Note <span class="optional">why</span><input name="note" maxlength="500"></label>
                </div>
                @if($plan['two_person'])<p class="notice">Everywhere changes a site-wide list, so it waits for a second IT officer under Changes.</p>@endif
                @foreach($plan['notes'] as $note)<p class="hint">{{ $note }}</p>@endforeach
                <div class="form-actions"><button class="button primary" @disabled($plan['lists']->isEmpty())>{{ $plan['kind']->value === 'block' ? 'Block it' : 'Allow it' }}</button></div>
            </form>
        </section>
    @endif
</x-layouts.app>
