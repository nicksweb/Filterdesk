<x-layouts.app title="Health">
    <x-slot:head><link rel="stylesheet" href="{{ asset('admin.css') }}"></x-slot:head>
    <div class="page-heading">
        <div><p class="eyebrow">Health</p><h1>Is the policy in force?</h1><p>Each part links to where it is fixed. Parts without data yet (marked —) are left out of the score.</p></div>
        @if(auth()->user()->isAdmin())<a class="button secondary" href="{{ route('admin.diagnostics') }}">Diagnostics</a>@endif
    </div>
    <x-coverage :coverage="$coverage" />
</x-layouts.app>
