---
paths:
  - 'app/Livewire/Auth/**'
---

# Auth

## Web login is by username, not email
Users log in with `username` (the canonical credential — legacy api.php /login already does auth()->attempt(['username',...])). App\Livewire\Auth\Login has a `$username` property, validates ['required','string'] (no email rule), looks up User::where('username', ...), and keys all validation errors on 'username'. config/fortify.php 'username' => 'username' (Fortify's own /login route + 'login' rate limiter key on it); 'email' => 'email' stays for password reset (reset link still goes by email).

Gap: app/Actions/Fortify/CreateNewUser still only collects name/email, so self-registered users get no username and cannot log in. Fine while users are seeded/admin-created; fix CreateNewUser + register view if registration is turned on for real.

## Fortify view blades must wrap their content in <x-layouts.auth>
resources/views/auth/*.blade.php are what FortifyServiceProvider's Fortify::loginView()/registerView()/etc. return. Each MUST wrap its body in `<x-layouts.auth title="...">` (which supplies <head>, @fluxAppearance, @vite, @fluxScripts). login.blade.php is the one that renders a Livewire component — it is `<x-layouts.auth><livewire:auth.login /></x-layouts.auth>`; the component itself carries NO #[Layout] attribute (that only fires for route-level full-page components, and would be ignored / risk double-wrap here). A bare `<livewire:auth.login />` with no wrapper ships no <head> → no CSS and no Livewire JS → the form does a native GET reload instead of authenticating, with no error shown.
