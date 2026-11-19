<div class="w-full space-y-6">
    <div>
        <flux:heading size="xl" level="1">Mes gains</flux:heading>
        <flux:text class="mt-1">
            L'argent que vous gagnez grâce à vos clients. « En attente » : le client n'a pas encore voyagé.
            « Disponible » : vous pouvez être payé le 15 ou le dernier jour du mois.
        </flux:text>
    </div>

    <flux:select wire:model.live="statusFilter" aria-label="Filtrer mes gains">
        <flux:select.option value="">Tous mes gains</flux:select.option>
        @foreach (\App\Enums\AmbassadorEarningStatus::cases() as $earningStatus)
            <flux:select.option value="{{ $earningStatus->value }}">{{ $earningStatus->labelForAmbassador() }}</flux:select.option>
        @endforeach
    </flux:select>

    <div class="space-y-3">
        @forelse ($this->earnings as $earning)
            <div wire:key="earning-{{ $earning->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold">{{ $earning->type->label() }}</div>
                        @if ($earning->label)
                            <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ $earning->label }}</div>
                        @endif
                    </div>
                    <flux:badge :color="$earning->status->badgeColor()">{{ $earning->status->labelForAmbassador() }}</flux:badge>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <div class="text-zinc-500">Votre gain</div>
                        <div class="font-medium">{{ number_format($earning->amount, 0, ',', ' ') }} FCFA</div>
                    </div>
                    @if ($earning->status !== \App\Enums\AmbassadorEarningStatus::Cancelled)
                        <div>
                            <div class="text-zinc-500">
                                @if ($earning->approved_at)
                                    Disponible depuis le
                                @else
                                    Disponible à partir du
                                @endif
                            </div>
                            <div class="font-medium">{{ ($earning->approved_at ?? $earning->approvable_at)?->format('d/m/Y H:i') ?? '—' }}</div>
                        </div>
                    @else
                        <div class="font-medium">Reversé</div>
                    @endif
                </div>
            </div>
        @empty
            <flux:text>Aucun gain pour le moment.</flux:text>
        @endforelse

        {{ $this->earnings->links() }}
    </div>
</div>
