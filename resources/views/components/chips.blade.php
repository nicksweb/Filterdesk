@props(['items', 'max' => 3])
{{-- Shows the first few; "+N" opens the rest in place. --}}
@php($items = array_values($items))
<span class="chips">
    @foreach(array_slice($items, 0, $max) as $item)<span class="chip">{{ $item }}</span>@endforeach
    @if(count($items) > $max)
        <details class="chip-more"><summary class="chip more">+{{ count($items) - $max }}</summary>
            <span class="chips">@foreach(array_slice($items, $max) as $item)<span class="chip">{{ $item }}</span>@endforeach</span>
        </details>
    @endif
</span>
