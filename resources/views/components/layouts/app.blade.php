<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Globe One Transport' }}</title>

    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-background text-foreground antialiased">
    <header class="bg-primary text-primary-foreground px-6 py-4 flex items-center justify-between">
        <span class="font-bold text-lg">Globe One Transport</span>
        <nav class="flex items-center gap-4 text-sm">
            <a href="{{ route('dashboard') }}" class="hover:underline">Tableau de bord</a>
            <a href="{{ route('profile.edit') }}" class="hover:underline">Profil</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="hover:underline">Déconnexion</button>
            </form>
        </nav>
    </header>

    <main class="px-6 py-8">
        {{ $slot }}
    </main>

    @fluxScripts
</body>
</html>
