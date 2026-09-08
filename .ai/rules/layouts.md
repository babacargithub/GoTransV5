---
paths:
  - resources/views/components/layouts/back-office.blade.php
---

# Layouts

## Back-office layout: header nav = sections, sidebar = départ stats
Two distinct nav areas in the back-office layout:
- `flux:header` horizontal `flux:navbar` = links to the main sections (Finances / Départs / Admin dropdowns, each opening a `flux:navmenu` of pages). On mobile the navbar is `max-lg:hidden` and the same items collapse into a right-aligned `flux:dropdown` hamburger (`bars-3`) using `flux:menu` + `flux:menu.group`.
- `flux:sidebar` (stashable, left) is reserved for booking stats of the current départs — not implemented yet, kept visible with a placeholder. Its mobile toggle is the LEFT `flux:sidebar.toggle` (`bars-2`, `inset="left"`); a hidden `x-init` div in `flux:main` dispatches `flux-sidebar-toggle` on mobile so the sidebar opens by default each load.
Only `back-office.departs.index` is a real route; every other menu item is `href="#"` until built. header/sidebar/main must stay direct children of `<body>` (Flux grid uses `:has(> [data-flux-main])`).
