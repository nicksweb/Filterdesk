<?php

namespace App\Casts;

use App\Support\Reach;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<Reach|null, Reach|string|null> */
class AsReach implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Reach
    {
        return Reach::tryFrom($value === null ? null : (string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Reach => $value->value(),
            default => Reach::from((string) $value)->value(),
        };
    }
}
