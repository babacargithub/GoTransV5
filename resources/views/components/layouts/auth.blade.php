<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Globe One Transport' }}</title>

    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-background text-foreground antialiased min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-primary">Globe One Transport</h1>
        </div>
        <div class="card p-8">
            {{ $slot }}
        </div>
    </div>

    @fluxScripts
</body>
</html>
