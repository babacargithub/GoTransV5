<?php

namespace App\Actions\Jetstream;

use App\Models\User;

class DeleteUser
{
    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        $user->tokens->each->delete();
        $user->delete();
    }
}
