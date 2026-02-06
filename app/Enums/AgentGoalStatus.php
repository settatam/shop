<?php

namespace App\Enums;

enum AgentGoalStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'gray',
            self::Failed => 'red',
        };
    }
}
