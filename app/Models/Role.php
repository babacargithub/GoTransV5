<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application role.
 *
 * Adds a `label` column on top of Spatie's model: the French, public-facing
 * wording managers see in the back-office UI. The internal `name` stays stable.
 *
 * @property string $name
 * @property string|null $label
 */
class Role extends SpatieRole
{
    /**
     * The French label to display, falling back to the internal name.
     */
    public function displayLabel(): string
    {
        return $this->label !== null && $this->label !== '' ? $this->label : $this->name;
    }
}
