<x-layouts.app title="Settings">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>

    <div class="page-heading">
        <div><p class="eyebrow">Administration</p><h1>Settings</h1><p>Reconfigure the installation. Secrets are write-only: leave a secret blank to keep the stored value.</p></div>
    </div>

    <nav class="tabs" aria-label="Settings sections">
        @foreach($sections as $key => $label)
            <a href="{{ route('admin.settings.show', $key) }}" @class(['active' => $section === $key])>{{ $label }}</a>
        @endforeach
    </nav>

    @if($section === 'general')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'general') }}">@csrf @method('PUT')
            <label>School or organisation name<input name="school_name" required value="{{ old('school_name', $settings->get('school.name')) }}"></label>
            <div class="fields two">
                <label>Timezone<select name="timezone">@foreach(timezone_identifiers_list() as $zone)<option @selected(old('timezone', $settings->get('school.timezone', config('app.timezone'))) === $zone)>{{ $zone }}</option>@endforeach</select></label>
                <label>Public application URL<input name="app_url" type="url" required value="{{ old('app_url', $settings->get('school.app_url', config('app.url'))) }}"><small>Used for email links and the OIDC redirect URI.</small></label>
            </div>
            <label>Schoolbox origin <span class="optional">optional</span><input name="schoolbox_origin" type="url" placeholder="https://schoolbox.example.edu.au" value="{{ old('schoolbox_origin', $settings->get('school.schoolbox_origin')) }}"><small>Only this exact origin may embed the app. Setting it switches session cookies to SameSite=None; Secure.</small></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>

    @elseif($section === 'authentication')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'authentication') }}">@csrf @method('PUT')
            <label class="choice"><input type="hidden" name="oidc_enabled" value="0"><input type="checkbox" name="oidc_enabled" value="1" @checked(old('oidc_enabled', $oidc?->enabled))><span><strong>OpenID Connect single sign-on</strong><small>Microsoft Entra ID or any standards-compliant provider.</small></span></label>
            <p class="notice">Redirect URI to register: <code>{{ $redirectUri }}</code></p>
            <label>Issuer URL<input name="oidc_issuer" type="url" placeholder="https://login.microsoftonline.com/&lt;tenant-id&gt;/v2.0" value="{{ old('oidc_issuer', $oidc?->configuration['issuer'] ?? '') }}"></label>
            <div class="fields two">
                <label>Client ID<input name="oidc_client_id" value="{{ old('oidc_client_id', $oidc?->configuration['client_id'] ?? '') }}"></label>
                <label>Client secret <span class="optional">{{ $oidcHasSecret ? 'stored · blank keeps it' : 'not set' }}</span><input name="oidc_client_secret" type="password" autocomplete="new-password"></label>
            </div>
            <label>Allowed email domains <span class="optional">comma separated; blank allows any</span><input name="oidc_allowed_domains" value="{{ old('oidc_allowed_domains', implode(', ', $oidc?->configuration['allowed_domains'] ?? [])) }}"></label>
            <label>Role rules <span class="optional">one per line, first match wins</span>
                <textarea name="role_rules" rows="5" class="mono" placeholder="email_domain:students.example.edu.au=student&#10;group:it-staff-group-object-id=it&#10;group:ITRequests.Admin=admin">{{ old('role_rules', $roleRules) }}</textarea>
                <small><code>email_domain:</code> matches the address domain and its subdomains. <code>group:</code> matches a value in the <code>groups</code> or <code>roles</code> claim (Entra: add a groups claim or app roles to the token).</small>
            </label>
            <div class="fields two">
                <label>Default role for unmatched users<select name="default_role">@foreach(['teacher' => 'Teacher', 'student' => 'Student', 'deny' => 'Refuse sign-in'] as $value => $label)<option value="{{ $value }}" @selected(old('default_role', $oidc?->configuration['default_role'] ?? 'teacher') === $value)>{{ $label }}</option>@endforeach</select></label>
                <label class="choice compact"><input type="hidden" name="sync_roles" value="0"><input type="checkbox" name="sync_roles" value="1" @checked(old('sync_roles', $oidc?->configuration['sync_roles'] ?? false))><span><strong>Re-apply rules at every sign-in</strong><small>Never removes IT or administrator access granted by hand.</small></span></label>
            </div>
            <label class="choice"><input type="hidden" name="emergency_local_login" value="0"><input type="checkbox" name="emergency_local_login" value="1" @checked(old('emergency_local_login', $settings->get('auth.emergency_local_login', true)))><span><strong>Emergency local sign-in</strong><small>Password sign-in for local accounts. Restrict /login at the reverse proxy where possible. Recover from the host with <code>php artisan itrequests:admin-password</code>.</small></span></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>

    @elseif($section === 'email')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'email') }}">@csrf @method('PUT')
            <h2>SMTP</h2>
            <div class="fields two">
                <label>Host <span class="optional">blank logs mail instead of sending</span><input name="mail_host" value="{{ old('mail_host', $smtp?->configuration['host'] ?? '') }}"></label>
                <div class="fields two">
                    <label>Port<input name="mail_port" type="number" value="{{ old('mail_port', $smtp?->configuration['port'] ?? 587) }}"></label>
                    <label>Encryption<select name="mail_encryption">@foreach(['tls' => 'STARTTLS', 'ssl' => 'TLS (SMTPS)', 'none' => 'None'] as $value => $label)<option value="{{ $value }}" @selected(old('mail_encryption', $smtp?->configuration['encryption'] ?? 'tls') === $value)>{{ $label }}</option>@endforeach</select></label>
                </div>
            </div>
            <div class="fields two">
                <label>Username<input name="mail_username" autocomplete="off" value="{{ old('mail_username', $smtp?->credentials['username'] ?? '') }}"></label>
                <label>Password <span class="optional">{{ $smtpHasPassword ? 'stored · blank keeps it' : 'not set' }}</span><input name="mail_password" type="password" autocomplete="new-password"></label>
            </div>
            <div class="fields two">
                <label>From address<input name="mail_from_address" type="email" value="{{ old('mail_from_address', $smtp?->configuration['from'] ?? '') }}"></label>
                <label>From name<input name="mail_from_name" value="{{ old('mail_from_name', $smtp?->configuration['from_name'] ?? '') }}"></label>
            </div>

            <h2>Notifications</h2>
            <label>IT notification address<input name="it_notification_address" type="email" required value="{{ old('it_notification_address', $settings->get('notifications.it_address')) }}"></label>
            <fieldset><legend>Email IT when</legend>
                @foreach(['new_request' => 'A new request is submitted', 'requester_reply' => 'A requester answers a question (sent to the assignee, or IT if unassigned)', 'assignment' => 'A request is assigned to someone', 'filter_sync' => 'A controller content filter is changed outside IT Requests, or cannot be read'] as $event => $label)
                    <label class="choice compact"><input type="checkbox" name="events[]" value="{{ $event }}" @checked($settings->get("notifications.events.{$event}", true))><span>{{ $label }}</span></label>
                @endforeach
            </fieldset>
            <fieldset><legend>Email requesters when</legend>
                <p class="hint">Requesters are always emailed when their request is approved, declined, needs more information, goes live, has its access window changed or is revoked.</p>
                @foreach(['submitted' => 'Their request is received (confirmation)', 'expiry_reminder' => 'Temporary access longer than a day ends within 24 hours', 'expired' => 'Temporary access has ended'] as $event => $label)
                    <label class="choice compact"><input type="checkbox" name="requester_events[]" value="{{ $event }}" @checked($settings->get("notifications.requester.{$event}", true))><span>{{ $label }}</span></label>
                @endforeach
            </fieldset>
            <div class="fields two">
                <label class="choice compact"><input type="hidden" name="daily_digest" value="0"><input type="checkbox" name="daily_digest" value="1" @checked($settings->get('notifications.daily_digest', true))><span><strong>Daily reconciliation digest</strong><small>Sent only when there are changes to apply.</small></span></label>
                <label>Digest time<input type="time" name="digest_time" value="{{ old('digest_time', $settings->get('notifications.digest_time', '07:30')) }}"></label>
            </div>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.test-email') }}">@csrf
            <h2>Send a test email</h2>
            <p class="section-copy">Sent immediately, not queued, so SMTP errors appear here. Save changes first.</p>
            <div class="search-line"><label>Recipient<input name="address" type="email" required value="{{ auth()->user()->email }}"></label><button class="button secondary">Send test</button></div>
        </form>

    @elseif($section === 'network')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'network') }}">@csrf @method('PUT')
            <div class="fields two">
                <label>Provider<select name="provider"><option value="unifi" @selected(($network?->provider ?? 'unifi') === 'unifi')>Ubiquiti UniFi Network</option><option value="mock" @selected($network?->provider === 'mock')>Simulation (testing only)</option></select></label>
                <label>Mode<select name="mode"><option value="read_only" @selected(($network?->mode ?? 'read_only') === 'read_only')>Read-only (recommended)</option><option value="active" @selected($network?->mode === 'active')>Active: push list changes to linked content filters</option></select><small>Active lets IT push allow and block list changes to the content filters linked under Policy lists → Networks &amp; filters. Each push only changes those lists, is read back, recorded and can be rolled back.</small></label>
            </div>
            @php($connection = old('connection', $network?->configuration['connection'] ?? 'local'))
            <label>Connection
                <select name="connection" data-scope-select>
                    <option value="local" @selected($connection === 'local')>Local console key: the app calls the console directly</option>
                    <option value="cloud" @selected($connection === 'cloud')>Site Manager key (unifi.ui.com): through Ubiquiti's cloud connector</option>
                </select>
                <small>Keys made at unifi.ui.com only work with the cloud connector. Keys made on the console (Network → Settings → Control Plane → Integrations) only work locally.</small>
            </label>
            <label data-scope-field="local">Controller URL<input name="controller_url" type="url" placeholder="https://unifi.example.edu.au" value="{{ old('controller_url', $network?->configuration['controller_url'] ?? '') }}"></label>
            <label data-scope-field="cloud">Console ID <span class="optional">use Discover to list your consoles</span><input name="console_id" list="discovered-consoles" value="{{ old('console_id', $network?->configuration['console_id'] ?? '') }}"><small>The connector requires the key's owner to be an owner of the console, and the console to be online.</small></label>
            @if($discovery && !empty($discovery['consoles']))<datalist id="discovered-consoles">@foreach($discovery['consoles'] as $console)<option value="{{ $console['id'] }}">{{ $console['name'] }}</option>@endforeach</datalist>@endif
            <div class="fields two">
                <label>API key <span class="optional">{{ $networkHasKey ? 'stored · blank keeps it' : 'not set' }}</span><input name="api_key" type="password" autocomplete="new-password"><small>Use a dedicated key for IT Requests.</small></label>
                <label>Site ID<input name="site_id" value="{{ old('site_id', $network?->configuration['site_id'] ?? '') }}" list="discovered-sites"><small>Use Discover to list sites.</small></label>
            </div>
            @if($discovery && $discovery['sites'])<datalist id="discovered-sites">@foreach($discovery['sites'] as $site)<option value="{{ $site['id'] }}">{{ $site['name'] }}</option>@endforeach</datalist>@endif
            <div class="fields two">
                <label>API prefix<input name="api_prefix" value="{{ old('api_prefix', $network?->configuration['api_prefix'] ?? '/proxy/network/integration/v1') }}"></label>
                <label class="choice compact" data-scope-field="local"><input type="hidden" name="verify_tls" value="0"><input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $network?->configuration['verify_tls'] ?? true))><span><strong>Verify TLS certificate</strong><small>Local only; the cloud connector is always verified. Turn off only for a self-signed console while you install its CA.</small></span></label>
            </div>
            <label>DNS filter entry matching
                <select name="entry_matching">
                    <option value="exact" @selected(($network?->configuration['entry_matching'] ?? 'exact') === 'exact')>Explicit: list the domain and each needed subdomain (recommended)</option>
                    <option value="includes_subdomains" @selected(($network?->configuration['entry_matching'] ?? '') === 'includes_subdomains')>An entry also covers its subdomains: collapse redundant subdomains</option>
                </select>
                <small>UniFi content filtering is DNS based and only sees hostnames. The explicit setting is safe on every controller version. Choose the second only after confirming the controller matches subdomains of a listed domain.</small>
            </label>
            <div class="fields two">
                <label>Largest list per content filter <span class="optional">entries</span><input type="number" name="max_filter_entries" min="50" max="100000" value="{{ old('max_filter_entries', $network?->configuration['max_filter_entries'] ?? 100000) }}"><small>Pushes that would make a filter's allow or block list longer than this are refused. UniFi handles up to about 100,000 entries, but its Content Filtering page gets slow to open and edit above a few thousand. A layer keeps big feeds in their own filter.</small></label>
                <label>Largest automatic push <span class="optional">changes</span><input type="number" name="max_automatic_changes" min="10" max="100000" value="{{ old('max_automatic_changes', $network?->configuration['max_automatic_changes'] ?? 1000) }}"><small>Automatic pushes (approvals, schedules, feed refreshes) with more changes than this wait for someone to push them from the policy page.</small></label>
            </div>
            <label class="choice compact"><input type="hidden" name="controller_authoritative" value="0"><input type="checkbox" name="controller_authoritative" value="1" @checked(old('controller_authoritative', $network?->configuration['controller_authoritative'] ?? true))><span><strong>The controller is the source of truth</strong><small>Entries added in UniFi are imported into the policy's own lists, and managed entries removed in UniFi are kept out of that filter, so pushes never undo changes made in UniFi (for example while this app was unavailable). Turn off to only report those changes for review.</small></span></label>
            <label class="choice compact"><input type="hidden" name="auto_push" value="0"><input type="checkbox" name="auto_push" value="1" @checked(old('auto_push', $network?->configuration['auto_push'] ?? false))><span><strong>Push automatically</strong><small>Active mode only. Approvals and revocations are pushed straight away; expiries, scheduled starts and list edits within five minutes. Entries added in UniFi by hand are never removed.</small></span></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>
        <section class="panel form-panel">
            <div class="panel-heading"><div><h2>Test and discover</h2><p>Read-only calls to the official API.{{ $network?->last_tested_at ? ' Last test: '.$network->last_test_status.' · '.$network->last_tested_at->diffForHumans().'.' : '' }}</p></div></div>
            @if($network?->last_test_message)<p class="hint">{{ $network->last_test_message }}</p>@endif
            <div class="button-row">
                <form method="post" action="{{ route('admin.settings.test-network') }}">@csrf<button class="button secondary">Test connection</button></form>
                <form method="post" action="{{ route('admin.settings.discover') }}">@csrf<input type="hidden" name="site_id" value="{{ $network?->configuration['site_id'] ?? '' }}"><button class="button secondary">Discover sites and networks</button></form>
            </div>
            @if($discovery && !empty($discovery['consoles']))
                <h3>Consoles visible to this Site Manager key</h3>
                @foreach($discovery['consoles'] as $console)<div class="list-row static"><span><strong>{{ $console['name'] }}</strong><small><code>{{ $console['id'] }}</code></small></span></div>@endforeach
                <p class="hint">Paste the console's ID into Console ID above, save, then test and discover again.</p>
            @endif
            @if($discovery)
                <div class="fields two policy-editors">
                    <div><h3>Sites</h3>@forelse($discovery['sites'] as $site)<div class="list-row static"><span><strong>{{ $site['name'] }}</strong><small><code>{{ $site['id'] }}</code></small></span></div>@empty<p class="hint">None returned.</p>@endforelse</div>
                    <div><h3>Networks</h3>@forelse($discovery['networks'] as $item)<div class="list-row static"><span><strong>{{ $item['name'] }}</strong><small><code>{{ $item['id'] }}</code>{{ isset($item['vlan']) ? ' · VLAN '.$item['vlan'] : '' }}</small></span></div>@empty<p class="hint">None returned.</p>@endforelse</div>
                </div>
                <p class="hint">Copy network IDs into Network profiles to map each probe to its UniFi network.</p>
            @endif
            <div class="notice">The official UniFi API does not expose content-filter domain lists. The app reads them, and in Active mode updates them, through the Network application's own content-filter endpoint. Link filters to policies under <a href="{{ route('admin.policy.networks.index') }}">Networks &amp; filters</a>, then use <strong>Verify write access</strong> on a policy before the first push.</div>
        </section>

    @elseif($section === 'profiles')
        <form class="panel" method="post" action="{{ route('admin.settings.update', 'profiles') }}">@csrf @method('PUT')
            <p class="section-copy">Each profile is a network path checked by its own probe container. The audience links the profile to filter policies and lists. <em>Probe address</em> is what the container reports about itself: the address and MAC on its VLAN, which you can name or reserve in UniFi. <a href="{{ route('admin.settings.show', ['section' => 'profiles', 'refresh' => 1]) }}">Refresh</a></p>
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Audience</th><th>Probe URL</th><th>UniFi network ID</th><th>Probe address</th><th>Order</th><th>On</th></tr></thead>
                <tbody>
                    @foreach($profiles as $profile)
                        <tr>
                            <td><input name="profiles[{{ $profile->id }}][name]" value="{{ $profile->name }}" required><small><code>{{ $profile->key }}</code></small></td>
                            <td><select name="profiles[{{ $profile->id }}][audience]">@foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}" @selected($profile->audience?->value === $value)>{{ $label }}</option>@endforeach</select></td>
                            <td><input name="profiles[{{ $profile->id }}][probe_url]" value="{{ $profile->probe_url }}" required></td>
                            <td><input name="profiles[{{ $profile->id }}][unifi_network_id]" value="{{ $profile->unifi_network_id }}"></td>
                            <td class="probe-identity">
                                @php($identity = $identities[$profile->id] ?? ['ok' => false, 'message' => 'Not checked.'])
                                @if($identity['ok'] && $identity['vlan'])
                                    <strong>{{ implode(', ', $identity['vlan']['ipv4']) ?: 'no IPv4' }}</strong>
                                    <small>MAC <code>{{ $identity['vlan']['mac'] ?? '?' }}</code> · {{ $identity['vlan']['name'] }} · gateway {{ $identity['gateway'] ?? '?' }}</small>
                                    <small>DNS {{ implode(', ', $identity['dns']) }} ({{ $identity['dns_mode'] }})</small>
                                    @php($expected = $expectedGateways[$profile->unifi_network_id] ?? null)
                                    @if($expected && $identity['gateway'] !== $expected)<small class="network-status warn">Default route is not this network's gateway ({{ $expected }}): the probe is not on its VLAN.</small>@endif
                                    @if($expected && !in_array($expected, $identity['dns'], true))<small class="network-status warn">DNS is not {{ $expected }}, so checks may not see this network's filtering.</small>@endif
                                @elseif($identity['ok'])
                                    <small class="network-status warn">No default route reported.</small>
                                @else
                                    <small class="hint">{{ $identity['message'] }}</small>
                                @endif
                            </td>
                            <td><input type="number" class="narrow" name="profiles[{{ $profile->id }}][sort_order]" value="{{ $profile->sort_order }}"></td>
                            <td><input type="hidden" name="profiles[{{ $profile->id }}][enabled]" value="0"><input type="checkbox" name="profiles[{{ $profile->id }}][enabled]" value="1" @checked($profile->enabled)></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <h3>Add a profile</h3>
            <div class="fields three">
                <label>Name<input name="new[name]" placeholder="Guest Wi-Fi"></label>
                <label>Key<input name="new[key]" placeholder="guest"></label>
                <label>Audience<select name="new[audience]">@foreach(\App\Enums\Audience::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            </div>
            <label>Probe URL<input name="new[probe_url]" placeholder="http://probe-guest:3000"></label>
            <p class="hint">A new probe needs its own container on that network's VLAN. Plan it under <a href="{{ route('admin.policy.setup.show') }}#probes">Guided setup → Extra probes</a>, which generates the service and creates the profile.</p>
            <div class="form-actions"><button class="button primary">Save profiles</button></div>
        </form>

    @elseif($section === 'approval')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'approval') }}">@csrf @method('PUT')
            <label class="choice"><input type="hidden" name="auto_approve_staff" value="0"><input type="checkbox" name="auto_approve_staff" value="1" @checked($settings->get('approval.auto_approve_staff', false))><span><strong>Auto-approve staff-only requests</strong><small>The domains go straight into the Staff allow list. IT still applies them in the controller unless the provider can do it automatically.</small></span></label>
            <label class="choice"><input type="hidden" name="students_can_request" value="0"><input type="checkbox" name="students_can_request" value="1" @checked($settings->get('approval.students_can_request', false))><span><strong>Students may submit requests</strong><small>Students can always run website checks.</small></span></label>
            <label>Default temporary access (days)<input name="default_temporary_days" type="number" min="1" max="365" value="{{ $settings->get('approval.default_temporary_days', 30) }}"></label>
            <div class="fields two">
                <label>Open timed access early (minutes)<input name="window_lead_minutes" type="number" min="0" max="60" value="{{ $settings->get('approval.window_lead_minutes', 5) }}"><small>Allow or block entries go live this long before their start, so the controller has them in place on time.</small></label>
                <label>Close timed access late (minutes)<input name="window_lag_minutes" type="number" min="0" max="60" value="{{ $settings->get('approval.window_lag_minutes', 5) }}"><small>Entries are removed this long after their end. With automatic pushing, changes reach the controller within about a minute.</small></label>
            </div>
            <label>Categories <span class="optional">comma or line separated</span><textarea name="categories" rows="4">{{ implode("\n", $settings->get('approval.categories', [])) }}</textarea></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>
    @endif
</x-layouts.app>
