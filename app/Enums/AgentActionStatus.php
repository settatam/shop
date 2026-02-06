<?php

namespace App\Enums;

enum AgentActionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Executed = 'executed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Executed => 'Executed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Approved => 'blue',
            self::Executed => 'green',
            self::Rejected => 'red',
            self::Failed => 'red',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isFinalized(): bool
    {
        return in_array($this, [self::Executed, self::Rejected, self::Failed]);
    }
}
