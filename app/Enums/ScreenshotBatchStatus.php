<?php

namespace App\Enums;

/**
 * Where a batch of screenshots stands in the automated processing that reads the group members out of them.
 */
enum ScreenshotBatchStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de traitement',
            self::Processed => 'Traité',
            self::Failed => 'Échec du traitement',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Processed => 'green',
            self::Failed => 'red',
        };
    }
}
