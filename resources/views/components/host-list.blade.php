@props(['items', 'max' => 12])
{{-- A comma-separated list of hostnames: the first few, then "and N more" that opens up to 500 more in place. --}}
@php($items = array_values((array) $items))
<span class="host-list">{{ implode(', ', array_slice($items, 0, $max)) }}@if(count($items) > $max)<details class="host-list-more"><summary>and {{ number_format(count($items) - $max) }} more</summary>{{ implode(', ', array_slice($items, $max, 500)) }}@if(count($items) > $max + 500) … and {{ number_format(count($items) - $max - 500) }} not shown here.@endif</details>@endif</span>
