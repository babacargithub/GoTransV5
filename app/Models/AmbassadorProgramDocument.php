<?php

namespace App\Models;

use App\Enums\AmbassadorProgramDocumentType;
use Database\Factories\AmbassadorProgramDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A markdown document (presentation, contract) shared with ambassador candidates through one-time links and
 * edited from the back office. There is one document per type.
 */
class AmbassadorProgramDocument extends Model
{
    /** @use HasFactory<AmbassadorProgramDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AmbassadorProgramDocumentType::class,
        ];
    }
}
