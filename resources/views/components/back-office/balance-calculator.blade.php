{{--
    Balance calculator wrapper shared by the Comptes and Caisses pages.

    Wrap the page content with it and pass `balances` as [id => balance]. Inside, use
    <x-back-office.balance-calculator.toggle />, <x-back-office.balance-calculator.bar />
    and one <x-back-office.balance-calculator.checkbox /> per card. The sum is computed in
    the browser, so it updates instantly without a server round trip.
--}}
@props(['balances'])

<div
    {{ $attributes }}
    x-data="{
        showCalculator: false,
        balances: @js($balances),
        selectedIds: [],
        get total() { return this.selectedIds.reduce((sum, id) => sum + (this.balances[id] ?? 0), 0); },
        formatted(amount) { return new Intl.NumberFormat('fr-FR').format(amount).replace(/[  ]/g, ' '); },
        selectAll() { this.selectedIds = Object.keys(this.balances); },
        toggleCalculator() { this.showCalculator = ! this.showCalculator; if (! this.showCalculator) { this.selectedIds = []; } },
    }"
>
    {{ $slot }}
</div>
