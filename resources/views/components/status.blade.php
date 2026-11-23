@props(['status'])
@php($value = $status instanceof \BackedEnum ? $status->value : $status)
<span class="status status-{{ $value }}">{{ $status instanceof \App\Enums\RequestStatus ? $status->label() : str($value)->replace('_', ' ')->title() }}</span>
