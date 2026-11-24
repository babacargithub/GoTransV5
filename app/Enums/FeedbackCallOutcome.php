<?php

namespace App\Enums;

/**
 * How a feedback call to a customer went. NoAnswer and Unreachable keep the customer in the "to call back"
 * list; Completed and Refused are final.
 */
enum FeedbackCallOutcome: string
{
    case Completed = 'completed';
    case NoAnswer = 'no_answer';
    case Unreachable = 'unreachable';
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'A répondu',
            self::NoAnswer => 'Pas de réponse',
            self::Unreachable => 'Injoignable',
            self::Refused => 'Refuse',
        };
    }

    /**
     * Whether the customer must be called again later.
     */
    public function needsCallBack(): bool
    {
        return $this === self::NoAnswer || $this === self::Unreachable;
    }
}
