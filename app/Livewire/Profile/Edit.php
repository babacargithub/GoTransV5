<?php

namespace App\Livewire\Profile;

use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Jetstream\DeleteUser;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Edit extends Component
{
    public string $name = '';
    public string $email = '';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $confirmingTwoFactor = false;
    public string $twoFactorCode = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfileInformation(UpdateUserProfileInformation $action): void
    {
        $action->update(Auth::user(), [
            'name' => $this->name,
            'email' => $this->email,
        ]);

        session()->flash('status', 'profile-updated');
    }

    public function updatePassword(UpdateUserPassword $action): void
    {
        $action->update(Auth::user(), [
            'current_password' => $this->current_password,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        session()->flash('status', 'password-updated');
    }

    public function enableTwoFactorAuthentication(EnableTwoFactorAuthentication $enable): void
    {
        $enable(Auth::user());
        $this->confirmingTwoFactor = true;
    }

    public function confirmTwoFactorAuthentication(ConfirmTwoFactorAuthentication $confirm): void
    {
        $confirm(Auth::user(), $this->twoFactorCode);

        $this->confirmingTwoFactor = false;
        $this->reset('twoFactorCode');
        session()->flash('status', 'two-factor-confirmed');
    }

    public function disableTwoFactorAuthentication(DisableTwoFactorAuthentication $disable): void
    {
        $disable(Auth::user());
        $this->confirmingTwoFactor = false;
    }

    public function deleteAccount(DeleteUser $deleteUser): void
    {
        /** @var User $user */
        $user = Auth::user();
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $deleteUser->delete($user);

        $this->redirect(route('login'), navigate: true);
    }

    public function render()
    {
        /** @var User $user */
        $user = Auth::user()->fresh();

        return view('livewire.profile.edit', [
            'user' => $user,
            'twoFactorConfirmed' => (bool) $user->two_factor_confirmed_at,
            'recoveryCodes' => $user->two_factor_confirmed_at
                ? $user->recoveryCodes()
                : [],
        ]);
    }
}
