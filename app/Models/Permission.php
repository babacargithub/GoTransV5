<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Application permission.
 *
 * Adds a `label` column on top of Spatie's model: the French, public-facing
 * wording managers see in the back-office UI. The internal `name` (e.g.
 * `cancel-paid-booking`) is the stable constant every guard checks against and
 * is never edited from the UI.
 *
 * @property string $name
 * @property string|null $label
 */
class Permission extends SpatiePermission
{
    /**
     * The French label to display, falling back to the internal name.
     */
    public function displayLabel(): string
    {
        return $this->label !== null && $this->label !== '' ? $this->label : $this->name;
    }
}
