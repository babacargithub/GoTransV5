---
paths:
  - 'resources/views/website/**'
---

# Views Website

## Public website icons = anonymous blade components, not inline SVG paths
Public website (resources/views/website/**) uses plain SVG icons as anonymous blade components in resources/views/components/website/icon/*.blade.php (Heroicons outline paths). Usage: <x-website.icon.chevron-right class="h-5 w-5 text-brand-navy" />. Each component takes `class` (full replacement, default 'h-5 w-5') and `strokeWidth` props. No Flux on the public website, no icon package installed. Prefer adding a component here over pasting raw <svg> paths into a view.

Also: long Tailwind class lists with bracket arbitrary values (e.g. shadow-[0_2px_10px_rgba(...)]) get line-wrapped and corrupted by the blade formatter on this project — put that styling in an @layer components class in resources/css/app.css instead (see .caravane-row).
