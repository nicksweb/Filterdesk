<x-layouts.app title="Access explorer">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div>
            <p class="eyebrow">Policies</p>
            <h1>Access explorer</h1>
            <p>Who can reach a site, from which network, at what time, and which step decides it.</p>
        </div>
    </div>

    <form method="get" class="panel filter-bar explorer-form">
        <label class="grow">Site<input name="site" value="{{ $site }}" placeholder="youtube.com" required></label>
        <label>Network type<select name="type"><option value="">Every type</option>@foreach($types as $option)<option value="{{ $option->key }}" @selected($type === $option->key)>{{ $option->name }}</option>@endforeach</select></label>
        <label>At <span class="optional">school time · blank = now</span><input type="datetime-local" name="at" value="{{ $at?->local()->format('Y-m-d\TH:i') }}"></label>
        <button class="button primary">Explain</button>
    </form>

    @foreach($results as $result)
        <section class="panel policy-section explorer-result">
            <div class="panel-heading">
                <div><h2>{{ $result['type']->name }}: <span class="verdict verdict-{{ $result['verdict'] }}">{{ ucfirst($result['verdict']) }}</span></h2><p>{{ $result['reason'] }}@if($result['pending']) <strong>A change is waiting to be published.</strong>@endif</p></div>
                @if($result['evidence'])<span class="hint">Probe {{ $result['evidence']['profile'] }}: <span class="status status-{{ $result['evidence']['status'] === 'allowed' ? 'ok' : 'failed' }}">{{ $result['evidence']['status'] }}</span> {{ $result['evidence']['when']->diffForHumans() }}</span>@endif
            </div>
            @foreach($result['policies'] as $policy)
                <h3>{{ $policy['policy']->name }}@if($policy['filter']) <small>· {{ $policy['filter'] }}</small>@endif</h3>
                <ol class="trace">
                    @foreach($policy['steps'] as $step)
                        <li class="trace-{{ $step['outcome'] }} @if(($step['key'] === 'category' && $policy['deciding'] === 'category') || ($step['key'] === 'outcome')) deciding @endif"><strong>{{ $step['label'] }}</strong> {{ $step['detail'] }}</li>
                    @endforeach
                </ol>
            @endforeach
            @if($result['firewall'])<p class="network-status warn">Its address is in the firewall IP list {{ implode(', ', $result['firewall']) }}, which blocks it regardless of the content filter.</p>@endif
            @if(! $result['evidence'])<p class="hint">No recent probe check from this network. <a href="{{ route('checks.create', ['websites' => $result['domain']]) }}">Check it now</a> to confirm what the network really does.</p>@endif
        </section>
    @endforeach

    <section class="panel policy-section">
        <div class="panel-heading"><div><h2>What if</h2><p>Before changing a filter's categories, see which approved sites it would cut off and which keep working through an allow entry. Uses the categories saved on requests, so it makes no lookups.</p></div></div>
        <form method="get" class="filter-bar">
            <input type="hidden" name="site" value="{{ $site }}">
            <label>Change<select name="change"><option value="block" @selected(($whatIfInput['change'] ?? 'block') === 'block')>Block</option><option value="unblock" @selected(($whatIfInput['change'] ?? '') === 'unblock')>Unblock</option></select></label>
            <label class="grow">Category
                @if($categories)
                    <select name="category">@foreach($categories as $category)<option value="{{ $category }}" @selected(($whatIfInput['category'] ?? '') === $category)>{{ ucfirst(strtolower(str_replace('_', ' ', $category))) }}</option>@endforeach</select>
                @else
                    <input name="category" value="{{ $whatIfInput['category'] ?? '' }}" placeholder="SOCIAL_NETWORKS">
                @endif
            </label>
            <label>For<select name="whatif_type">@foreach($types as $option)<option value="{{ $option->key }}" @selected(($whatIfInput['whatif_type'] ?? '') === $option->key)>{{ $option->name }}</option>@endforeach</select></label>
            <button class="button secondary">Show the effect</button>
        </form>
        @if($whatIf)
            <p class="lead-line"><strong>{{ $whatIf['summary'] }}</strong></p>
            @if($whatIf['cut_off'])<h3>Would be cut off</h3><ul class="plain-list">@foreach($whatIf['cut_off'] as $item)<li><code>{{ $item['domain'] }}</code> <small><a href="{{ route('requests.show', $item['request']) }}">{{ $item['request']->reference }}</a> · approved {{ $item['request']->approved_at?->local()->format('j M Y') }}</small></li>@endforeach</ul><p class="hint">With website categories on, the category guard adds allow entries for these automatically after the change.</p>@endif
            @if($whatIf['kept'])<h3>Keep working (allow entry)</h3><ul class="plain-list">@foreach($whatIf['kept'] as $item)<li><code>{{ $item['domain'] }}</code> <small>{{ $item['list'] }} · {{ $item['request']->reference }}</small></li>@endforeach</ul>@endif
            @if($whatIf['freed'])<h3>Allow entries no longer needed</h3><ul class="plain-list">@foreach($whatIf['freed'] as $item)<li><code>{{ $item['domain'] }}</code> <small>{{ $item['list'] }}</small></li>@endforeach</ul>@endif
            @if($whatIf['open'])<h3>Open requests in this category</h3><ul class="plain-list">@foreach($whatIf['open'] as $item)<li><a href="{{ route('requests.show', $item) }}">{{ $item->reference }}</a> {{ implode(', ', $item->domains) }}</li>@endforeach</ul>@endif
        @endif
    </section>
</x-layouts.app>
