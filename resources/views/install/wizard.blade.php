<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Install Filterdesk</title><link rel="stylesheet" href="{{ asset('app.css') }}"><link rel="stylesheet" href="{{ asset('ui.css') }}"><script src="{{ asset('app.js') }}" defer></script></head>
<body class="install-page">
@php($titles = [1 => 'Welcome and system checks', 2 => 'School configuration', 3 => 'Administrator and authentication', 4 => 'UniFi integration', 5 => 'Network access profiles', 6 => 'Email and notifications', 7 => 'Approval policies', 8 => 'Review and complete'])
<main class="install-shell">
    <aside class="install-aside">
        <div class="brand"><span class="brand-mark">IT</span><span>Filterdesk</span></div>
        <p>A secure self-service hub for your school community.</p>
        <ol>@foreach($titles as $number => $label)<li @class(['current' => $number === $step, 'done' => $number < $step])><span>{{ $number }}</span>{{ $label }}</li>@endforeach</ol>
    </aside>
    <section class="install-card">
        <p class="eyebrow">Step {{ $step }} of 8</p>
        <h1>{{ $titles[$step] }}</h1>
        @if(session('info'))<div class="alert success" role="status">{{ session('info') }}</div>@endif
        @if(session('error'))<div class="alert error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="alert error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="post" action="{{ route('install.store', $step) }}">@csrf
            @if($step === 1)
                <p>Filterdesk checks website access from staff and student networks, manages approval, and records every decision. Docker migrations must run before this wizard.</p>
                <div class="check-list">@foreach($checks as $name => $check)<div><span class="check-icon {{ $check['ok'] ? 'ok' : 'bad' }}">{{ $check['ok'] ? '✓' : '!' }}</span><span><strong>{{ str($name)->replace('_', ' ')->title() }}</strong><small>{{ $check['message'] }}</small></span></div>@endforeach</div>
            @elseif($step === 2)
                <label>School or organisation name<input name="school_name" required value="{{ old('school_name', $data['school_name'] ?? '') }}"></label>
                <div class="fields two"><label>Timezone<input name="timezone" required value="{{ old('timezone', $data['timezone'] ?? 'Australia/Brisbane') }}"></label><label>Public application URL<input name="app_url" type="url" required placeholder="https://filterdesk.example.edu.au" value="{{ old('app_url', $data['app_url'] ?? '') }}"></label></div>
                <label>Schoolbox origin <span class="optional">optional</span><input name="schoolbox_origin" type="url" placeholder="https://schoolbox.example.edu.au" value="{{ old('schoolbox_origin', $data['schoolbox_origin'] ?? '') }}"><small>Allows this application to be embedded only by this exact Schoolbox origin.</small></label>
            @elseif($step === 3)
                <div class="fields two"><label>Initial administrator name<input name="admin_name" required value="{{ old('admin_name', $data['admin_name'] ?? '') }}"></label><label>Administrator email<input name="admin_email" type="email" required value="{{ old('admin_email', $data['admin_email'] ?? '') }}"></label></div>
                <div class="fields two"><label>Emergency password<input name="admin_password" type="password" minlength="14" required></label><label>Confirm password<input name="admin_password_confirmation" type="password" minlength="14" required></label></div>
                <label class="choice"><input type="hidden" name="emergency_local_login" value="0"><input type="checkbox" name="emergency_local_login" value="1" @checked(old('emergency_local_login', $data['emergency_local_login'] ?? true))><span><strong>Keep emergency local sign-in</strong><small>Recommended for recovery. Restrict it at your reverse proxy if possible.</small></span></label>
                <label class="choice"><input type="hidden" name="oidc_enabled" value="0"><input type="checkbox" name="oidc_enabled" value="1" @checked(old('oidc_enabled', $data['oidc_enabled'] ?? true))><span><strong>Configure OpenID Connect</strong><small>Works with Microsoft Entra ID and standards-compliant providers.</small></span></label>
                <label>Issuer URL<input name="oidc_issuer" type="url" placeholder="https://login.microsoftonline.com/tenant-id/v2.0" value="{{ old('oidc_issuer', $data['oidc_issuer'] ?? '') }}"></label>
                <div class="fields two"><label>Client ID<input name="oidc_client_id" value="{{ old('oidc_client_id', $data['oidc_client_id'] ?? '') }}"></label><label>Client secret<input name="oidc_client_secret" type="password"></label></div>
                <label>Allowed email domains <span class="optional">comma separated</span><input name="oidc_allowed_domains" placeholder="example.edu.au, students.example.edu.au" value="{{ old('oidc_allowed_domains', $data['oidc_allowed_domains'] ?? '') }}"></label>
                <label>Role rules <span class="optional">one per line, first match wins</span><textarea name="role_rules" rows="3" placeholder="email_domain:students.example.edu.au=student&#10;group:00000000-0000-0000-0000-000000000000=it">{{ old('role_rules', $data['role_rules'] ?? '') }}</textarea><small>Map an email domain or an Entra group/app-role claim to a role. Without rules every new user gets the default role below.</small></label>
                <div class="fields two">
                    <label>Default role for unmatched users<select name="default_role">@foreach(['teacher' => 'Teacher', 'student' => 'Student', 'deny' => 'Refuse sign-in'] as $value => $label)<option value="{{ $value }}" @selected(old('default_role', $data['default_role'] ?? 'teacher') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="choice compact"><input type="hidden" name="sync_roles" value="0"><input type="checkbox" name="sync_roles" value="1" @checked(old('sync_roles', $data['sync_roles'] ?? true))><span><strong>Re-apply rules at every sign-in</strong><small>Recommended while you tune the rules: a fix to a rule also fixes accounts made earlier. Never removes IT or admin access given by hand.</small></span></label>
                </div>
                @php($hint = filled($data['oidc_issuer'] ?? null) ? app(\App\Services\Oidc\OidcProviderHints::class)->for($data['oidc_issuer']) : null)
                <details class="oidc-advanced" @if(filled($data['oidc_scopes'] ?? null) || filled($data['oidc_group_claims'] ?? null) || $errors->has('oidc_scopes')) open @endif><summary>Provider-specific options <span class="optional">extra scopes, group claims</span></summary>
                    <div class="fields two">
                        <label>Extra scopes <span class="optional">space separated</span><input name="oidc_scopes" value="{{ old('oidc_scopes', $data['oidc_scopes'] ?? '') }}" placeholder="{{ \App\Services\Oidc\OidcProviderHints::CLOUDWORK_SCOPE }}"><small>openid, profile and email are always requested. CloudWork ID: <code>{{ \App\Services\Oidc\OidcProviderHints::CLOUDWORK_SCOPE }}</code> (added for you when your rules use <code>group:</code>).</small></label>
                        <label>Claims holding groups or roles <span class="optional">blank = automatic</span><input name="oidc_group_claims" value="{{ old('oidc_group_claims', $data['oidc_group_claims'] ?? '') }}" placeholder="automatic"><small>Blank uses every claim named …groups, …role or …roles.</small></label>
                    </div>
                    @if($hint && $hint['note'])<p class="hint">{{ $hint['provider'] ? $hint['provider'].': ' : '' }}{{ $hint['note'] }}</p>@endif
                </details>
                <p class="notice">Register this redirect URI with your identity provider: <code>{{ rtrim($data['app_url'] ?? config('app.url'), '/') }}/auth/oidc/callback</code></p>
                @if($data['sso_warning'] ?? null)<div class="alert warning">{{ $data['sso_warning'] }}</div>@endif
                <div class="test-sign-in">
                    <div><strong>Test sign-in</strong> <span class="optional">optional</span><small>Sign in at your provider with the settings above and see what it sends and the role you would get. Nobody is created or signed in, and nothing is saved except what you typed on this page.</small></div>
                    <button type="submit" class="button secondary" formaction="{{ route('install.oidc-test') }}" formnovalidate>Test sign-in</button>
                </div>
                @if(session(\App\Http\Controllers\OidcTestController::RESULT_KEY))<x-oidc-test-result :result="session(\App\Http\Controllers\OidcTestController::RESULT_KEY)" />@endif
            @elseif($step === 4)
                <label class="choice"><input type="hidden" name="unifi_enabled" value="0"><input type="checkbox" name="unifi_enabled" value="1" @checked(old('unifi_enabled', $data['unifi_enabled'] ?? true))><span><strong>Configure UniFi Network</strong><small>You can install in simulation mode and configure it later.</small></span></label>
                <label>Connection<select name="unifi_connection"><option value="local" @selected(($data['unifi_connection'] ?? 'local') === 'local')>Local console key (Network → Settings → Control Plane → Integrations)</option><option value="cloud" @selected(($data['unifi_connection'] ?? '') === 'cloud')>Site Manager key from unifi.ui.com (cloud connector)</option></select><small>unifi.ui.com keys only work with the cloud connector; Test connection then lists your consoles to choose from.</small></label>
                @php($tested = $data['unifi_test'] ?? null)
                <label>Controller URL <span class="optional">local connection</span><input name="unifi_controller_url" type="url" placeholder="https://unifi-console.example.edu.au" value="{{ old('unifi_controller_url', $data['unifi_controller_url'] ?? '') }}"></label>
                <label>Official API key <span class="optional">{{ filled($data['unifi_api_key'] ?? null) ? 'entered · blank keeps it' : '' }}</span><input name="unifi_api_key" type="password" autocomplete="new-password"></label>
                @if($tested && $tested['consoles'])
                    <fieldset class="console-choice"><legend>Console to manage <span class="optional">{{ count($tested['consoles']) }} visible to this key</span></legend>
                        @foreach($tested['consoles'] as $console)
                            <label class="choice"><input type="radio" name="unifi_console_id" value="{{ $console['id'] }}" @checked(($data['unifi_console_id'] ?? '') === $console['id'] || count($tested['consoles']) === 1 && blank($data['unifi_console_id'] ?? null))><span><strong>{{ $console['name'] }}</strong><small><code>{{ $console['id'] }}</code></small></span></label>
                        @endforeach
                        <small>Pick one, then <strong>Test connection</strong> again to see its sites, networks and filters.</small>
                    </fieldset>
                @else
                    <label>Console ID <span class="optional">cloud connection · Test connection lists your consoles</span><input name="unifi_console_id" value="{{ old('unifi_console_id', $data['unifi_console_id'] ?? '') }}"></label>
                @endif
                @if($tested && count($tested['sites']) > 1)
                    <fieldset class="console-choice"><legend>Site</legend>
                        @foreach($tested['sites'] as $site)<label class="choice compact"><input type="radio" name="unifi_site_id" value="{{ $site['id'] }}" @checked(($data['unifi_site_id'] ?? $tested['sites'][0]['id']) === $site['id'])><span>{{ $site['name'] }}</span></label>@endforeach
                    </fieldset>
                @else
                    <label>Site ID <span class="optional">usually blank; not the console ID</span><input name="unifi_site_id" value="{{ old('unifi_site_id', $data['unifi_site_id'] ?? '') }}"></label>
                @endif
                <div class="test-sign-in">
                    <div><strong>Test connection</strong><small>Checks the key{{ ($data['unifi_connection'] ?? 'local') === 'cloud' ? ', lists the consoles it can see' : '' }} and shows the networks and content filters found, so you can tell it's the right console. Nothing is changed in UniFi.</small></div>
                    <button type="submit" class="button secondary" formaction="{{ route('install.unifi-test') }}" formnovalidate>Test connection</button>
                </div>
                @if($tested)
                    <div @class(['alert', 'success' => $tested['ok'], 'warning' => ! $tested['ok']]) role="status">{{ $tested['message'] }}
                        @if($tested['ok'])<br>{{ count($tested['networks']) }} {{ count($tested['networks']) === 1 ? 'network' : 'networks' }}{{ $tested['filters'] !== null ? ' and '.$tested['filters'].' content '.($tested['filters'] === 1 ? 'filter' : 'filters') : '' }} found.@endif
                    </div>
                    @if($tested['networks'])<x-chips :items="$tested['networks']" :max="12" />@endif
                @endif
                <div class="fields two"><label>Integration mode<select name="unifi_mode"><option value="read_only" @selected(($data['unifi_mode'] ?? '') === 'read_only')>Read-only (recommended)</option><option value="active" @selected(($data['unifi_mode'] ?? '') === 'active')>Active changes</option></select></label><label class="choice compact"><input type="hidden" name="unifi_verify_tls" value="0"><input type="checkbox" name="unifi_verify_tls" value="1" @checked(old('unifi_verify_tls', $data['unifi_verify_tls'] ?? true))><span><strong>Verify TLS certificate</strong><small>Disable only for a private controller using a documented internal CA exception.</small></span></label></div>
                <label>How do domain entries match? <select name="entry_matching"><option value="exact" @selected(($data['entry_matching'] ?? 'exact') === 'exact')>List the domain and each needed subdomain explicitly (recommended)</option><option value="includes_subdomains" @selected(($data['entry_matching'] ?? '') === 'includes_subdomains')>An entry also covers its subdomains</option></select><small>UniFi filtering is DNS based. The explicit option works on every controller version.</small></label>
                <div class="notice">Domain allowlisting is capability-checked. Current official UniFi APIs do not expose this consistently, so unsupported controllers remain a manual IT action.</div>
            @elseif($step === 5)
                <p>Each probe is a separate, isolated container. The defaults work for initial testing; a Compose override attaches each probe to the appropriate VLAN.</p>
                <div class="fields two"><label>Student probe URL<input name="student_probe_url" required value="{{ old('student_probe_url', $data['student_probe_url'] ?? 'http://probe-student:3000') }}"></label><label>Staff probe URL<input name="staff_probe_url" required value="{{ old('staff_probe_url', $data['staff_probe_url'] ?? 'http://probe-staff:3000') }}"></label></div>
                <div class="fields two"><label>Student VLAN <span class="optional">optional, for example 20</span><input name="student_network_id" inputmode="numeric" value="{{ old('student_network_id', $data['student_network_id'] ?? '') }}"></label><label>Staff VLAN <span class="optional">optional, for example 10</span><input name="staff_network_id" inputmode="numeric" value="{{ old('staff_network_id', $data['staff_network_id'] ?? '') }}"></label></div>
                <p class="hint">The VLAN each probe should test from. It is matched to the UniFi network once connected, and Policies → Guided setup then plans a probe container on that VLAN. Until that is done, both probes use Docker's own network and see the same filtering.</p>
                <h3>Other networks</h3>
                <p class="hint">Add every other network you want to filter and test, such as guest Wi-Fi, BYOD, CCTV or a computer lab. Each gets its own probe, planned on its VLAN under Policies → Guided setup once UniFi is connected, and switched on when its container runs. Leave rows blank if you don't need them; you can add more later under Settings → Network profiles.</p>
                @php($types = \App\Models\NetworkType::query()->ordered()->get()->reject(fn ($type) => in_array($type->key, ['staff', 'student'], true)))
                <div class="network-rows">
                    <div class="network-row network-head"><span>Network name</span><span>Network type</span><span>New type name</span><span>VLAN</span></div>
                    @for($row = 0; $row < \App\Http\Controllers\InstallController::EXTRA_NETWORK_ROWS; $row++)
                        @php($saved = old("networks.{$row}", $data['networks'][$row] ?? []))
                        <div class="network-row">
                            <label><span class="sr-only">Network name</span><input name="networks[{{ $row }}][name]" maxlength="60" placeholder="{{ ['Guest Wi-Fi', 'CCTV', 'Computer lab', '', '', ''][$row] ?? '' }}" value="{{ $saved['name'] ?? '' }}"></label>
                            <label><span class="sr-only">Network type</span><select name="networks[{{ $row }}][type]">
                                @foreach($types as $type)<option value="{{ $type->key }}" @selected(($saved['type'] ?? ($row === 0 ? 'guest' : 'other')) === $type->key)>{{ $type->name }}</option>@endforeach
                                <option value="staff" @selected(($saved['type'] ?? '') === 'staff')>Staff</option>
                                <option value="student" @selected(($saved['type'] ?? '') === 'student')>Students</option>
                                <option value="__new" @selected(($saved['type'] ?? '') === '__new')>New type…</option>
                            </select></label>
                            <label><span class="sr-only">New type name</span><input name="networks[{{ $row }}][new_type]" maxlength="60" placeholder="e.g. CCTV" value="{{ $saved['new_type'] ?? '' }}"></label>
                            <label><span class="sr-only">VLAN</span><input name="networks[{{ $row }}][vlan]" inputmode="numeric" placeholder="{{ [80, 30, 40, '', '', ''][$row] ?? '' }}" value="{{ $saved['vlan'] ?? '' }}"></label>
                        </div>
                    @endfor
                </div>
                <p class="hint">The type decides which shared allow and block lists apply. A network of the same type as Staff or Students shares their rules; a new type (for example CCTV) gets its own.</p>
            @elseif($step === 6)
                <div class="fields two"><label>SMTP host <span class="optional">optional</span><input name="mail_host" value="{{ old('mail_host', $data['mail_host'] ?? '') }}"></label><label>SMTP port<input name="mail_port" type="number" value="{{ old('mail_port', $data['mail_port'] ?? 587) }}"></label></div>
                <label>Encryption<select name="mail_encryption">@foreach(['tls' => 'STARTTLS when offered (587, or 25)', 'ssl' => 'TLS from the start (SMTPS, 465)', 'none' => 'None: plain SMTP (internal relay on 25)'] as $value => $label)<option value="{{ $value }}" @selected(old('mail_encryption', $data['mail_encryption'] ?? 'tls') === $value)>{{ $label }}</option>@endforeach</select></label>
                <div class="fields two"><label>SMTP username<input name="mail_username" value="{{ old('mail_username', $data['mail_username'] ?? '') }}"></label><label>SMTP password<input name="mail_password" type="password"></label></div>
                <div class="fields two"><label>From address<input name="mail_from_address" type="email" value="{{ old('mail_from_address', $data['mail_from_address'] ?? '') }}"></label><label>IT notification address<input name="it_notification_address" type="email" required value="{{ old('it_notification_address', $data['it_notification_address'] ?? '') }}"></label></div>
            @elseif($step === 7)
                <label class="choice"><input type="hidden" name="auto_approve_staff" value="0"><input type="checkbox" name="auto_approve_staff" value="1" @checked(old('auto_approve_staff', $data['auto_approve_staff'] ?? false))><span><strong>Auto-approve requests for trusted networks</strong><small>Requests that are only for network types marked Auto-approve (Staff by default; change under Settings → Network types) are approved straight away.</small></span></label>
                <label class="choice"><input type="hidden" name="students_can_request" value="0"><input type="checkbox" name="students_can_request" value="1" @checked(old('students_can_request', $data['students_can_request'] ?? false))><span><strong>Students may submit requests</strong><small>Students can always run website checks.</small></span></label>
                <label>Default temporary access duration (days)<input name="default_temporary_days" type="number" min="1" max="365" value="{{ old('default_temporary_days', $data['default_temporary_days'] ?? 30) }}"></label>
                <label>Website categories <span class="optional">comma separated</span><textarea name="categories" rows="3" required>{{ old('categories', $data['categories'] ?? 'Teaching and learning, Research, Professional development, Administration, Other') }}</textarea></label>
                <h3>Two-person approval</h3>
                <label class="choice"><input type="hidden" name="two_person_site_wide" value="0"><input type="checkbox" name="two_person_site_wide" value="1" @checked(old('two_person_site_wide', $data['two_person_site_wide'] ?? true))><span><strong>Site-wide changes need two IT officers</strong><small>Approving site-wide access, and changing site-wide lists by hand, waits for a second IT officer. Skipped while only one IT officer is enabled.</small></span></label>
                <label class="choice"><input type="hidden" name="two_person_override" value="0"><input type="checkbox" name="two_person_override" value="1" @checked(old('two_person_override', $data['two_person_override'] ?? true))><span><strong>Allow approving alone when no other IT officer is available</strong><small>With a reason; the other officers are emailed to review it.</small></span></label>
                <label>Sensitive categories <span class="optional">need two IT officers · comma or line separated</span><textarea name="sensitive_categories" rows="3">{{ old('sensitive_categories', $data['sensitive_categories'] ?? implode(', ', \App\Services\Approval\TwoPersonRule::DEFAULT_SENSITIVE)) }}</textarea><small>Used once website categories are turned on (Settings → Website categories).</small></label>
                <h3>Exception reviews</h3>
                <div class="fields two"><label>Review exceptions every (months)<input name="review_months" type="number" min="1" max="36" value="{{ old('review_months', $data['review_months'] ?? 12) }}"></label><label>Remove unreviewed ones after (days)<input name="grace_days" type="number" min="1" max="90" value="{{ old('grace_days', $data['grace_days'] ?? 14) }}"></label></div>
                <h3>Website categories <span class="optional">optional</span></h3>
                <p class="hint">Shows what a website is (for example Social Networks or Games) on requests and checks, and which UniFi categories block it. Sensitive-category approvals, smart approval and the category guard need it. It sends each looked-up domain to Cloudflare, so it stays off unless you tick it. You can also turn it on later under Settings → Website categories.</p>
                <label class="choice"><input type="hidden" name="categories_enabled" value="0"><input type="checkbox" name="categories_enabled" value="1" @checked(old('categories_enabled', $data['categories_enabled'] ?? false))><span><strong>Look up website categories with Cloudflare</strong><small>Needs a free Cloudflare account and an API token with Radar read access (and Intel read, with an account ID, for less popular sites).</small></span></label>
                <div class="fields three">
                    <label>Source<select name="categories_source">@foreach(['auto' => 'Intel, then Radar', 'radar' => 'Radar only', 'intel' => 'Intel only'] as $value => $label)<option value="{{ $value }}" @selected(old('categories_source', $data['categories_source'] ?? 'auto') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label>API token<input type="password" name="categories_token" autocomplete="off" placeholder="{{ filled($data['categories_token'] ?? null) ? 'Saved; leave blank to keep it' : '' }}">@error('categories_token')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label>Account ID <span class="optional">for Intel</span><input name="categories_account_id" value="{{ old('categories_account_id', $data['categories_account_id'] ?? '') }}" maxlength="32">@error('categories_account_id')<small class="field-error">{{ $message }}</small>@enderror</label>
                </div>
                <div class="button-row"><button class="button secondary small" type="submit" formaction="{{ route('install.categories-test') }}" formnovalidate>Test lookup</button></div>
                @isset($data['categories_test'])<div class="alert {{ $data['categories_test']['ok'] ? 'success' : 'error' }}">{{ $data['categories_test']['ok'] ? 'It works. ' : 'The test lookup failed: ' }}{{ $data['categories_test']['message'] }}</div>@endisset
            @else
                @php($extraNetworks = collect($data['networks'] ?? [])->filter(fn ($row) => filled($row['name'] ?? null)))
                <div class="review-grid"><div><span>Networks</span><strong>Students{{ filled($data['student_network_id'] ?? null) ? ' (VLAN '.$data['student_network_id'].')' : '' }}, Staff{{ filled($data['staff_network_id'] ?? null) ? ' (VLAN '.$data['staff_network_id'].')' : '' }}@foreach($extraNetworks as $row), {{ $row['name'] }}{{ filled($row['vlan'] ?? null) ? ' (VLAN '.$row['vlan'].')' : '' }}@endforeach</strong></div><div><span>School</span><strong>{{ $data['school_name'] ?? 'Not set' }}</strong></div><div><span>Authentication</span><strong>{{ ($data['oidc_enabled'] ?? false) ? 'OIDC + emergency admin'.(filled($data['oidc_scopes'] ?? null) ? ' · scopes '.$data['oidc_scopes'] : '').' · roles '.(($data['sync_roles'] ?? true) ? 'follow the rules at each sign-in' : 'set once, at the first sign-in') : 'Emergency admin only' }}</strong></div><div><span>Network provider</span><strong>{{ ($data['unifi_enabled'] ?? false) ? 'UniFi · '.str($data['unifi_mode'] ?? 'read_only')->replace('_', ' ')->title().(isset($data['unifi_test']) ? ($data['unifi_test']['ok'] ? ' · tested OK' : ' · last test failed') : ' · not tested') : 'Simulation' }}</strong></div><div><span>Network profiles</span><strong>Student and Staff</strong></div><div><span>Notifications</span><strong>{{ $data['it_notification_address'] ?? 'Not set' }}</strong></div><div><span>Website categories</span><strong>{{ ($data['categories_enabled'] ?? false) ? 'Cloudflare'.(isset($data['categories_test']) ? ($data['categories_test']['ok'] ? ' · tested OK' : ' · last test failed') : ' · not tested') : 'Off' }}</strong></div><div><span>Timezone</span><strong>{{ $data['timezone'] ?? 'Not set' }}</strong></div></div>
                @if($data['sso_warning'] ?? null)<div class="alert warning">Single sign-on: {{ $data['sso_warning'] }} <a href="{{ route('install.show', 3) }}">Change it</a></div>@endif
                <div class="notice">Completing installation encrypts saved secrets, creates the administrator, and permanently locks this wizard. After you sign in, the home page lists what to finish: Guided setup, probes on their VLANs, a second IT officer, the school calendar, website categories and backups.</div>
            @endif
            <div class="form-actions">@if($step > 1)<a class="button secondary" href="{{ route('install.show', $step - 1) }}">Back</a>@endif<button class="button primary" type="submit">{{ $step === 8 ? 'Complete installation' : 'Continue' }}</button></div>
        </form>
    </section>
</main>
</body></html>
