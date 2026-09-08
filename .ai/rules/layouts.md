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

## Back-office sidebar = DepartStatsSidebar Livewire component (booking counts)
The left flux:sidebar is no longer a placeholder. It embeds <livewire:back-office.depart-stats-sidebar /> (App\Livewire\BackOffice\DepartStatsSidebar + resources/views/livewire/back-office/depart-stats-sidebar.blade.php).

It rebuilds the legacy Vue sidebar: per upcoming départ, each bus with its réservations / sièges réservés / billets vendus counts, from the untouched DepartController@bookingsCount (legacy `departs/bookings_counts`). Self-contained: wire:poll.60s + a manual refresh button, so it does not re-render when the host full-page component updates.

Sidebar is widened on desktop via `lg:w-96` on the flux:sidebar (Flux's default w-64 is a low-specificity :where() rule, so a plain class overrides it). Items are deliberately roomy (bordered blocks, space-y). Still a direct child of <body>.
