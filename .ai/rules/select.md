---
paths:
  - 'resources/views/flux/select/**'
---

# Select

## Flux select placeholder override: placeholder <option> is not disabled
resources/views/flux/select/variants/default.blade.php is a local override of the Flux stub. The only change: the placeholder `<option>` drops `disabled`.

Why: with `wire:model` bound to a null property, Livewire's updateSelect() deselects every option, and the browser then falls back to the first *enabled* option. With a disabled placeholder that is the first real item, so every dropdown (trajet, horaire, véhicule, itinéraire…) looked pre-selected while the component value stayed null and "required" fired on submit.

When bumping livewire/flux, re-diff this file against the new stub (`php artisan flux:publish select`) and re-apply the one-line change. Keep only variants/default.blade.php published — delete the other generated files.
