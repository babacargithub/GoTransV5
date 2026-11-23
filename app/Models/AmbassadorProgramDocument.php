<?php

namespace App\Models;

use App\Enums\AmbassadorProgramDocumentType;
use Database\Factories\AmbassadorProgramDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A markdown document (presentation, contract) shared with ambassador candidates through one-time links and
 * edited from the back office. A contract edit creates a new version (so signed texts are never rewritten); the
 * current document of a type is its highest version.
 */
class AmbassadorProgramDocument extends Model
{
    /** @use HasFactory<AmbassadorProgramDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'version',
        'title',
        'content',
    ];

    /**
     * The current (highest version) document of the given type, if there is one.
     */
    public static function current(AmbassadorProgramDocumentType $type): ?self
    {
        return self::query()
            ->where('type', $type->value)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * The current document of every type: older contract versions are kept only as signature evidence.
     */
    public function scopeCurrentVersions(Builder $query): Builder
    {
        return $query->whereIn('id', self::query()
            ->selectRaw('MAX(id)')
            ->groupBy('type'));
    }

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
