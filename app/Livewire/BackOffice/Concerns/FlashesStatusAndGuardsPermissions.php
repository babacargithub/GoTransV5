<?php

namespace App\Livewire\BackOffice\Concerns;

use App\Enums\PermissionName;

/**
 * Flash message properties plus the permission guard shared by the finance
 * pages whose traits (ManagesAccounts, ManagesCaisses) expect them.
 */
trait FlashesStatusAndGuardsPermissions
{
    public ?string $flashStatusMessage = null;

    public ?string $flashErrorMessage = null;

    protected function resetFlashMessages(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;
    }

    /**
     * Guard a permission-gated action. Returns false (and flashes an error)
     * when the current user lacks the permission; a `full-access` holder
     * always passes.
     */
    private function ensurePermittedOrFlash(PermissionName $requiredPermission): bool
    {
        if ($requiredPermission->allowedForCurrentUser()) {
            return true;
        }

        $this->flashErrorMessage = $requiredPermission->unauthorizedMessage();

        return false;
    }
}
