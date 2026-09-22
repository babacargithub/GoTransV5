<?php

use App\Models\Trajet;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give every existing trajet a unique slug derived from its city pair
     * (falling back to public_name / name / code).
     */
    public function up(): void
    {
        Trajet::query()->whereNull('slug')->orderBy('id')->each(function (Trajet $trajet) {
            $trajet->slug = Trajet::generateUniqueSlug($trajet->buildSlugSource(), $trajet->getKey());
            $trajet->saveQuietly();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Trajet::query()->update(['slug' => null]);
    }
};
