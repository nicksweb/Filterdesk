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
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'general') }}" enctype="multipart/form-data">@csrf @method('PUT')
            @php($branding = (array) $settings->get('branding', []))
            <div class="fields two">
                <label>Application name <span class="optional">blank: "{{ $settings->get('school.name') }} Filterdesk"</span><input name="app_name" maxlength="60" placeholder="uConnect" value="{{ old('app_name', $branding['app_name'] ?? '') }}"><small>Shown in the header, browser tab and emails.</small></label>
                <label>Initials in the header <span class="optional">up to 3 letters; used when there is no icon</span><input name="brand_mark" maxlength="3" placeholder="IT" value="{{ old('brand_mark', $branding['mark'] ?? '') }}"></label>
                <label class="choice compact"><input type="hidden" name="project_link" value="0"><input type="checkbox" name="project_link" value="1" @checked(old('project_link', $branding['project_link'] ?? true))><span><strong>Link to the open-source project in the footer</strong><small>Shows "Built with Filterdesk" with a link to the project on GitHub at the foot of every page.</small></span></label>
            </div>
            <div class="fields two">
                <label>Browser and header icon <span class="optional">PNG, SVG, WebP, JPEG or ICO, square, up to 512 KB</span><input type="file" name="icon" accept="image/png,image/svg+xml,image/webp,image/jpeg,image/x-icon"></label>
                <div class="icon-preview"><img src="{{ route('brand.icon', ['v' => config('app.icon_version')]) }}" alt="Current icon" width="48" height="48">@if(filled($branding['icon_data'] ?? null))<label class="choice compact"><input type="checkbox" name="remove_icon" value="1"><span>Use the built-in icon again</span></label>@else<small class="hint">Built-in icon</small>@endif</div>
            </div>
            <label>School or organisation name<input name="school_name" required value="{{ old('school_name', $settings->get('school.name')) }}"></label>
            <div class="fields two">
                <label>Timezone<select name="timezone">@foreach(timezone_identifiers_list() as $zone)<option @selected(old('timezone', $settings->get('school.timezone', config('app.timezone'))) === $zone)>{{ $zone }}</option>@endforeach</select></label>
                <label>Public application URL<input name="app_url" type="url" required value="{{ old('app_url', $settings->get('school.app_url', config('app.url'))) }}"><small>Used for email links and the OIDC redirect URI.</small></label>
            </div>
            <label>Embedding origin (Schoolbox or another portal) <span class="optional">optional</span><input name="schoolbox_origin" type="url" placeholder="https://schoolbox.example.edu.au" value="{{ old('schoolbox_origin', $settings->get('school.schoolbox_origin')) }}"><small>Only this exact origin may embed the app. Setting it switches session cookies to SameSite=None; Secure.</small></label>
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
            <details class="hint oidc-advanced" @if($oidcLastError) open @endif><summary>Provider details and advanced options</summary>
                @if($oidcProvider)
                    <p>Provider: token endpoint authentication {{ implode(', ', (array) ($oidcProvider['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic (default)'])) }} · PKCE {{ in_array('S256', (array) ($oidcProvider['code_challenge_methods_supported'] ?? []), true) ? 'supported (used)' : 'not advertised' }} · userinfo {{ filled($oidcProvider['userinfo_endpoint'] ?? null) ? 'yes' : 'no (ID token only)' }} · scopes {{ implode(' ', array_slice((array) ($oidcProvider['scopes_supported'] ?? []), 0, 12)) ?: 'not listed' }}</p>
                @endif
                <div class="fields three">
                    <label>Extra scopes <span class="optional">space separated</span><input name="oidc_scopes" value="{{ old('oidc_scopes', implode(' ', $oidc?->configuration['scopes'] ?? [])) }}" placeholder="https://scopes.cloudwork.id/profile"><small>openid, profile and email are always requested. CloudWork ID: add <code>https://scopes.cloudwork.id/profile</code> for its custom claims.</small></label>
                    <label>Claims holding groups or roles <span class="optional">space separated · blank = automatic</span><input name="oidc_group_claims" value="{{ old('oidc_group_claims', implode(' ', $oidc?->configuration['group_claims'] ?? [])) }}" placeholder="automatic"><small>Blank uses every claim whose name ends in groups, role or roles (Entra's <code>groups</code>/<code>roles</code>, CloudWork's <code>https://cloudwork.id/claims/groups</code> and <code>…/role</code>). <code>group:</code> rules match a value in any of them.</small></label>
                    <label>Client authentication<select name="oidc_token_auth">@foreach(['auto' => 'Automatic (from the provider)', 'basic' => 'client_secret_basic (header)', 'post' => 'client_secret_post (form body)'] as $value => $label)<option value="{{ $value }}" @selected(old('oidc_token_auth', $oidc?->configuration['token_auth'] ?? 'auto') === $value)>{{ $label }}</option>@endforeach</select></label>
                </div>
                @if($oidcLastClaims)
                    <p>Claims in the last sign-in ({{ \Illuminate\Support\Carbon::parse($oidcLastClaims['at'])->local()->format('j M Y g:i a') }}), for writing rules; personal values are not shown:</p>
                    <ul class="claim-list">@foreach($oidcLastClaims['claims'] as $claim => $value)<li><code>{{ $claim }}</code> {{ $value }}</li>@endforeach</ul>
                @endif
                @if($oidcLastError)<div class="alert error">Last sign-in failure ({{ \Illuminate\Support\Carbon::parse($oidcLastError['at'])->local()->format('j M Y g:i a') }}): {{ $oidcLastError['message'] }}</div>@endif
            </details>
            @if($oidcWarning)<div class="alert warning">{{ $oidcWarning }}</div>@endif
            <label>Role rules <span class="optional">one per line, first match wins</span>
                <textarea name="role_rules" rows="5" class="mono" placeholder="email_domain:students.example.edu.au=student&#10;group:it-staff-group-object-id=it&#10;group:Filterdesk.Admin=admin">{{ old('role_rules', $roleRules) }}</textarea>
                <small><code>email_domain:</code> matches the address domain and its subdomains. <code>group:</code> matches a value in the <code>groups</code> or <code>roles</code> claim (Entra: add a groups claim or app roles to the token).</small>
            </label>
            <div class="fields two">
                <label>Network type rules <span class="optional">one per line · every match adds its types</span>
                    <textarea name="type_rules" rows="4" class="mono" placeholder="email_domain:students.example.edu.au=student&#10;group:staff=staff&#10;group:boarding=student,boarding">{{ old('type_rules', $typeRules) }}</textarea>
                    <small>The networks a person uses, by type key. New requests choose these networks by default.</small></label>
                <label>Department rules <span class="optional">one per line · first match wins</span>
                    <textarea name="department_rules" rows="4" class="mono" placeholder="group:science-faculty=Science&#10;group:english-faculty=English">{{ old('department_rules', $departmentRules) }}</textarea>
                    <small>Shown on requests and used to group them in Insights.</small></label>
            </div>
            <div class="fields two">
                <label>Default role for unmatched users<select name="default_role">@foreach(['teacher' => 'Teacher', 'student' => 'Student', 'deny' => 'Refuse sign-in'] as $value => $label)<option value="{{ $value }}" @selected(old('default_role', $oidc?->configuration['default_role'] ?? 'teacher') === $value)>{{ $label }}</option>@endforeach</select></label>
                <label class="choice compact"><input type="hidden" name="sync_roles" value="0"><input type="checkbox" name="sync_roles" value="1" @checked(old('sync_roles', $oidc?->configuration['sync_roles'] ?? false))><span><strong>Re-apply rules at every sign-in</strong><small>Never removes IT or administrator access granted by hand.</small></span></label>
            </div>
            <label class="choice"><input type="hidden" name="emergency_local_login" value="0"><input type="checkbox" name="emergency_local_login" value="1" @checked(old('emergency_local_login', $settings->get('auth.emergency_local_login', true)))><span><strong>Emergency local sign-in</strong><small>Password sign-in for local accounts. Restrict /login at the reverse proxy where possible. Recover from the host with <code>php artisan filterdesk:admin-password</code>.</small></span></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>

        @if($oidc?->enabled)
            <section class="panel form-panel" style="margin-top:20px">
                <div class="test-sign-in">
                    <div><strong>Test sign-in</strong><small>Sign in at your provider with the saved settings and see what it sends, the groups, and the role the saved rules give. Nobody is created or signed in. Sign out of the provider first to test someone else's account.</small></div>
                    <form method="post" action="{{ route('admin.settings.test-sign-in') }}">@csrf<button class="button secondary">Test sign-in</button></form>
                </div>
                @if(session(\App\Http\Controllers\OidcTestController::RESULT_KEY))
                    <x-oidc-test-result :result="session(\App\Http\Controllers\OidcTestController::RESULT_KEY)" />
                    <p class="hint">The role buttons add a line to Role rules above; press Save to keep it.</p>
                @endif
            </section>
        @endif

        @if($roleChanges->isNotEmpty())
            <section class="panel form-panel" style="margin-top:20px">
                <h2>Accounts the rules would change</h2>
                <p class="hint">From the groups each person had at their last sign-in. {{ ($oidc?->configuration['sync_roles'] ?? false) ? 'Roles are re-applied at each sign-in, so these change next time anyway.' : 'Roles are only set at the first sign-in, so these stay as they are unless you apply the rules.' }} IT and admin access is never taken away here.</p>
                <div class="table-wrap"><table>
                    <thead><tr><th>Account</th><th>Now</th><th>By the rules</th><th>Why</th></tr></thead>
                    <tbody>@foreach($roleChanges as $change)<tr @class(['muted' => $change['kept']])><td>{{ $change['user']->name }}<small>{{ $change['user']->email }}</small></td><td>{{ ucfirst($change['from']) }}</td><td>{{ ucfirst($change['to']) }}{{ $change['kept'] ? ' (kept as '.$change['from'].')' : '' }}</td><td>{{ $change['rule'] ?? 'default role' }}</td></tr>@endforeach</tbody>
                </table></div>
                @if($roleChanges->reject(fn ($change) => $change['kept'])->isNotEmpty())
                    <form method="post" action="{{ route('admin.settings.apply-roles') }}" class="form-actions">@csrf<button class="button primary" data-confirm="Change {{ $roleChanges->reject(fn ($change) => $change['kept'])->count() }} account(s) to the role the rules give?">Apply the rules to these accounts</button></form>
                @endif
            </section>
        @endif

        <section class="panel form-panel try-rules" style="margin-top:20px">
            <h2>Try the rules</h2>
            <p class="hint">See what the saved rules give someone before they sign in. Paste their groups one per line, as the provider sends them or as you see them in your directory. <code>group:</code> rules match the whole value, the CN of a directory path (<code>CN=ITStaff,OU=…</code> matches <code>group:itstaff</code>) or the name before @ of a group email (<code>itstaff@school.example</code> matches <code>group:itstaff</code>), ignoring case.</p>
            <form method="post" action="{{ route('admin.settings.try-rules') }}">@csrf
                <div class="fields two">
                    <label>Email<input name="try_email" type="email" required value="{{ old('try_email', auth()->user()->email) }}"></label>
                    <label>Groups <span class="optional">one per line</span><textarea name="try_groups" rows="4" class="mono" placeholder="CN=ITStaff,OU=User Groups,DC=school,DC=example&#10;itstaff@school.example">{{ old('try_groups') }}</textarea></label>
                </div>
                <div class="form-actions"><button class="button secondary">Try</button></div>
            </form>
            @if(session('rule_result'))
                @php($result = session('rule_result'))
                <dl class="details"><div><dt>Role</dt><dd>{{ $result['role'] }}</dd></div><div><dt>Network types</dt><dd>{{ $result['types'] }}</dd></div><div><dt>Department</dt><dd>{{ $result['department'] }}</dd></div><div class="wide"><dt>Group names the rules can match</dt><dd><code>{{ $result['names'] }}</code></dd></div></dl>
            @endif
        </section>

    @elseif($section === 'email')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'email') }}">@csrf @method('PUT')
            <h2>SMTP</h2>
            <div class="fields two">
                <label>Host <span class="optional">blank logs mail instead of sending</span><input name="mail_host" value="{{ old('mail_host', $smtp?->configuration['host'] ?? '') }}"></label>
                <div class="fields two">
                    <label>Port<input name="mail_port" type="number" value="{{ old('mail_port', $smtp?->configuration['port'] ?? 587) }}"></label>
                    <label>Encryption<select name="mail_encryption">@foreach(['tls' => 'STARTTLS when offered (587, or 25)', 'ssl' => 'TLS from the start (SMTPS, 465)', 'none' => 'None: plain SMTP, never TLS (internal relay on 25)'] as $value => $label)<option value="{{ $value }}" @selected(old('mail_encryption', $smtp?->configuration['encryption'] ?? 'tls') === $value)>{{ $label }}</option>@endforeach</select></label>
                </div>
            </div>
            <label class="choice compact"><input type="hidden" name="mail_verify_tls" value="0"><input type="checkbox" name="mail_verify_tls" value="1" @checked(old('mail_verify_tls', $smtp?->configuration['verify_tls'] ?? true))><span><strong>Verify the server's certificate</strong><small>Leave on. If the test fails with "subjectAltName did not match" (common when the host is an IP address), use the server's name from its certificate as the host instead, or turn this off for an internal server you trust.</small></span></label>
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
                @foreach(['new_request' => 'A new request is submitted', 'requester_reply' => 'A requester answers a question (sent to the assignee, or IT if unassigned)', 'assignment' => 'A request is assigned to someone', 'filter_sync' => 'A controller content filter is changed outside Filterdesk, or cannot be read'] as $event => $label)
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
                <label>Mode<select name="mode"><option value="read_only" @selected(($network?->mode ?? 'read_only') === 'read_only')>Read-only (recommended)</option><option value="active" @selected($network?->mode === 'active')>Active: push list changes to linked content filters</option></select><small>Active lets IT push allow and block list changes to the content filters linked under Policies → Networks &amp; filters. Each push only changes those lists, is read back, recorded and can be rolled back.</small></label>
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
                <label>API key <span class="optional">{{ $networkHasKey ? 'stored · blank keeps it' : 'not set' }}</span><input name="api_key" type="password" autocomplete="new-password"><small>Use a dedicated key for Filterdesk.</small></label>
                <label>Site ID <span class="optional">usually blank</span><input name="site_id" value="{{ old('site_id', $network?->configuration['site_id'] ?? '') }}" list="discovered-sites"><small>Only for a console with more than one site. Blank uses the first site. This is not the console ID. Use Discover to list sites.</small></label>
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
            <p class="section-copy">Each profile is a network path checked by its own probe container. The network type links the profile to filter policies and lists. <em>Probe address</em> is what the container reports about itself: the address and MAC on its VLAN, which you can name or reserve in UniFi. Each probe runs a setup checklist (container, VLAN, gateway, DNS, internet, filtering, DHCP) and says what to fix; switch a probe on once its checklist passes. <a href="{{ route('admin.settings.show', ['section' => 'profiles', 'refresh' => 1]) }}">Re-run checks</a></p>
            @php($sharedGateways = collect($identities)->filter(fn ($identity, $id) => ($identity['ok'] ?? false) && $profiles->firstWhere('id', $id)?->enabled)->groupBy(fn ($identity) => $identity['gateway'] ?? '?')->filter(fn ($group) => $group->count() > 1))
            @if($sharedGateways->isNotEmpty())
                <div class="alert error"><strong>These probes are not on separate networks.</strong> {{ $sharedGateways->map(fn ($group, $gateway) => $group->count().' probes share gateway '.$gateway)->join('; ') }}, so their checks all see the same filtering. Put each probe on its VLAN under <a href="{{ route('admin.policy.setup.show') }}#probes">Guided setup → Probe containers</a>.</div>
            @endif
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Network type</th><th>Probe URL</th><th>UniFi network ID</th><th>Probe address</th><th>Order</th><th>On</th><th>Delete</th></tr></thead>
                <tbody>
                    @foreach($profiles as $profile)
                        <tr>
                            <td><input name="profiles[{{ $profile->id }}][name]" value="{{ $profile->name }}" required><small><code>{{ $profile->key }}</code></small></td>
                            <td><select name="profiles[{{ $profile->id }}][audience]">@foreach(\App\Models\NetworkType::options() as $value => $label)<option value="{{ $value }}" @selected($profile->audience === $value)>{{ $label }}</option>@endforeach</select></td>
                            <td><input name="profiles[{{ $profile->id }}][probe_url]" value="{{ $profile->probe_url }}" required></td>
                            <td><input name="profiles[{{ $profile->id }}][unifi_network_id]" value="{{ $profile->unifi_network_id }}"></td>
                            <td class="probe-identity">
                                @php($diagnosis = $diagnostics[$profile->id] ?? null)
                                @php($vlanIf = collect($diagnosis['identity']['interfaces'] ?? [])->firstWhere('default_route', true))
                                @if($vlanIf)<strong>{{ implode(', ', $vlanIf['ipv4']) ?: 'no IPv4' }}</strong><small>MAC <code>{{ $vlanIf['mac'] ?? '?' }}</code> · gateway {{ $diagnosis['identity']['gateway'] ?? '?' }}</small>@endif
                                @if($diagnosis)<small class="network-status {{ ['ok' => 'ok', 'warn' => 'warn', 'todo' => 'warn', 'fail' => 'bad'][$diagnosis['state']] ?? 'muted' }}">{{ $diagnosis['summary'] }}</small>@endif
                            </td>
                            <td><input type="number" class="narrow" name="profiles[{{ $profile->id }}][sort_order]" value="{{ $profile->sort_order }}"></td>
                            <td><input type="hidden" name="profiles[{{ $profile->id }}][enabled]" value="0"><input type="checkbox" name="profiles[{{ $profile->id }}][enabled]" value="1" @checked($profile->enabled)></td>
                            <td><input type="hidden" name="profiles[{{ $profile->id }}][delete]" value="0"><input type="checkbox" name="profiles[{{ $profile->id }}][delete]" value="1" aria-label="Delete {{ $profile->name }}"></td>
                        </tr>
                        @if($diagnosis)
                            <tr class="probe-checklist-row"><td colspan="8">
                                <details class="probe-checklist" data-remember="probe-{{ $profile->id }}-{{ substr(md5($diagnosis['summary']), 0, 8) }}" @if(!in_array($diagnosis['state'], ['ok'], true)) open @endif>
                                    <summary>Setup checklist for {{ $profile->name }} <span class="network-status {{ ['ok' => 'ok', 'warn' => 'warn', 'todo' => 'warn', 'fail' => 'bad'][$diagnosis['state']] ?? 'muted' }}">{{ $diagnosis['summary'] }}</span></summary>
                                    <ol>
                                        @foreach($diagnosis['checks'] as $check)
                                            <li class="check-{{ $check['status'] }}"><span class="check-mark">{{ ['ok' => '✓', 'fail' => '✗', 'warn' => '!', 'todo' => '→', 'skip' => '–', 'reviewed' => '✓'][$check['status']] ?? '?' }}</span>
                                                <div><strong>{{ $check['label'] }}</strong> <span>{{ $check['detail'] }}</span>
                                                    @if($check['fix'] && $check['status'] !== 'reviewed')<ul class="check-fix">@foreach($check['fix'] as $step)<li>{{ $step }}</li>@endforeach</ul>@endif
                                                    <div class="check-actions">
                                                        @if(($check['action'] ?? null) === 'confirm_relay' && $check['mac'] && $check['status'] === 'warn')
                                                            <button class="button small primary" form="probe-confirm-relay-{{ $profile->id }}" data-confirm="Confirm {{ $check['ip'] }} is reserved for {{ $check['mac'] }} on your DHCP server?">It's reserved on our DHCP server</button>
                                                        @endif
                                                        @if(($check['action'] ?? null) === 'reserve' && $check['mac'] && $check['status'] === 'warn')
                                                            <button class="button small primary" form="probe-reserve-{{ $profile->id }}" data-confirm="Reserve {{ $check['ip'] }} for {{ $check['mac'] }} ({{ $profile->name }}) in UniFi?">Reserve {{ $check['ip'] }} in UniFi</button>
                                                            @if($check['outside'])<button class="button small secondary" form="probe-outside-{{ $profile->id }}" data-confirm="Move {{ $profile->name }} to {{ $check['outside'] }}, outside the DHCP pool? You then regenerate compose.vlan.yml and recreate its Docker network.">Use {{ $check['outside'] }} instead</button>@endif
                                                        @endif
                                                        @if(($check['action'] ?? null) === 'block_answer' && $check['status'] === 'warn')
                                                            <button class="button small primary" form="probe-block-answer-{{ $profile->id }}" data-confirm="Treat {{ implode(', ', $check['addresses'] ?? []) }} as your gateway's answer for blocked sites, on every probe?">Save {{ implode(', ', $check['addresses'] ?? []) }} as a block answer</button>
                                                        @endif
                                                        @if($check['reviewable'] ?? false)
                                                            <button class="button small secondary" form="probe-review-{{ $profile->id }}-{{ $check['key'] }}">{{ $check['status'] === 'reviewed' ? 'Undo reviewed' : 'Reviewed, no action needed' }}</button>
                                                        @endif
                                                    </div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>
                            </td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table></div>
            <h3>Website checks</h3>
            <label>Check method
                <select name="check_method">@foreach(\App\Services\Website\ProbeSettings::METHODS as $value => $label)<option value="{{ $value }}" @selected($checkMethod === $value)>{{ $label }}</option>@endforeach</select>
                <small class="hint">UniFi's content filter blocks at DNS, so DNS only answers most checks: blocked or not, on each network, in about a second, without the probe ever fetching the site. It cannot see a block page, HTTPS inspection, or parts of a page that fail to load, and it shows no previews.</small>
            </label>
            <div class="fields two">
                <label>Page previews (screenshot and page title)
                    <select name="check_previews">@foreach(\App\Services\Website\ProbeSettings::PREVIEWS as $value => $label)<option value="{{ $value }}" @selected($checkPreviews === $value)>{{ $label }}</option>@endforeach</select>
                    <small class="hint">Everyone sees whether a site is allowed or blocked on each network. Previews show the page itself, so a student who checks an inappropriate site would see it; keep them to IT unless you need them wider.</small>
                </label>
                <label>Block answers <span class="optional">extra DNS answers that mean blocked</span>
                    <input name="block_addresses" value="{{ implode(', ', $blockAddresses) }}" placeholder="e.g. 10.0.0.250">
                    <small class="hint">UniFi's answer (203.0.113.250, 2001:db8:1000::fa), 0.0.0.0 and the documentation ranges are always treated as blocked. Add another gateway's block-page address here; the checklist offers it when it sees one.</small>
                </label>
            </div>
            <h3>Add a profile</h3>
            <div class="fields three">
                <label>Name<input name="new[name]" placeholder="Guest Wi-Fi"></label>
                <label>Key<input name="new[key]" placeholder="guest"></label>
                <label>Network type<select name="new[audience]">@foreach(\App\Models\NetworkType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            </div>
            <label>Probe URL<input name="new[probe_url]" placeholder="http://probe-guest:3000"></label>
            <p class="hint">A new probe needs its own container on that network's VLAN. Plan it under <a href="{{ route('admin.policy.setup.show') }}#probes">Guided setup → Extra probes</a>, which generates the service and creates the profile.</p>
            <div class="form-actions"><button class="button primary">Save profiles</button></div>
        </form>
        @foreach($profiles as $profile)
            @if($diagnosis = $diagnostics[$profile->id] ?? null)
                <form id="probe-reserve-{{ $profile->id }}" method="post" action="{{ route('admin.probes.reserve', $profile) }}" hidden>@csrf</form>
                <form id="probe-outside-{{ $profile->id }}" method="post" action="{{ route('admin.probes.outside-pool', $profile) }}" hidden>@csrf</form>
                <form id="probe-confirm-relay-{{ $profile->id }}" method="post" action="{{ route('admin.probes.confirm-relay', $profile) }}" hidden>@csrf</form>
                <form id="probe-block-answer-{{ $profile->id }}" method="post" action="{{ route('admin.probes.block-answer', $profile) }}" hidden>@csrf</form>
                @foreach($diagnosis['checks'] as $check)
                    @if($check['reviewable'] ?? false)
                        <form id="probe-review-{{ $profile->id }}-{{ $check['key'] }}" method="post" action="{{ route('admin.probes.review', $profile) }}" hidden>@csrf<input type="hidden" name="check" value="{{ $check['key'] }}"><input type="hidden" name="reviewed" value="{{ $check['status'] === 'reviewed' ? 0 : 1 }}"></form>
                    @endif
                @endforeach
            @endif
        @endforeach

    @elseif($section === 'types')
        <form class="panel" method="post" action="{{ route('admin.settings.update', 'types') }}">@csrf @method('PUT')
            <p class="section-copy">A network type is a kind of network the tool manages, such as Staff, Students, CCTV or Exam rooms. Each type has its own shared allow and block lists; every filter policy of that type inherits them, along with the site-wide lists and the policy's own. Requests choose which types they are for. Names can change freely; the key stays the same.</p>
            @error('types')<div class="alert error">{{ $message }}</div>@enderror
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Description</th><th>Requestable</th><th>Auto-approve</th><th>Exam mode</th><th>Policies</th><th>Order</th><th>Delete</th></tr></thead>
                <tbody>
                    @foreach($networkTypes as $type)
                        <tr>
                            <td><input name="types[{{ $type->id }}][name]" value="{{ old("types.{$type->id}.name", $type->name) }}" required maxlength="80"><small><code>{{ $type->key }}</code></small></td>
                            <td><input name="types[{{ $type->id }}][description]" value="{{ old("types.{$type->id}.description", $type->description) }}" maxlength="255"></td>
                            <td><input type="hidden" name="types[{{ $type->id }}][requestable]" value="0"><input type="checkbox" name="types[{{ $type->id }}][requestable]" value="1" @checked($type->requestable) aria-label="{{ $type->name }} can be chosen on requests"></td>
                            <td><input type="hidden" name="types[{{ $type->id }}][auto_approve]" value="0"><input type="checkbox" name="types[{{ $type->id }}][auto_approve]" value="1" @checked($type->auto_approve) aria-label="Auto-approve requests only for {{ $type->name }}"></td>
                            <td><select name="types[{{ $type->id }}][exam_mode]" aria-label="Exam mode for {{ $type->name }}">@foreach(\App\Models\NetworkType::EXAM_MODES as $mode => $label)<option value="{{ $mode }}" @selected(($type->exam_mode ?? 'off') === $mode)>{{ $label }}</option>@endforeach</select>
                                @if($examLists->has($type->key))<small><a href="{{ route('admin.policy.lists.show', $examLists[$type->key]) }}">{{ $examLists[$type->key]->entries_count }} blocked site(s)</a>@if($type->examActive()) · <strong>active now</strong>@endif</small>@endif</td>
                            <td>{{ $type->filter_policies_count }}</td>
                            <td><input type="number" class="narrow" name="types[{{ $type->id }}][sort_order]" value="{{ $type->sort_order }}"></td>
                            <td>@if($type->inUse())<span class="hint">In use</span>@else<input type="hidden" name="types[{{ $type->id }}][delete]" value="0"><input type="checkbox" name="types[{{ $type->id }}][delete]" value="1" aria-label="Delete {{ $type->name }}">@endif @error("types.{$type->id}.delete")<small class="field-error">{{ $message }}</small>@enderror</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <p class="hint"><strong>Exam mode</strong>: a block list for the type that only applies while exam mode is on, either switched on now or during the exam periods in Settings → School calendar. Choosing a mode creates the list; add the sites to block during exams to it. Changes are pushed automatically when exam periods start and end. <strong>Requestable</strong>: shown as a choice when someone requests access or a block. <strong>Auto-approve</strong>: with <em>Auto-approve</em> on under Approval policy, an access request is approved straight away when every network it is for has this ticked.</p>
            <h3>Add a network type</h3>
            <div class="fields two">
                <label>Name<input name="new[name]" maxlength="80" placeholder="Exam rooms"></label>
                <label>Description <span class="optional">optional</span><input name="new[description]" maxlength="255" placeholder="Locked down during exams"></label>
            </div>
            <p class="hint">A new type gets its own allow and block lists straight away. Then set it on a policy (Policies) or a probe (Network profiles).</p>
            <div class="form-actions"><button class="button primary">Save network types</button></div>
        </form>

    @elseif($section === 'integrations')
        <section class="panel">
            <h2>API tokens</h2>
            <p class="section-copy">For scripts, ticketing systems and dashboards: <code>{{ url('/api/v1') }}</code> with <code>Authorization: Bearer &lt;token&gt;</code>. A token acts as the IT officer who created it, so its actions are recorded against them and the usual rules apply (two-person approval included). Endpoints: <code>GET /requests</code>, <code>GET /requests/{reference}</code>, <code>POST /requests</code>, <code>POST /requests/{reference}/decision</code>, <code>GET /versions</code>, <code>GET /explain?site=&amp;type=</code>. See docs/API.md.</p>
            @if(session('new_token'))<div class="alert success"><strong>New token</strong> (shown once): <code class="select-all">{{ session('new_token') }}</code></div>@endif
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Acts as</th><th>Can</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
                <tbody>
                    @forelse($apiTokens as $token)
                        <tr><td>{{ $token->name }}</td><td>{{ $token->user?->name }}</td><td>{{ implode(', ', $token->abilities) }}</td><td>{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td><td>{{ $token->expires_at?->local()->format('j M Y') ?? 'Never' }}</td>
                            <td><form method="post" action="{{ route('admin.integrations.tokens.destroy', $token) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Revoke {{ $token->name }}? Anything using it stops working.">Revoke</button></form></td></tr>
                    @empty
                        <tr><td colspan="6" class="empty">No tokens.</td></tr>
                    @endforelse
                </tbody>
            </table></div>
            <form method="post" action="{{ route('admin.integrations.tokens.store') }}" class="fields three">@csrf
                <label>Name<input name="name" required maxlength="80" placeholder="Service desk"></label>
                <fieldset><legend>Can</legend>@foreach(\App\Models\ApiToken::ABILITIES as $ability => $label)<label class="choice compact"><input type="checkbox" name="abilities[]" value="{{ $ability }}" @checked($ability === 'read')><span>{{ $label }}</span></label>@endforeach</fieldset>
                <label>Expires after (days) <span class="optional">blank = never</span><input type="number" name="expires_days" min="1" max="730" value="365"></label>
                <div class="form-actions"><button class="button primary">Create token</button></div>
            </form>
        </section>
        <section class="panel" style="margin-top:20px">
            <h2>Webhooks</h2>
            <p class="section-copy">Send request and policy events to another system: a JSON receiver (a ticketing system, an automation) or a Microsoft Teams channel (an incoming webhook or a Workflows URL). Teams cards for new requests have Approve and Decline buttons that open a confirmation page here. JSON bodies are signed with HMAC-SHA256 of the raw body in <code>X-Filterdesk-Signature: sha256=…</code> when a secret is set.</p>
            @foreach($webhooks as $webhook)
                <form method="post" action="{{ route('admin.integrations.webhooks.update', $webhook) }}" class="probe-plan">@csrf @method('PUT')
                    <div class="fields three">
                        <label>Name<input name="name" value="{{ $webhook->name }}" required maxlength="80"></label>
                        <label>URL<input name="url" value="{{ $webhook->url }}" required></label>
                        <label>Format<select name="format">@foreach(\App\Models\WebhookEndpoint::FORMATS as $format => $label)<option value="{{ $format }}" @selected($webhook->format === $format)>{{ $label }}</option>@endforeach</select></label>
                    </div>
                    <div class="category-grid">@foreach(\App\Models\WebhookEndpoint::EVENTS as $event => $label)<label class="choice compact"><input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, $webhook->events ?? [], true))><span>{{ $label }}</span></label>@endforeach</div>
                    <div class="fields two">
                        <label>Secret <span class="optional">{{ filled($webhook->secret) ? 'stored · blank keeps it' : 'optional' }}</span><input name="secret" type="password" autocomplete="new-password"></label>
                        <label class="choice compact"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($webhook->enabled)><span>Enabled<small>Last delivery: {{ $webhook->last_status ?? 'none yet' }}{{ $webhook->last_delivered_at ? ' · '.$webhook->last_delivered_at->diffForHumans() : '' }}</small></span></label>
                    </div>
                    <div class="button-row"><button class="button secondary">Save</button></div>
                </form>
                <div class="button-row webhook-actions">
                    <form method="post" action="{{ route('admin.integrations.webhooks.test', $webhook) }}">@csrf<button class="button small secondary">Send a test</button></form>
                    <form method="post" action="{{ route('admin.integrations.webhooks.destroy', $webhook) }}">@csrf @method('DELETE')<button class="link-button danger-text" data-confirm="Remove {{ $webhook->name }}?">Remove</button></form>
                </div>
            @endforeach
            <h3>Add a webhook</h3>
            <form method="post" action="{{ route('admin.integrations.webhooks.store') }}">@csrf
                <div class="fields three">
                    <label>Name<input name="name" required maxlength="80" placeholder="IT Teams channel"></label>
                    <label>URL<input name="url" required placeholder="https://…"></label>
                    <label>Format<select name="format">@foreach(\App\Models\WebhookEndpoint::FORMATS as $format => $label)<option value="{{ $format }}">{{ $label }}</option>@endforeach</select></label>
                </div>
                <div class="category-grid">@foreach(\App\Models\WebhookEndpoint::EVENTS as $event => $label)<label class="choice compact"><input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, ['request.submitted', 'request.awaiting_second'], true))><span>{{ $label }}</span></label>@endforeach</div>
                <label>Secret <span class="optional">optional · JSON only</span><input name="secret" type="password" autocomplete="new-password"></label>
                <div class="form-actions"><button class="button primary">Add webhook</button></div>
            </form>
        </section>

    @elseif($section === 'calendar')
        <form class="panel" method="post" action="{{ route('admin.settings.update', 'calendar') }}">@csrf @method('PUT')
            <p class="section-copy">Terms, holidays and exam periods, and the bell times of the school day. Recurring requests can follow the calendar ("Term 2, Mondays, periods 3–4"), skipping holidays, and network types can switch to exam mode during exam periods. Times are school time.</p>
            <h3>Terms, holidays and exam periods</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Name</th><th>Kind</th><th>First day</th><th>Last day</th><th>Delete</th></tr></thead>
                <tbody>
                    @foreach($calendarPeriods as $period)
                        <tr>
                            <td><input name="periods[{{ $period->id }}][name]" value="{{ $period->name }}" required maxlength="80" aria-label="Name"></td>
                            <td><select name="periods[{{ $period->id }}][kind]" aria-label="Kind">@foreach(\App\Models\CalendarPeriod::KINDS as $kind => $label)<option value="{{ $kind }}" @selected($period->kind === $kind)>{{ $label }}</option>@endforeach</select></td>
                            <td><input type="date" name="periods[{{ $period->id }}][start_date]" value="{{ $period->start_date->format('Y-m-d') }}" required aria-label="First day"></td>
                            <td><input type="date" name="periods[{{ $period->id }}][end_date]" value="{{ $period->end_date->format('Y-m-d') }}" required aria-label="Last day"></td>
                            <td><input type="hidden" name="periods[{{ $period->id }}][delete]" value="0"><input type="checkbox" name="periods[{{ $period->id }}][delete]" value="1" aria-label="Delete {{ $period->name }}"></td>
                        </tr>
                    @endforeach
                    <tr>
                        <td><input name="new_period[name]" maxlength="80" placeholder="Term 1 2027" aria-label="New period name"></td>
                        <td><select name="new_period[kind]" aria-label="New period kind">@foreach(\App\Models\CalendarPeriod::KINDS as $kind => $label)<option value="{{ $kind }}">{{ $label }}</option>@endforeach</select></td>
                        <td><input type="date" name="new_period[start_date]" aria-label="New period first day"></td>
                        <td><input type="date" name="new_period[end_date]" aria-label="New period last day"></td>
                        <td></td>
                    </tr>
                </tbody>
            </table></div>
            <p class="hint">Holidays inside a term (a public holiday, a pupil-free day) are skipped by calendar schedules. Add one row at a time and save.</p>
            <h3>Bell times</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Period</th><th>Starts</th><th>Ends</th><th>Delete</th></tr></thead>
                <tbody>
                    @foreach($bellPeriods as $bell)
                        <tr>
                            <td><input name="bells[{{ $bell->id }}][name]" value="{{ $bell->name }}" required maxlength="60" aria-label="Period name"></td>
                            <td><input type="time" name="bells[{{ $bell->id }}][starts_at]" value="{{ $bell->starts_at }}" required aria-label="Starts"></td>
                            <td><input type="time" name="bells[{{ $bell->id }}][ends_at]" value="{{ $bell->ends_at }}" required aria-label="Ends"></td>
                            <td><input type="hidden" name="bells[{{ $bell->id }}][delete]" value="0"><input type="checkbox" name="bells[{{ $bell->id }}][delete]" value="1" aria-label="Delete {{ $bell->name }}"></td>
                        </tr>
                    @endforeach
                    <tr>
                        <td><input name="new_bell[name]" maxlength="60" placeholder="Period 1" aria-label="New period name"></td>
                        <td><input type="time" name="new_bell[starts_at]" aria-label="New period starts"></td>
                        <td><input type="time" name="new_bell[ends_at]" aria-label="New period ends"></td>
                        <td></td>
                    </tr>
                </tbody>
            </table></div>
            <p class="hint">Periods next to each other (within 10 minutes) are treated as one block, so choosing periods 3 and 4 does not include the gap between them, but choosing periods 2 and 5 does not include lunch either.</p>
            <div class="form-actions"><button class="button primary">Save school calendar</button></div>
        </form>

    @elseif($section === 'templates')
        @php($templates = app(\App\Services\Notifications\EmailTemplates::class))
        <form class="panel" method="post" action="{{ route('admin.settings.update', 'templates') }}">@csrf @method('PUT')
            <p class="section-copy">Change the wording of every email the app sends. Use the placeholders listed with each template; they are filled in when the email is sent. Separate paragraphs with a blank line. Leave a field blank to use the default. Text is sent as plain text, so HTML is shown as typed.</p>
            @foreach(collect(\App\Services\Notifications\EmailTemplates::DEFINITIONS)->groupBy('group', preserveKeys: true) as $group => $definitions)
                <h3>{{ $group }}</h3>
                @foreach($definitions as $key => $definition)
                    @php($current = $templates->template($key))
                    <details class="panel template-editor">
                        <summary><strong>{{ $definition['label'] }}</strong> @if($templates->customised($key))<span class="status status-ok">Customised</span>@else<span class="hint">Default</span>@endif</summary>
                        <div class="fields two">
                            <label>Subject<input name="templates[{{ $key }}][subject]" maxlength="200" value="{{ $current['subject'] }}"></label>
                            <label>Greeting <span class="optional">blank for none</span><input name="templates[{{ $key }}][greeting]" maxlength="200" value="{{ $current['greeting'] }}"></label>
                        </div>
                        <label>Body<textarea name="templates[{{ $key }}][body]" rows="6" maxlength="5000" class="mono">{{ $current['body'] }}</textarea></label>
                        <label>Button<input name="templates[{{ $key }}][action]" maxlength="60" value="{{ $current['action'] }}"></label>
                        <p class="hint">Placeholders: @foreach(['name' => "Recipient's name", 'app_name' => 'App name', 'school' => 'School name'] + $definition['placeholders'] as $placeholder => $meaning)<code>{{ '{'.$placeholder.'}' }}</code> {{ strtolower($meaning) }}@if(!$loop->last) · @endif @endforeach</p>
                        @php($preview = $templates->mail($key, $templates->sample($key), url('/')))
                        <div class="template-preview"><small class="hint">Preview with sample values</small><strong>{{ $preview->subject }}</strong>@if($preview->greeting)<p>{{ $preview->greeting }}</p>@endif @foreach($preview->introLines as $line)<p>{{ $line }}</p>@endforeach @if($preview->actionText)<span class="button small secondary">{{ $preview->actionText }}</span>@endif</div>
                        @if($templates->customised($key))<label class="choice compact"><input type="checkbox" name="templates[{{ $key }}][reset]" value="1"><span>Reset to the default wording</span></label>@endif
                    </details>
                @endforeach
            @endforeach
            <div class="form-actions"><button class="button primary">Save email templates</button></div>
        </form>

    @elseif($section === 'categories')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'categories') }}">@csrf @method('PUT')
            <p class="section-copy">Shows what kind of site a domain is (for example <em>Gambling</em> or <em>Social Networks</em>) on website checks and requests, and which UniFi content category it probably falls in, with whether a linked filter already blocks that category. It helps decide between a category block and a list of hostnames. It is a guide: UniFi's own category database can differ.</p>
            <div class="notice"><strong>Privacy:</strong> when this is on, every domain someone checks or requests is sent to Cloudflare to be categorised (no user details are sent). Answers are cached for a week. Leave it off if your privacy policy does not allow that.</div>
            <label class="choice"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $categoryLookup?->enabled))><span><strong>Look up website categories</strong><small>Off by default.</small></span></label>
            <label>Source
                <select name="source">
                    @php($source = $categoryLookup?->configuration['source'] ?? 'auto')
                    <option value="auto" @selected($source === 'auto')>Cloudflare: Intel when an account ID is set, Radar otherwise (recommended)</option>
                    <option value="intel" @selected($source === 'intel')>Cloudflare Intel only: any site, with security risks (account ID and Intel Read)</option>
                    <option value="radar" @selected($source === 'radar')>Cloudflare Radar only: popular sites only (token only)</option>
                </select>
                <small>Radar only categorises sites in its popularity rankings, so most smaller sites come back empty. Intel categorises almost any domain.</small>
            </label>
            <label>Cloudflare API token <span class="optional">{{ filled($categoryLookup?->credentials['token'] ?? null) ? 'saved; leave blank to keep it' : 'required' }}</span><input type="password" name="token" autocomplete="off" maxlength="200"><small>Create one at dash.cloudflare.com → Manage account → Account API Tokens (or My Profile → API Tokens) with <strong>Account → Intel → Read</strong> and <strong>Account → Radar → Read</strong>. Read access only; nothing else.</small></label>
            <label>Account ID <span class="optional">needed for Intel; in the dashboard URL after dash.cloudflare.com/</span><input name="account_id" value="{{ old('account_id', $categoryLookup?->configuration['account_id'] ?? '') }}" maxlength="32" class="mono"></label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.test-categories') }}">@csrf
            <label>Try a domain<input name="domain" placeholder="example.com" required maxlength="253"></label>
            <div class="form-actions"><button class="button secondary">Look it up</button></div>
        </form>

    @elseif($section === 'certificate')
        @php($certificate = app(\App\Services\Network\InspectionCertificate::class)->current())
        <section class="panel form-panel">
            <h2>HTTPS inspection certificate</h2>
            <p class="section-copy">When the gateway inspects HTTPS (UniFi calls it SSL inspection or Deep Inspection), it signs websites with its own certificate authority. Devices that don't trust it show certificate warnings. Upload that CA's <strong>public</strong> certificate here and Filterdesk offers it at a public page with install steps for each kind of device. Website checks that hit an inspected network link to it. Never upload the private key.</p>
            @if($certificate)
                <dl class="details">
                    <div><dt>Certificate</dt><dd>{{ $certificate['subject'] }}</dd></div>
                    <div><dt>Valid</dt><dd>{{ $certificate['valid_from'] }} to {{ $certificate['valid_to'] }}</dd></div>
                    <div><dt>SHA-256 fingerprint</dt><dd class="mono" style="word-break:break-all">{{ $certificate['fingerprint'] }}</dd></div>
                    <div><dt>Public page</dt><dd><a href="{{ route('certificate.show') }}" target="_blank" rel="noopener">{{ route('certificate.show') }}</a></dd></div>
                </dl>
            @endif
            <form method="post" action="{{ route('admin.settings.update', 'certificate') }}" enctype="multipart/form-data">@csrf @method('PUT')
                <label>{{ $certificate ? 'Replace with' : 'CA certificate' }} <span class="optional">.crt, .cer or .pem; PEM or DER</span><input type="file" name="certificate_file" accept=".crt,.cer,.pem,.der"></label>
                <label>Or paste it <span class="optional">-----BEGIN CERTIFICATE----- …</span><textarea name="certificate_text" rows="4" class="mono"></textarea></label>
                <p class="hint">In UniFi Network: Settings → Security → (Deep Inspection or SSL Inspection) → download the certificate. In other gateways, export the inspection CA without its key.</p>
                <div class="form-actions"><button class="button primary">Upload</button></div>
            </form>
            @if($certificate)
                <form method="post" action="{{ route('admin.settings.update', 'certificate') }}">@csrf @method('PUT')<input type="hidden" name="remove" value="1"><button class="link-button danger-text" data-confirm="Remove the certificate? Its public page stops working.">Remove the certificate</button></form>
            @endif
        </section>
    @elseif($section === 'approval')
        <form class="panel form-panel" method="post" action="{{ route('admin.settings.update', 'approval') }}">@csrf @method('PUT')
            <label class="choice"><input type="hidden" name="auto_approve_staff" value="0"><input type="checkbox" name="auto_approve_staff" value="1" @checked($settings->get('approval.auto_approve_staff', false))><span><strong>Auto-approve requests for trusted networks</strong><small>Access requests whose networks are all marked <em>Auto-approve</em> under Network types (by default Staff) are approved straight away. IT still applies them in the controller unless the provider can do it automatically.</small></span></label>
            <label class="choice"><input type="hidden" name="students_can_request" value="0"><input type="checkbox" name="students_can_request" value="1" @checked($settings->get('approval.students_can_request', false))><span><strong>Students may submit requests</strong><small>Students can always run website checks.</small></span></label>
            <h3>Two-person approval</h3>
            <label class="choice"><input type="hidden" name="two_person_site_wide" value="0"><input type="checkbox" name="two_person_site_wide" value="1" @checked($settings->get('approval.two_person_site_wide', true))><span><strong>Site-wide changes need two IT officers</strong><small>Approving site-wide access or a block, and adding or removing entries in a site-wide list by hand, waits until a second IT officer confirms it (Policy → Changes). The officer who made the change cannot confirm it. Skipped while only one IT officer is enabled.</small></span></label>
            <label class="choice"><input type="hidden" name="two_person_override" value="0"><input type="checkbox" name="two_person_override" value="1" @checked($settings->get('approval.two_person_override', true))><span><strong>Allow approving alone when no other IT officer is available</strong><small>The officer ticks a box and gives a reason. The other IT officers (and the IT address) are emailed to review it, and it is recorded in the audit log and the Insights report.</small></span></label>
            <label>Sensitive categories <span class="optional">comma or line separated · requests in these need two IT officers</span><textarea name="sensitive_categories" rows="4">{{ implode("\n", $settings->get('approval.sensitive_categories', \App\Services\Approval\TwoPersonRule::DEFAULT_SENSITIVE)) }}</textarea><small>Matched against the website category service (Cloudflare) and UniFi category names, for example Gambling or Adult Themes. Needs Settings → Website categories. Leave empty to turn this off.</small></label>
            @php($guide = app(\App\Services\Requests\AccessGuide::class)->settings())
            <h3>After access is approved</h3>
            <label class="choice"><input type="hidden" name="guide[enabled]" value="0"><input type="checkbox" name="guide[enabled]" value="1" @checked($guide['enabled'])><span><strong>Show what to do next</strong><small>Staff whose request is approved straight away go to this page, and it appears on approved requests and in the approval email. Filtering works on website names, so a computer can keep the old answer for a few minutes.</small></span></label>
            <label class="choice compact"><input type="hidden" name="guide[students]" value="0"><input type="checkbox" name="guide[students]" value="1" @checked($guide['students'])><span>Show it to students too</span></label>
            <div class="fields two">
                <label>Heading<input name="guide[title]" maxlength="120" value="{{ $guide['title'] }}"></label>
                <label>Introduction<textarea name="guide[intro]" rows="3" maxlength="600">{{ $guide['intro'] }}</textarea></label>
            </div>
            <label>Steps <span class="optional">one per line · blank restores the defaults</span><textarea name="guide[steps]" rows="5" maxlength="3000">{{ $guide['steps'] }}</textarea></label>
            <h3>Exception reviews</h3>
            <div class="fields two">
                <label>Review exceptions every (months)<input name="review_months" type="number" min="1" max="36" value="{{ $settings->get('governance.review_months', 12) }}"><small>Allow and block entries made by IT or from requests get a review date this far ahead. Owners and IT are emailed when one is due.</small></label>
                <label>Remove unreviewed exceptions after (days)<input name="grace_days" type="number" min="1" max="90" value="{{ $settings->get('governance.grace_days', 14) }}"><small>An exception nobody keeps is removed this long after its review date.</small></label>
            </div>
            <label>Default temporary access (days)<input name="default_temporary_days" type="number" min="1" max="365" value="{{ $settings->get('approval.default_temporary_days', 30) }}"></label>
            <div class="fields two">
                <label>Open timed access early (minutes)<input name="window_lead_minutes" type="number" min="0" max="60" value="{{ $settings->get('approval.window_lead_minutes', 5) }}"><small>Allow or block entries go live this long before their start, so the controller has them in place on time.</small></label>
                <label>Close timed access late (minutes)<input name="window_lag_minutes" type="number" min="0" max="60" value="{{ $settings->get('approval.window_lag_minutes', 5) }}"><small>Entries are removed this long after their end. With automatic pushing, changes reach the controller within about a minute.</small></label>
                <label>Recurring schedules: open early and close late (minutes)<input name="schedule_margin_minutes" type="number" min="0" max="180" value="{{ $settings->get('approval.schedule_margin_minutes', 60) }}"><small>For schedules with set hours, access starts this long before the first hour and ends this long after the last. All-day schedules switch on at 1 am.</small></label>
            </div>
            @php($form = app(\App\Services\Requests\RequestFormSettings::class)->all())
            <h3>Request form</h3>
            <div class="fields two">
                <label>Request categories for staff <span class="optional">comma or line separated</span><textarea name="categories" rows="5">{{ implode("\n", $settings->get('approval.categories', [])) }}</textarea></label>
                <label>Request categories for students <span class="optional">comma or line separated</span><textarea name="form[student_categories]" rows="5" required>{{ implode("\n", $form['student_categories']) }}</textarea></label>
            </div>
            <p class="hint">The "Category" dropdown on the request form. Students see their own list, worded for them.</p>
            <div class="fields two">
                <label>Reason field title (access requests)<input name="form[access_label]" maxlength="120" required value="{{ $form['access_label'] }}"></label>
                <label>Reason field title (block requests)<input name="form[block_label]" maxlength="120" required value="{{ $form['block_label'] }}"></label>
                <label>Hint inside the field (access)<input name="form[access_hint]" maxlength="300" value="{{ $form['access_hint'] }}"></label>
                <label>Hint inside the field (block)<input name="form[block_hint]" maxlength="300" value="{{ $form['block_hint'] }}"></label>
            </div>
            <p class="hint">Rename the field to ask for something else, for example "Class and unit" or "Ticket number". The title is also used in emails to IT.</p>
            <div class="fields two">
                <label class="choice compact"><input type="hidden" name="form[staff_required]" value="0"><input type="checkbox" name="form[staff_required]" value="1" @checked($form['staff_required'])><span><strong>Required for staff</strong><small>Teachers, IT and admins.</small></span></label>
                <label class="choice compact"><input type="hidden" name="form[student_required]" value="0"><input type="checkbox" name="form[student_required]" value="1" @checked($form['student_required'])><span><strong>Required for students</strong><small>When students may submit requests.</small></span></label>
            </div>
            <label>Minimum length when filled in <span class="optional">characters</span><input type="number" name="form[min_length]" min="0" max="500" value="{{ $form['min_length'] }}"></label>
            <label>Block requests: when <span class="optional">for network types with a school-hours filter</span>
                <select name="form[block_hours]">@foreach(\App\Services\Requests\RequestFormSettings::BLOCK_HOURS as $value => $label)<option value="{{ $value }}" @selected($form['block_hours'] === $value)>{{ $label }}</option>@endforeach</select>
                <small>A block in <em>school hours only</em> goes into the school-hours filter (for example Student - School Time), so the site is open after hours and at weekends. When the requester chooses, it is a suggestion: IT can still change it when approving. <em>Always all the time</em> hides the choice from IT too.</small>
            </label>
            <div class="form-actions"><button class="button primary">Save</button></div>
        </form>
    @endif
</x-layouts.app>
