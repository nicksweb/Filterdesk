<x-layouts.app title="Version {{ $release->label() }}">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.policy.changes.index') }}">Changes</a> · Policy version</p>
            <h1>{{ $release->label() }}</h1>
            <p>{{ $release->triggerLabel() }} · {{ $release->created_at->local()->format('j M Y g:i a') }} · {{ $release->user?->name ?? 'Automatic' }} · {{ $release->summary }}</p>
        </div>
        @if($release->canRollBack())
            <form method="post" action="{{ route('admin.policy.versions.rollback', $release) }}">@csrf<button class="button secondary" data-confirm="Put the content filters in {{ $release->label() }} back as they were before it?">Roll back {{ $release->label() }}</button></form>
        @endif
    </div>
    @if($rolledBackBy)<div class="alert">Rolled back by {{ $rolledBackBy->label() }} on {{ $rolledBackBy->created_at->local()->format('j M Y g:i a') }}.</div>@endif
    @if($release->rollbackOf)<div class="alert">This version rolled back <a href="{{ route('admin.policy.versions.show', $release->rollbackOf) }}">{{ $release->rollbackOf->label() }}</a>.</div>@endif

    @foreach($release->pushes as $push)
        <section class="panel policy-section">
            <h2>{{ $push->provider_name }} <small>{{ $push->filterPolicy?->name }}</small></h2>
            <p><span class="status status-{{ $push->status === 'ok' ? 'ok' : 'failed' }}">{{ $push->status === 'ok' ? 'Written' : 'Failed' }}</span> {{ $push->message }}</p>
            @foreach(['allow_add' => 'Allow +', 'allow_remove' => 'Allow −', 'block_add' => 'Block +', 'block_remove' => 'Block −'] as $key => $label)
                @if(!empty($push->changes[$key]))
                    <p><strong>{{ $label }}</strong> {{ count($push->changes[$key]) }}: @foreach(array_slice($push->changes[$key], 0, 200) as $domain)<code>{{ $domain }}</code>@if(!$loop->last), @endif @endforeach{{ count($push->changes[$key]) > 200 ? ' and '.(count($push->changes[$key]) - 200).' more' : '' }}</p>
                @endif
            @endforeach
        </section>
    @endforeach
    @foreach($release->firewall ?? [] as $item)
        <section class="panel policy-section">
            <h2>{{ $item['name'] }} <small>Firewall IP list</small></h2>
            <p><span class="status status-{{ $item['ok'] ? 'ok' : 'failed' }}">{{ $item['ok'] ? 'Written' : 'Failed' }}</span> {{ $item['message'] }}</p>
        </section>
    @endforeach
</x-layouts.app>
