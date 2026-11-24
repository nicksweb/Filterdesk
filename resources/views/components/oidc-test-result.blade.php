@props(['result', 'roles' => ['admin' => 'Admin', 'it' => 'IT', 'teacher' => 'Teacher', 'student' => 'Student']])
{{-- What a Test sign-in brought back: the claims (personal values hidden), each group with buttons that add a rule, and the role these rules give. --}}
<div class="oidc-test-result" role="status">
    @if(! $result['ok'])
        <div class="alert error"><strong>Test sign-in failed.</strong> {{ $result['error'] }}</div>
    @else
        <p class="oidc-verdict">With these rules this account would be
            <strong>{{ $result['role'] ? ucfirst($result['role']) : 'refused' }}</strong>
            <small>{{ $result['rule'] ? 'matched '.$result['rule'] : ($result['role'] ? 'no rule matched: the default role' : 'no rule matched and unmatched users are refused') }}</small></p>
        @if($result['warning'])<div class="alert warning">{{ $result['warning'] }}</div>@endif
        @if($result['groups'])
            <p class="hint">Groups and roles sent{{ $result['group_claims'] ? ' (in '.implode(', ', $result['group_claims']).')' : '' }}. Pick the role a group should give; it is added to the role rules{{ $attributes->get('live') ? '' : ' (save to keep it)' }}.</p>
            <ul class="oidc-groups">
                @foreach($result['groups'] as $group)
                    @php($name = \App\Services\Auth\UserProvisioningService::groupLabel($group))
                    <li><code title="{{ $group }}">{{ $name }}</code>
                        <span class="button-row">@foreach($roles as $role => $label)<button type="button" class="button small secondary" data-append-rule="group:{{ strtolower($name) }}={{ $role }}" data-target="role_rules">{{ $label }}</button>@endforeach</span></li>
                @endforeach
            </ul>
        @endif
        <details class="hint"><summary>Every claim that came back ({{ count($result['claims']) }})</summary>
            <ul class="claim-list">@foreach($result['claims'] as $claim => $value)<li><code>{{ $claim }}</code> {{ $value }}</li>@endforeach</ul>
        </details>
    @endif
</div>
