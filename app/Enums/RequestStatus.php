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
    case Failed = 'failed';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
