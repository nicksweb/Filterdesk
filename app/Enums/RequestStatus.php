<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Pending = 'pending';
    case NeedsInformation = 'needs_information';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ActionRequired = 'action_required';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Failed = 'failed';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    /** Statuses still awaiting an IT decision. */
    public static function open(): array
    {
        return [self::Pending, self::NeedsInformation];
    }

    /** Approved statuses whose domains belong in the desired policy. */
    public static function granted(): array
    {
        return [self::Approved, self::ActionRequired, self::Active, self::Failed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function isGranted(): bool
    {
        return in_array($this, self::granted(), true);
    }
}
