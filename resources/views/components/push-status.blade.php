@props(['status' => null])
{{-- State of a push started from a button and run in the background. --}}
@if($status)
    @if($status['state'] === 'running')
        <div class="notice push-running"><strong>Push in progress</strong> (started {{ \Illuminate\Support\Carbon::parse($status['since'])->diffForHumans() }}). This can take a minute through the cloud connector; refresh to see the result.</div>
    @elseif(\Illuminate\Support\Carbon::parse($status['since'])->gt(now()->subMinutes(10)))
        <div class="{{ $status['state'] === 'done' ? 'alert success' : 'alert error' }}"><strong>Last push {{ $status['state'] === 'done' ? 'finished' : 'did not finish' }}</strong> {{ \Illuminate\Support\Carbon::parse($status['since'])->diffForHumans() }}: {{ $status['message'] ?? '' }}</div>
    @endif
@endif
