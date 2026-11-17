<div class="mx-auto w-full max-w-5xl">
    <flux:heading size="xl" level="1">Revenus</flux:heading>
    <flux:text class="mt-1">Revenus, dépenses et résultat net sur la période</flux:text>

    @include('livewire.back-office.partials.profit-report')

    <div class="mt-6">
        <livewire:back-office.fixed-cost-coverage-card />
    </div>
</div>
