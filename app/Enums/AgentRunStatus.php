<?php

namespace App\Enums;

enum AgentRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Running => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Cancelled => 'gray',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled]);
    }
}
