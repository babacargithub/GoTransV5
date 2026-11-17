<div class="w-full space-y-6">
    <flux:heading size="xl" level="1">Mes paiements</flux:heading>

    <div class="space-y-3">
        @forelse ($this->payouts as $payout)
            <div wire:key="payout-{{ $payout->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-3">
                    <div class="text-lg font-semibold">{{ number_format($payout->amount, 0, ',', ' ') }} FCFA</div>
                    <flux:badge :color="$payout->status->badgeColor()">{{ $payout->status->label() }}</flux:badge>
                </div>
                <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <div class="text-zinc-500">Demandé le</div>
                        <div class="font-medium">{{ $payout->created_at->format('d/m/Y H:i') }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Payé le</div>
                        <div class="font-medium">{{ $payout->paid_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    </div>
                </div>
                @if ($payout->status === \App\Enums\AmbassadorPayoutStatus::Failed)
                    <flux:text class="mt-3 text-sm">Ce paiement n'a pas abouti. Il sera renvoyé, vous n'avez rien à faire.</flux:text>
                @endif
            </div>
        @empty
            <flux:text>Aucun paiement pour le moment.</flux:text>
        @endforelse

        {{ $this->payouts->links() }}
    </div>
</div>
