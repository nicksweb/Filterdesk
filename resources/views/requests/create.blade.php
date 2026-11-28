<x-layouts.app title="Request website access">
    <x-slot:head><link rel="stylesheet" href="{{ asset('request-flow.css') }}"></x-slot:head>
    @if($short ?? false)
        {{-- The short form: what and why. IT decides who it's for, the category and how long. --}}
        <div class="page-heading"><div><p class="eyebrow">Ask IT</p><h1>Ask for a website</h1><p>Tell IT which website you need and why. They'll email you when they've looked at it.</p></div></div>
        <form class="panel form-panel" method="post" action="{{ route('requests.store') }}">@csrf<input type="hidden" name="request_type" value="allow">
            <label>Website <span class="optional">or several, separated by spaces or commas</span><textarea name="domains" rows="2" required placeholder="example.com">{{ old('domains', $prefillDomains ?? '') }}</textarea>@error('domains')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>Why do you need it? @unless($reasonRequired)<span class="optional">optional</span>@endunless<textarea name="use_case" rows="4" @if($reasonRequired) required minlength="{{ $reasonMinLength }}" @endif placeholder="For example: my history assignment on the gold rush">{{ old('use_case') }}</textarea>@error('use_case')<small class="field-error">{{ $message }}</small>@enderror</label>
            <x-privacy-note /><div class="form-actions"><button class="button primary">Send to IT</button></div>
        </form>
    @else
    <div class="page-heading"><div><p class="eyebrow">{{ $block ? 'New block request' : 'New access request' }}</p><h1>{{ $block ? 'Request a website block' : 'Request website access' }}</h1><p>{{ $block ? 'Tell IT which websites should be blocked, for whom, and for how long. IT reviews every block before it is applied.' : 'Tell IT what is needed, who needs it, and for how long.' }}</p></div>@if($block)<a class="button secondary" href="{{ route('requests.create', ['domains' => old('domains', $prefillDomains ?? '')]) }}">Request access instead</a>@elseif(! auth()->user()->isStudent())<a class="button secondary" href="{{ route('requests.create', ['type' => 'block', 'domains' => old('domains', $prefillDomains ?? '')]) }}">Request a block instead</a>@endif</div>
    <form class="panel form-panel" method="post" action="{{ route('requests.store') }}">@csrf<input type="hidden" name="request_type" value="{{ $block ? 'block' : 'allow' }}">
        <label>Domains <span class="optional">up to 25, separated by spaces or commas</span><textarea name="domains" rows="3" required placeholder="example.com&#10;learning.example.org">{{ old('domains', $prefillDomains ?? '') }}</textarea></label>
        @if(!empty($siteCategories))<div class="category-guide">@foreach($siteCategories as $domain => $lookup)<x-site-categories :lookup="$lookup" :domain="$domain" />@endforeach<small class="hint">A guide to what these sites are, from a website category service. Mention anything surprising in the use case.</small></div>@endif
        @php($ownTypes = array_values(array_intersect((array) auth()->user()->network_types, $networkTypes->pluck('key')->all())))
        @php($chosenTypes = old('network_types', $ownTypes !== [] && ! $block ? $ownTypes : ($block ? $networkTypes->reject(fn ($type) => $type->auto_approve)->pluck('key')->take(1)->all() : $networkTypes->pluck('key')->take(1)->all())))
        <fieldset><legend>{{ $block ? 'Block it on' : 'Which networks need access?' }}</legend><div class="option-grid">
            @foreach($networkTypes as $type)
                <label class="choice"><input type="checkbox" name="network_types[]" value="{{ $type->key }}" @checked(in_array($type->key, (array) $chosenTypes, true))><span><strong>{{ $type->name }}</strong><small>{{ $type->description ?: 'Every '.$type->name.' network' }}</small></span></label>
            @endforeach
        </div>@error('network_types')<p class="field-error">{{ $message }}</p>@enderror</fieldset>
        @if($block && $blockHoursLayers->isNotEmpty())
            <fieldset class="hours-choice"><legend>When should it be blocked?</legend><div class="option-grid">
                <label class="choice"><input type="radio" name="hours" value="school" @checked(old('hours', $blockHoursDefault) === 'school')><span><strong>School hours only</strong><small>{{ $blockHoursLayers->map(fn ($layer) => ($layer->sync_details['schedule'] ?? 'on the school-hours schedule').' for '.\App\Models\NetworkType::labelFor($layer->audience))->join('; ') }}. Open after hours and at weekends.</small></span></label>
                <label class="choice"><input type="radio" name="hours" value="all" @checked(old('hours', $blockHoursDefault) === 'all')><span><strong>All the time</strong><small>Day and night, every day.</small></span></label>
            </div><p class="hint">IT confirms this when approving. Networks without a school-hours filter are blocked all the time.</p></fieldset>
        @endif
        <label>Category<select name="category" required>@foreach($categories as $category)<option @selected(old('category')===$category)>{{ $category }}</option>@endforeach</select></label>
        <label>{{ $reasonLabel }}@unless($reasonRequired)<span class="optional">optional</span>@endunless<textarea name="use_case" rows="5" @if($reasonMinLength) minlength="{{ $reasonMinLength }}" @endif maxlength="5000" @required($reasonRequired) placeholder="{{ $reasonHint }}">{{ old('use_case') }}</textarea></label>
        <fieldset><legend>{{ $block ? 'Block period' : 'Access period' }}</legend><div class="option-grid"><label class="choice"><input type="radio" name="access_duration" value="permanent" @checked(old('access_duration', 'permanent') === 'permanent')><span><strong>Permanent</strong><small>{{ $block ? 'Keep it blocked until IT changes the policy.' : 'Keep the domain in the desired allow list until IT changes the policy.' }}</small></span></label><label class="choice"><input type="radio" name="access_duration" value="temporary" @checked(old('access_duration') === 'temporary')><span><strong>Temporary</strong><small>{{ $block ? 'Block it only for a period, such as an exam or a lesson.' : 'Remove it from the daily desired list after the end time.' }}</small></span></label><label class="choice"><input type="radio" name="access_duration" value="recurring" @checked(old('access_duration') === 'recurring')><span><strong>Recurring</strong><small>{{ $block ? 'Block it on chosen days, for example every Monday to Friday, for up to 6 months.' : 'On chosen days, all day or for set hours, for up to 6 months.' }}</small></span></label></div><div class="fields two temporary-fields"><label>Temporary start <span class="optional">optional</span><input name="requested_from" type="datetime-local" value="{{ old('requested_from') }}"></label><label>Temporary end <span class="optional">required when temporary</span><input name="requested_until" type="datetime-local" value="{{ old('requested_until') }}"></label></div>
        @php($schedule = old('schedule', ['days' => [1, 2, 3, 4, 5], 'all_day' => '1', 'from' => '08:00', 'to' => '15:00', 'every_weeks' => 1]))
        @php($calendar = app(\App\Services\SchoolCalendar::class))
        @php($terms = collect($calendar->terms())->filter(fn ($term) => $term['end'] >= now()->local()->format('Y-m-d'))->values())
        <div class="recurring-fields">
            @if($terms->isNotEmpty())
                <fieldset class="schedule-mode"><legend>Follow</legend>
                    <label class="choice compact"><input type="radio" name="schedule[mode]" value="dates" @checked(($schedule['mode'] ?? 'dates') === 'dates')><span>Dates I choose</span></label>
                    <label class="choice compact"><input type="radio" name="schedule[mode]" value="calendar" @checked(($schedule['mode'] ?? 'dates') === 'calendar')><span>The school calendar (terms and periods, skipping holidays)</span></label>
                </fieldset>
                <div class="calendar-fields">
                    <div class="day-picker" role="group" aria-label="Terms">
                        @foreach($terms as $term)
                            <label class="day wide"><input type="checkbox" name="schedule[terms][]" value="{{ $term['id'] }}" @checked(in_array((string) $term['id'], array_map('strval', (array) ($schedule['terms'] ?? [])), true))><span>{{ $term['name'] }}<small>{{ \Carbon\Carbon::parse($term['start'])->format('j M') }} – {{ \Carbon\Carbon::parse($term['end'])->format('j M Y') }}</small></span></label>
                        @endforeach
                    </div>
                    <p class="hint">Choose the terms; leave them all unticked for every term listed.</p>
                    @error('schedule.terms')<p class="field-error">{{ $message }}</p>@enderror
                    @if($calendar->bells())
                        <div class="day-picker" role="group" aria-label="Periods">
                            @foreach($calendar->bells() as $bell)
                                <label class="day wide"><input type="checkbox" name="schedule[periods][]" value="{{ $bell['id'] }}" @checked(in_array((string) $bell['id'], array_map('strval', (array) ($schedule['periods'] ?? [])), true))><span>{{ $bell['name'] }}<small>{{ $bell['from'] }}–{{ $bell['to'] }}</small></span></label>
                            @endforeach
                        </div>
                        <p class="hint">With <em>Set hours</em> below, access follows the periods you tick instead of the hours.</p>
                        @error('schedule.periods')<p class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>
            @endif
            <div class="fields two date-fields">
                <label>First day<input type="date" name="schedule[start_date]" value="{{ $schedule['start_date'] ?? now()->local()->format('Y-m-d') }}" min="{{ now()->local()->format('Y-m-d') }}"></label>
                <label>Last day <span class="optional">up to 6 months later</span><input type="date" name="schedule[end_date]" value="{{ $schedule['end_date'] ?? '' }}" max="{{ now()->local()->addMonthsNoOverflow(6)->format('Y-m-d') }}"></label>
            </div>
            <div class="day-picker" role="group" aria-label="Days of the week">
                @foreach([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                    <label class="day"><input type="checkbox" name="schedule[days][]" value="{{ $day }}" @checked(in_array((string) $day, array_map('strval', (array) ($schedule['days'] ?? [])), true))><span>{{ $label }}</span></label>
                @endforeach
            </div>
            @error('schedule.days')<p class="field-error">{{ $message }}</p>@enderror
            <div class="fields three">
                <label>Hours<select name="schedule[all_day]"><option value="1" @selected(($schedule['all_day'] ?? '1') == '1')>All day (switches on at 1 am)</option><option value="0" @selected(($schedule['all_day'] ?? '1') == '0')>Set hours</option></select></label>
                <label>From<select name="schedule[from]">@for($hour = 0; $hour < 24; $hour++)@php($value = sprintf('%02d:00', $hour))<option value="{{ $value }}" @selected(($schedule['from'] ?? '08:00') === $value)>{{ \Carbon\Carbon::createFromFormat('H:i', $value)->format('g a') }}</option>@endfor</select></label>
                <label>To<select name="schedule[to]">@for($hour = 1; $hour <= 23; $hour++)@php($value = sprintf('%02d:00', $hour))<option value="{{ $value }}" @selected(($schedule['to'] ?? '15:00') === $value)>{{ \Carbon\Carbon::createFromFormat('H:i', $value)->format('g a') }}</option>@endfor</select></label>
            </div>
            <label>Repeat<select name="schedule[every_weeks]">@foreach([1 => 'Every week', 2 => 'Every 2 weeks', 3 => 'Every 3 weeks', 4 => 'Every 4 weeks'] as $weeks => $label)<option value="{{ $weeks }}" @selected((int) ($schedule['every_weeks'] ?? 1) === $weeks)>{{ $label }}</option>@endforeach</select></label>
            @foreach(['schedule.start_date', 'schedule.end_date', 'schedule.to'] as $field)@error($field)<p class="field-error">{{ $message }}</p>@enderror @endforeach
            <p class="hint">With set hours, access opens {{ \App\Support\Schedule::margin() }} minutes before and closes {{ \App\Support\Schedule::margin() }} minutes after. All-day access switches on at 1 am each chosen day and off at midnight.</p>
        </div>
        </fieldset>
        <label class="choice"><input type="checkbox" name="standards_confirmed" value="1" required @checked(old('standards_confirmed'))><span><strong>{{ $block ? 'I have checked these are the websites to block' : 'I have reviewed this website for the intended audience' }}</strong><small>{{ $block ? 'Blocking a shared domain can break other sites; IT will check before applying it.' : 'I believe it is appropriate and does not breach school policy or the code of conduct.' }}</small></span></label>
        <x-privacy-note />
        <div class="form-actions"><a class="button secondary" href="{{ route('requests.index') }}">Cancel</a><button class="button primary">{{ $block ? 'Submit block request' : 'Submit request' }}</button></div>
    </form>
    @endif
</x-layouts.app>
