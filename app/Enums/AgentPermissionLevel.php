<?php

namespace App\Enums;

enum AgentPermissionLevel: string
{
    case Auto = 'auto';
    case Approve = 'approve';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Approve => 'Require Approval',
            self::Block => 'Disabled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Auto => 'Execute actions without approval',
            self::Approve => 'Queue all actions for approval',
            self::Block => 'Agent is disabled',
        };
    }

    public function requiresApproval(): bool
    {
        return $this === self::Approve;
    }

    public function isBlocked(): bool
    {
        return $this === self::Block;
    }
}
