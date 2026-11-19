@props(['status'])
@php($value = $status instanceof \BackedEnum ? $status->value : $status)
<span class="status status-{{ $value }}">{{ str($value)->replace('_', ' ')->title() }}</span>
