@props(['policy'])
@php($labels = ['in_sync' => ['In sync', 'ok'], 'changes_due' => ['Changes to push', 'action_required'], 'networks' => ['Networks differ', 'action_required'], 'drift' => ['Changed in controller', 'failed'], 'error' => ['Cannot check', 'failed']])
@if($policy->sync_status && isset($labels[$policy->sync_status]))
    <span class="status status-{{ $labels[$policy->sync_status][1] }}" title="{{ $policy->sync_details['summary'] ?? '' }} · checked {{ $policy->sync_checked_at?->diffForHumans() }}">{{ $labels[$policy->sync_status][0] }}</span>
@elseif(filled($policy->provider_reference))
    <span class="status status-pending">Not checked yet</span>
@else
    <span class="status status-pending">Not linked</span>
@endif
