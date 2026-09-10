---
paths:
  - app/Models/Trajet.php
---

# Models

## Trajet has disabled + display_position; public listings use scopePubliclyVisible()
trajets table has `disabled` (bool, default false) and `display_position` (unsigned int, default 0). Public-facing listings MUST use Trajet::query()->publiclyVisible() (where disabled=false, ordered by display_position then name) — currently the website.home route. MobileAppController@listeDepartsTrajet abort_if($trajet->disabled, 404) but only inside its `website.*` branch; the mobile JSON API still serves disabled trajets. Back office TrajetList toggles `disabled` via a per-row flux:switch bound to $trajetActiveStates[id] (true = actif = not disabled), persisted in updatedTrajetActiveStates().
