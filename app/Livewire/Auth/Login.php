<?php

namespace App\Livewire\Auth;

use App\Models\Ambassador;
use App\Models\Amicale;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $username = '';

    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($this->username).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'username' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        $user = User::where('username', $this->username)->first();

        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        // Ambassadors and amicales only sign in on their own domain, and staff never do: same error as a bad password.
        $requiredAccountRole = $this->accountRoleOfCurrentDomain();
        $partnerAccountRoles = [Ambassador::ACCOUNT_ROLE_NAME, Amicale::ACCOUNT_ROLE_NAME];

        $isAccountOfThisDomain = $requiredAccountRole === null
            ? ! $user->hasAnyRole($partnerAccountRoles)
            : $user->hasRole($requiredAccountRole);

        if (! $isAccountOfThisDomain) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        // Only once the credentials are right, so a stranger never learns anything about an account.
        $partnerRejectionMessage = match ($requiredAccountRole) {
            Ambassador::ACCOUNT_ROLE_NAME => $user->ambassador?->accessRejectionMessage(),
            Amicale::ACCOUNT_ROLE_NAME => $user->amicale?->accessRejectionMessage(),
            default => null,
        };

        if ($partnerRejectionMessage !== null) {
            throw ValidationException::withMessages([
                'username' => $partnerRejectionMessage,
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => $this->remember,
            ]);

            $this->redirect(route('two-factor.login'), navigate: true);

            return;
        }

        Auth::login($user, $this->remember);
        session()->regenerate();

        $this->redirectIntended(
            default: match ($requiredAccountRole) {
                Ambassador::ACCOUNT_ROLE_NAME => route('ambassador.home'),
                Amicale::ACCOUNT_ROLE_NAME => route('amicale.home'),
                default => route('back-office.departs.index'),
            },
            navigate: true,
        );
    }

    public function render()
    {
        return view('livewire.auth.login', [
            'isAmbassadorDomain' => $this->accountRoleOfCurrentDomain() === Ambassador::ACCOUNT_ROLE_NAME,
            'isAmicaleDomain' => $this->accountRoleOfCurrentDomain() === Amicale::ACCOUNT_ROLE_NAME,
        ]);
    }

    /**
     * The role every account signing in on the current domain must carry: null on the back-office domains.
     */
    private function accountRoleOfCurrentDomain(): ?string
    {
        return match (request()->getHost()) {
            config('app.ambassador_domain') => Ambassador::ACCOUNT_ROLE_NAME,
            config('app.amicale_admin_domain') => Amicale::ACCOUNT_ROLE_NAME,
            default => null,
        };
    }
}
