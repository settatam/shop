<?php

namespace App\Enums;

enum AgentType: string
{
    case Background = 'background';
    case EventTriggered = 'event_triggered';
    case GoalOriented = 'goal_oriented';
    case Reactive = 'reactive';
    case Proactive = 'proactive';

    public function label(): string
    {
        return match ($this) {
            self::Background => 'Background',
            self::EventTriggered => 'Event Triggered',
            self::GoalOriented => 'Goal Oriented',
            self::Reactive => 'Reactive',
            self::Proactive => 'Proactive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Background => 'Runs on a schedule to perform recurring tasks',
            self::EventTriggered => 'Activated in response to specific events',
            self::GoalOriented => 'Works towards achieving a specific goal',
            self::Reactive => 'Reacts to changes and synchronizes state',
            self::Proactive => 'Proactively identifies opportunities and takes action',
        };
    }
}
