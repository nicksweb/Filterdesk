@if($embed)
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Check a website · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}?v={{ config('version.app') }}">
    <link rel="stylesheet" href="{{ asset('ui.css') }}?v={{ config('version.app') }}">
    <link rel="stylesheet" href="{{ asset('check-flow.css') }}?v={{ config('version.app') }}">
</head>
<body class="embed-body">
    @include('checks.public-form')
</body>
</html>
@else
<x-layouts.app title="Check a website">
    <x-slot:head><link rel="stylesheet" href="{{ asset('check-flow.css') }}"></x-slot:head>
    <div class="page-heading"><div><p class="eyebrow">Website check</p><h1>Is a website blocked?</h1><p>Check whether a website opens on the school network. No sign-in needed.</p></div></div>
    @include('checks.public-form')
</x-layouts.app>
@endif
