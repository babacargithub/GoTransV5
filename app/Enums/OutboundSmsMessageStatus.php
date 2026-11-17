<?php

namespace App\Enums;

/**
 * Life of an SMS the app asked Orange to send. Sent means Orange accepted the request, not that the phone
 * received it. Skipped means the app deliberately did not send it (test environment).
 */
enum OutboundSmsMessageStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Sent => 'Envoyé',
            self::Failed => 'Échoué',
            self::Skipped => 'Non envoyé',
        };
    }
}
