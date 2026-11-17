{{-- Sigma button that shows/hides the balance calculator (see balance-calculator.blade.php). --}}
<flux:tooltip content="Additionner des soldes">
    <flux:button
        icon="sigma"
        aria-label="Additionner des soldes"
        x-on:click="toggleCalculator()"
        x-bind:variant="showCalculator ? 'primary' : 'outline'"
    />
</flux:tooltip>
