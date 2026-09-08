{{--
    Icon-only "modifier" button for back-office list rows.

    House rule: edit actions are icon-only and indigo. The indigo tint reuses the
    same --color-accent override the départ list already uses for its indigo
    primary buttons. Callers pass wire:click / :href and a `label` for the
    accessible name and tooltip.
--}}
@props(['icon' => 'pencil-square', 'label' => 'Modifier'])

<flux:tooltip :content="$label">
    <flux:button
        variant="primary"
        size="sm"
        :icon="$icon"
        :aria-label="$label"
        class="[--color-accent:var(--color-indigo-700)] [--color-accent-foreground:var(--color-white)] dark:[--color-accent:var(--color-indigo-600)]"
        {{ $attributes }}
    />
</flux:tooltip>
