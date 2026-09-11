---
paths:
  - 'app/Http/Resources/CaravaneDepartsResource.php,app/Http/Resources/MobileTrajetDepartsResource.php,app/Models/Depart.php,app/Http/Controllers/MobileAppController.php,resources/views/website/caravanes/show.blade.php'
---

# Caravanes

## Website caravane page uses CaravaneDepartsResource, not the mobile resource
MobileAppController@listeDepartsTrajet: the `website.*` branch renders website.caravanes.show with `CaravaneDepartsResource` (lean, fully eager-loaded — ~10 queries). The mobile JSON branch still returns `MobileTrajetDepartsResource` (per-bus point-departs/destinations/promo lookups = hundreds of queries; do not point the website at it).

Bus-selection is shared via `Depart::getBusesForBooking(?Collection $availableVehicules = null)` — reads the loaded `buses` collection; pass a shared `Vehicule::all()` when looping many departs so it's fetched once. `Bus::seatsLeft()` short-circuits on an eager-loaded `available_seats_count` (withCount alias) — that's how CaravaneDepartsResource makes `isFull()` query-free.

"Heures de départ" on the caravane page loads lazily: GET website.caravanes.schedule (`caravanes/horaires/{depart}?bus=`) -> MobileAppController@caravaneDepartSchedule, fetched by inline JS in show.blade.php when a <details> opens. Don't reintroduce server-side schedule eager-loading in the blade.
