<?php

namespace App\Enums;

enum AgentTriggerType: string
{
    case Scheduled = 'scheduled';
    case Event = 'event';
    case Manual = 'manual';
    case Goal = 'goal';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Event => 'Event',
            self::Manual => 'Manual',
            self::Goal => 'Goal',
        };
    }
}
