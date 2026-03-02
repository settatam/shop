<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case WaitingForAgent = 'waiting_for_agent';
    case Assigned = 'assigned';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::WaitingForAgent => 'Waiting for Agent',
            self::Assigned => 'Assigned',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'green',
            self::WaitingForAgent => 'yellow',
            self::Assigned => 'blue',
            self::Closed => 'gray',
        };
    }
}
