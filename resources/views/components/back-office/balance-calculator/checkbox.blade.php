{{-- Per-card checkbox of the balance calculator; only visible while the calculator is open. --}}
@props(['id', 'name'])

<input
    type="checkbox"
    value="{{ $id }}"
    x-model="selectedIds"
    x-show="showCalculator"
    x-cloak
    aria-label="Inclure {{ $name }} dans le total"
    class="size-4 shrink-0 rounded border-zinc-300 accent-[var(--color-accent)]"
>
