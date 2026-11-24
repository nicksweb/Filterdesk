@props(['items', 'id', 'rows' => 6, 'download' => null, 'limit' => 1000])
{{-- A read-only box of hostnames, one per line. Long lists show the first 1,000; the full list is a download. --}}
@php($items = array_values((array) $items))
<textarea rows="{{ $rows }}" readonly id="{{ $id }}">{{ implode("\n", array_slice($items, 0, $limit)) }}</textarea>
@if(count($items) > $limit)<small class="hint">Showing the first {{ number_format($limit) }} of {{ number_format(count($items)) }}.@if($download) <a href="{{ $download }}">Download all as .txt</a>.@endif</small>@endif
