<x-layouts.app title="Domain scanner">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div>
            <p class="eyebrow">Web filtering</p>
            <h1>Domain scanner</h1>
            <p>Find every hostname a site uses, then allow or block them together. DNS filtering only sees hostnames, so a site is only fully allowed or blocked when all of them are listed.</p>
        </div>
    </div>

    <section class="panel policy-section">
        <form method="post" action="{{ route('admin.policy.scan.store') }}">@csrf
            <label>Websites <span class="optional">up to 5, one per line</span><textarea name="websites" rows="3" class="mono" required placeholder="https://www.example.com">{{ old('websites', $websites ?? '') }}</textarea></label>
            <label class="choice compact"><input type="checkbox" name="certificates" value="1" @checked(old('certificates', $certificates ?? true))><span><strong>Include subdomains from certificate-transparency logs</strong><small>Looks up the registrable domain at crt.sh. It finds hosts a single page load never touches, such as apps, game servers and mirrors. Useful before blocking a site.</small></span></label>
            <div class="form-actions"><button class="button primary">Scan</button></div>
        </form>
    </section>

    @if($scan)
        <section class="panel table-wrap policy-section">
            <div class="panel-heading"><div><h2>{{ count($scan['hosts']) }} hostname(s) found</h2><p>Scanned {{ implode(', ', $scan['apexes']) }} in {{ number_format($scan['duration_ms'] / 1000, 1) }}s. Hosts loaded by the page are selected; certificate-only hosts may be stale, so review them.</p></div></div>
            @foreach($scan['notes'] as $note)<div class="notice">{{ $note }}</div>@endforeach

            <form method="post" action="{{ route('admin.policy.entries.store') }}">@csrf
                <div class="button-row"><button type="button" class="button small secondary" data-check-all="scan-host">Select all</button><button type="button" class="button small secondary" data-check-none="scan-host">Select none</button></div>
                <table class="scan-table">
                    <thead><tr><th></th><th>Hostname</th><th>Found by</th><th>Networks</th><th>Already listed</th></tr></thead>
                    <tbody>
                        @foreach(collect($scan['hosts'])->groupBy(fn ($host) => $host['same_site'] ? 'Same site: '.$host['apex'] : 'Third-party hosts') as $group => $hosts)
                            <tr class="scan-group"><td colspan="5">{{ $group }}</td></tr>
                            @foreach($hosts as $host)
                                <tr>
                                    <td><input type="checkbox" name="hosts[]" value="{{ $host['host'] }}" data-group="scan-host" aria-label="Select {{ $host['host'] }}" @checked(in_array($host['origin'], ['requested', 'apex', 'page_load'], true))></td>
                                    <td><strong>{{ $host['host'] }}</strong>@if(!$host['same_site'] && $host['apex'])<small>{{ $host['apex'] }}</small>@endif</td>
                                    <td>{{ ['requested' => 'Entered', 'apex' => 'Domain', 'page_load' => 'Page load', 'certificate' => 'Certificate'][$host['origin']] ?? $host['origin'] }}</td>
                                    <td>@if($host['origin'] === 'page_load'){{ $host['failed_on'] ? 'Failed on '.implode(', ', array_map('ucfirst', $host['failed_on'])) : 'Loaded everywhere' }}@else<span class="hint">—</span>@endif</td>
                                    <td>@forelse($host['listed'] as $listed)<span class="status status-{{ $listed['kind'] === 'allow' ? 'ok' : 'rejected' }}">{{ $listed['list'] }}</span> @empty<span class="hint">No</span>@endforelse</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>

                <div class="fields two">
                    <label>Add selected to
                        <select name="policy_list_id" required>
                            @foreach($lists as $list)<option value="{{ $list->id }}">{{ $list->name }} ({{ $list->scopeLabel() }} · {{ $list->kind->value }})</option>@endforeach
                        </select>
                    </label>
                    <label>Note <span class="optional">why</span><input name="note" value="Scanned {{ implode(', ', $scan['apexes']) }}"></label>
                </div>
                <div class="fields two">
                    <label>Starts <span class="optional">optional</span><input type="datetime-local" name="starts_at"></label>
                    <label>Expires <span class="optional">blank = permanent</span><input type="datetime-local" name="expires_at"></label>
                </div>
                <ul class="rule-notes">@foreach($rules->notes as $note)<li>{{ $note }}</li>@endforeach</ul>
                <div class="form-actions"><button class="button primary">Add selected hostnames</button></div>
            </form>
        </section>
    @endif
</x-layouts.app>
