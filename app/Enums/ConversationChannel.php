<?php

namespace App\Enums;

enum ConversationChannel: string
{
    case Web = 'web';
    case WhatsApp = 'whatsapp';
    case Slack = 'slack';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::WhatsApp => 'WhatsApp',
            self::Slack => 'Slack',
        };
    }
}
