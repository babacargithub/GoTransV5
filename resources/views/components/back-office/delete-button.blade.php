{{--
    Icon-only "supprimer" button for back-office list rows.

    House rule: delete actions are icon-only and red — Flux's danger variant.
    Callers pass wire:click and a `label` for the accessible name and tooltip.
--}}
@props(['icon' => 'trash', 'label' => 'Supprimer'])

<flux:tooltip :content="$label">
    <flux:button
        variant="danger"
        size="sm"
        :icon="$icon"
        :aria-label="$label"
        {{ $attributes }}
    />
</flux:tooltip>
