---
paths:
  - 'app/Http/Resources/CaravaneDepartsResource.php,app/Http/Resources/MobileTrajetDepartsResource.php,app/Models/Depart.php,app/Http/Controllers/MobileAppController.php,resources/views/website/caravanes/show.blade.php'
---

# Caravanes

## Website caravane page uses CaravaneDepartsResource, not the mobile resource
MobileAppController@listeDepartsTrajet: the `website.*` branch renders website.caravanes.show with `CaravaneDepartsResource` (lean, fully eager-loaded — ~10 queries). The mobile JSON branch still returns `MobileTrajetDepartsResource` (per-bus point-departs/destinations/promo lookups = hundreds of queries; do not point the website at it).

Bus-selection is shared via `Depart::getBusesForBooking(?Collection $availableVehicules = null)` — reads the loaded `buses` collection; pass a shared vehicle collection (CaravaneDepartsResource derives it from the already-loaded buses, no extra query) when looping many departs so it's fetched once. `Bus::isFull()` short-circuits on an eager-loaded `has_available_seat` flag (`withExists('seats as has_available_seat', ...)` — an EXISTS probe, cheaper than counting) — that's how CaravaneDepartsResource makes bus-selection query-free per bus. That flag is loaded only because `getBusesForBooking()` still calls `isFull()` internally to prefer an open bus (see the rule below) — it is NOT exposed in the resource's output.

"Heures de départ" on the caravane page loads lazily: GET website.caravanes.schedule (`caravanes/horaires/{depart}?bus=`) -> MobileAppController@caravaneDepartSchedule, fetched by inline JS in show.blade.php when a <details> opens. Don't reintroduce server-side schedule eager-loading in the blade.

The read-only public pages (website.home, .yobante, .aide, .caravanes.show) run without session/cookie/CSRF middleware and website.home + .caravanes.show are additionally full-HTML cached — see .ai/rules/providers.md.

## Caravane listing: full/closed buses stay bookable, never greyed out
Business rule (explicit product decision, not a bug): on the public caravane page, a full or closed bus/départ is NOT shown as unavailable — the "Réserver" button stays active. The backend (StudentBooking -> the untouched mobile booking pipeline) puts the customer on the waiting list instead of rejecting the booking, so gating the UI on `full`/`closed` would just add friction for no reason.

CaravaneDepartsResource intentionally emits neither `full` nor `is_closed`/`closed` in its payload — the ONLY availability flag is `is_passed` (a départ that has already left, a real hard block). Do not re-add `full`/`closed` gating to show.blade.php's `$departUnavailable`/`unavailable` computation without asking first; if you need bus fullness for something else (e.g. a future "places restantes" indicator), fetch it deliberately rather than reusing this removal as precedent that it's always available.

Internally, `Bus::isFull()`/`isClosed()` are still used by `Depart::getBusesForBooking()` to prefer an open bus when several share a vehicle type — that selection logic is unrelated to and untouched by this UI rule.
