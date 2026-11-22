<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{{ $title }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #0f172a; font: 16px/1.7 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .page { max-width: 46rem; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
        .brand { font-weight: 700; margin-bottom: 1.5rem; }
        .document { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; padding: 1.25rem; overflow-wrap: anywhere; }
        .document h1 { font-size: 1.6rem; line-height: 1.25; margin-top: 0; }
        .document h2 { font-size: 1.3rem; margin-top: 2rem; }
        .document h3 { font-size: 1.1rem; margin-top: 1.5rem; }
        .document table { border-collapse: collapse; display: block; overflow-x: auto; }
        .document th, .document td { border: 1px solid #cbd5e1; padding: .4rem .7rem; text-align: left; }
        .document blockquote { margin-left: 0; padding-left: 1rem; border-left: 4px solid #cbd5e1; color: #475569; }
        .notice { text-align: center; padding: 4rem 1rem; }
        .notice h1 { font-size: 1.6rem; }
        .notice p { color: #475569; }
        @media (min-width: 640px) { .document { padding: 2rem 2.5rem; } }
        @media print { body { display: none !important; } }
    </style>
    {{ $head ?? '' }}
</head>
<body>
    <div class="page">
        <div class="brand">Globe One Transport</div>
        {{ $slot }}
    </div>
    {{ $scripts ?? '' }}
</body>
</html>
