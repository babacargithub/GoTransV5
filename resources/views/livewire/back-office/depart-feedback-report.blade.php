@php
    $summary = $this->callSummary;
    $canListenToRecordings = \App\Enums\PermissionName::ListenDepartFeedbackRecordings->allowedForCurrentUser();
@endphp

<div class="mx-auto w-full max-w-5xl space-y-6 text-zinc-900 dark:text-zinc-100">
    <div>
        <flux:heading size="xl" level="1">Avis clients</flux:heading>
        <flux:text class="mt-1">Ce que les clients pensent de leur voyage, départ par départ</flux:text>
    </div>

    <flux:select wire:model.live="selectedDepartId" label="Départ" placeholder="Choisir un départ">
        @foreach ($this->reportableDeparts as $reportableDepart)
            <flux:select.option :value="$reportableDepart->id">
                {{ $reportableDepart->trajet?->name }} - {{ $reportableDepart->name }}
            </flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->reportableDeparts->isEmpty())
        <flux:callout icon="information-circle" heading="Aucun avis pour le moment." text="Marquez un départ comme arrivé pour lancer les appels clients." />
    @elseif ($selectedDepartId !== null)
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ([
                ['Clients à appeler', $summary['totalCalls']],
                ['Ont répondu', $summary['answered']],
                ['Taux de réponse', $summary['responseRate'].' %'],
                ['Pas encore joints', $summary['noAnswer'] + $summary['unreachable'] + $summary['notCalled']],
            ] as [$cardLabel, $cardValue])
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">{{ $cardLabel }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $cardValue }}</div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">Note moyenne par critère</flux:heading>
            <div class="mt-3 space-y-3">
                @forelse ($this->criterionAverages as $criterionAverage)
                    <div>
                        <div class="mb-1 flex justify-between text-sm">
                            <span>{{ $criterionAverage['label'] }}</span>
                            <span class="font-semibold">{{ number_format($criterionAverage['average'], 1, ',', ' ') }} / 20 <span class="font-normal text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">({{ $criterionAverage['ratingsCount'] }})</span></span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                            <div class="h-full rounded-full {{ $criterionAverage['average'] >= 14 ? 'bg-emerald-500' : ($criterionAverage['average'] >= 10 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ $criterionAverage['average'] / 20 * 100 }}%"></div>
                        </div>
                    </div>
                @empty
                    <flux:text>Aucune note enregistrée pour ce départ.</flux:text>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700"><flux:heading size="lg">Appels</flux:heading></div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->calls as $call)
                    <div class="space-y-2 p-4" wire:key="call-{{ $call->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <span class="font-medium">{{ $call->customer->full_name }}</span>
                                <span class="text-sm text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">· {{ $call->customer->phone_number }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-sm">
                                <flux:badge size="sm">{{ $call->outcome->label() }}</flux:badge>
                                @if ($call->averageScore() !== null)
                                    <span class="font-semibold">{{ number_format($call->averageScore(), 1, ',', ' ') }} / 20</span>
                                @endif
                            </div>
                        </div>
                        @if ($call->ratings->isNotEmpty())
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                                @foreach ($call->ratings->sortBy('criterion.position') as $rating)
                                    <span>{{ $rating->criterion->label }} : <strong>{{ $rating->score }}</strong></span>
                                @endforeach
                            </div>
                        @endif
                        @if ($call->comment)
                            <flux:text class="italic">« {{ $call->comment }} »</flux:text>
                        @endif
                        @if ($call->hasRecording() && $canListenToRecordings)
                            <audio controls preload="none" class="w-full" src="{{ route('back-office.depart-feedbacks.recording', $call) }}"></audio>
                        @endif
                        <div class="text-xs text-zinc-400 dark:text-zinc-500">
                            @if ($call->claimedBy) Agent : {{ $call->claimedBy->name }} · @endif
                            {{ ($call->completed_at ?? $call->called_at)?->translatedFormat('d M Y à H:i') }}
                        </div>
                    </div>
                @empty
                    <div class="p-4 text-sm text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">Aucun appel passé pour ce départ.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">Que faut-il améliorer ?</flux:heading>
            @if ($flashStatusMessage)
                <flux:callout class="mt-3" variant="success" icon="check-circle" :heading="$flashStatusMessage" />
            @endif
            <flux:textarea wire:model="conclusion" class="mt-3" rows="5" placeholder="Conclusion écrite pour ce départ" />
            <div class="mt-3 flex justify-end">
                <flux:button variant="primary" wire:click="saveConclusion">Enregistrer la conclusion</flux:button>
            </div>
        </div>
    @endif
</div>
