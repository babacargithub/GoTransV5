<?php

namespace App\Enums;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Kind of document shared with ambassador candidates. There is exactly one document per type.
 */
enum AmbassadorProgramDocumentType: string
{
    case Presentation = 'presentation';
    case Contract = 'contract';

    public function label(): string
    {
        return match ($this) {
            self::Presentation => 'Présentation du programme',
            self::Contract => 'Contrat',
        };
    }

    /**
     * A signed URL to this document that expires on its first open, or after the given number of days.
     */
    public function createOneTimeUrl(int $daysBeforeExpiry): string
    {
        return URL::temporarySignedRoute(
            'ambassador-program-document',
            now()->addDays($daysBeforeExpiry),
            ['documentType' => $this->value, 'link' => Str::uuid()->toString()],
        );
    }
}
