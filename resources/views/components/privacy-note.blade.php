@php($privacyUrl = app(\App\Services\SettingsRepository::class)->get('school.privacy_url'))
@if($privacyUrl)<small class="hint privacy-note">{{ $slot->isEmpty() ? 'How this information is used:' : $slot }} <a href="{{ $privacyUrl }}" target="_blank" rel="noopener">{{ app(\App\Services\SettingsRepository::class)->get('school.privacy_label') ?: 'Privacy policy' }}</a></small>@endif
