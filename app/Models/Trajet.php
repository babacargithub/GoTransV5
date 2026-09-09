<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Trajet extends Model
{
    //
    const UGB_DAKAR = 1;

    const DAKAR_UGB = 2;

    const HORAIRE_NUIT_UGB_DK = 1;

    const HORAIRE_MATIN_UGB_DK = 4;

    const HORAIRE_MATIN_DK_UG = 3;

    const HORAIRE_APRES_MIDI_DK_UG = 2;

    const HORAIRE_APRES_MIDI_UGB_DK = 5;

    public $timestamps = false;

    protected $fillable = [
        'name', 'public_name', 'length', 'end_point', 'start_point', 'deleted_at', 'departure_city', 'arrival_city', 'code', 'slug',
    ];

    protected static function booted(): void
    {
        static::saving(function (Trajet $trajet) {
            if (blank($trajet->slug)) {
                $trajet->slug = static::generateUniqueSlug($trajet->buildSlugSource(), $trajet->getKey());
            }
        });
    }

    /**
     * The public website resolves trajets by their SEO-friendly slug, while the legacy
     * API and back-office keep binding by id — so the custom key is declared per-route
     * (`{trajet:slug}`) rather than globally via getRouteKeyName().
     */
    public function buildSlugSource(): string
    {
        if (filled($this->public_name)) {
            return $this->public_name;
        }

        $cityPair = trim("{$this->departure_city} {$this->arrival_city}");

        return $cityPair !== '' ? $cityPair : (string) ($this->name ?? $this->code);
    }

    /**
     * Slugify the given source and append a numeric suffix until the slug is unique
     * across the trajets table (optionally ignoring the row being updated).
     */
    public static function generateUniqueSlug(string $source, ?int $ignoreTrajetId = null): string
    {
        $baseSlug = Str::slug($source) ?: 'trajet';
        $candidateSlug = $baseSlug;
        $suffix = 2;

        while (static::slugAlreadyTaken($candidateSlug, $ignoreTrajetId)) {
            $candidateSlug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $candidateSlug;
    }

    protected static function slugAlreadyTaken(string $slug, ?int $ignoreTrajetId): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->when($ignoreTrajetId, fn ($query) => $query->whereKeyNot($ignoreTrajetId))
            ->exists();
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function pointDeps(): HasMany
    {
        return $this->hasMany(PointDep::class);
    }

    public function horaires(): HasMany
    {
        return $this->hasMany(Horaire::class);
    }

    public function departs(): HasMany
    {
        return $this->hasMany(Depart::class);
    }
}
