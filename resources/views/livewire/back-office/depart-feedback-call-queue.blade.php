@php
    $progress = $this->progress;
    $feedback = $this->currentFeedback;
    $outcomeButtons = [
        ['outcome' => \App\Enums\FeedbackCallOutcome::Completed, 'class' => 'bg-emerald-600 text-white'],
        ['outcome' => \App\Enums\FeedbackCallOutcome::NoAnswer, 'class' => 'bg-amber-500 text-white'],
        ['outcome' => \App\Enums\FeedbackCallOutcome::Unreachable, 'class' => 'bg-zinc-500 text-white'],
        ['outcome' => \App\Enums\FeedbackCallOutcome::Refused, 'class' => 'bg-rose-600 text-white'],
    ];
@endphp

<div class="mx-auto w-full max-w-xl space-y-4 pb-28 text-zinc-900 dark:text-zinc-100">
    <div>
        <flux:heading size="xl" level="1">Appels à passer</flux:heading>
        <flux:text class="mt-1">Appelez les clients d'un départ arrivé et notez leur avis</flux:text>
    </div>

    @if ($flashStatusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$flashStatusMessage" />
    @endif

    @if ($flashErrorMessage)
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$flashErrorMessage" />
    @endif

    <flux:select wire:model.live="selectedDepartId" label="Départ" placeholder="Choisir un départ arrivé">
        @foreach ($this->arrivedDeparts as $arrivedDepart)
            <flux:select.option :value="$arrivedDepart->id">
                {{ $arrivedDepart->trajet?->name }} - {{ $arrivedDepart->name }} ({{ $arrivedDepart->total_calls_count - $arrivedDepart->finished_calls_count }} à appeler)
            </flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->arrivedDeparts->isEmpty())
        <flux:callout icon="information-circle" heading="Aucun appel à passer pour le moment." text="Les clients d'un départ apparaissent ici dès qu'il est marqué comme arrivé." />
    @endif

    @if ($selectedDepartId !== null)
        <div>
            <div class="mb-1 flex items-center justify-between text-sm">
                <span class="font-medium">Progression</span>
                <span data-testid="feedback-progress">{{ $progress['finished'] }} / {{ $progress['total'] }}</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ $progress['percentage'] }}%"></div>
            </div>
        </div>

        @if ($feedback !== null)
            <div class="space-y-5 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="feedback-card-{{ $feedback->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-xl font-semibold" data-testid="current-customer-name">{{ $feedback->customer->full_name }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $feedback->depart->trajet?->name }}
                            @if ($feedback->booking?->seat_number)
                                · place {{ $feedback->booking->seat_number }}
                            @endif
                        </div>
                        <div class="mt-1 text-lg font-medium tabular-nums">{{ $feedback->customer->phone_number }}</div>
                    </div>
                </div>

                <a
                    href="tel:+221{{ substr((string) $feedback->customer->phone_number, -9) }}"
                    wire:click="startCall"
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-4 text-lg font-semibold text-white active:bg-emerald-700"
                >
                    <flux:icon.phone class="size-6" /> Appeler
                </a>

                <flux:text class="text-sm">
                    Rappel : annoncez au client que l'appel est enregistré à des fins d'amélioration du service.
                </flux:text>

                <div>
                    <div class="mb-2 text-sm font-medium">Résultat de l'appel</div>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($outcomeButtons as $outcomeButton)
                            <button
                                type="button"
                                wire:click="chooseOutcome('{{ $outcomeButton['outcome']->value }}')"
                                data-testid="outcome-{{ $outcomeButton['outcome']->value }}"
                                @class([
                                    'rounded-xl px-3 py-4 text-base font-semibold',
                                    $outcomeButton['class'],
                                    'opacity-40' => $feedback->outcome !== null && $feedback->outcome !== $outcomeButton['outcome'],
                                    'ring-4 ring-offset-2 ring-emerald-300' => $feedback->outcome === $outcomeButton['outcome'],
                                ])
                            >
                                {{ $outcomeButton['outcome']->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($feedback->outcome === null)
                    <button
                        type="button"
                        wire:click="skipToNextCard"
                        wire:loading.attr="disabled"
                        wire:target="skipToNextCard"
                        data-testid="skip-to-next"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-300 px-4 py-3 text-base font-semibold text-zinc-700 active:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:active:bg-zinc-800"
                    >
                        Au suivant <flux:icon.arrow-right class="size-5" />
                    </button>
                @endif

                @if ($feedback->outcome === \App\Enums\FeedbackCallOutcome::Completed)
                    <div class="space-y-4">
                        @foreach ($this->criteria as $criterion)
                            <div
                                wire:key="criterion-{{ $criterion->id }}"
                                x-data="{
                                    grade: {{ $scores[$criterion->id] ?? 'null' }},
                                    savedGrade: {{ $scores[$criterion->id] ?? 'null' }},
                                    save() {
                                        if (this.grade === null || this.grade === this.savedGrade) { return; }
                                        this.savedGrade = this.grade;
                                        $wire.setScore({{ $criterion->id }}, this.grade);
                                    },
                                }"
                            >
                                <div class="mb-2 flex items-baseline justify-between">
                                    <div class="text-sm font-medium">{{ $criterion->label }}</div>
                                    <div class="tabular-nums" data-testid="grade-{{ $criterion->id }}">
                                        <span class="text-2xl font-bold" x-text="grade ?? '—'"></span>
                                        <span class="text-sm text-zinc-500 dark:text-zinc-400">/ 20</span>
                                    </div>
                                </div>
                                <input
                                    type="range"
                                    min="{{ \App\Models\DepartFeedbackRating::MINIMUM_SCORE }}"
                                    max="{{ \App\Models\DepartFeedbackRating::MAXIMUM_SCORE }}"
                                    step="1"
                                    :value="grade ?? 10"
                                    x-on:input="grade = Number($event.target.value)"
                                    x-on:change="save()"
                                    x-on:pointerup="grade = Number($event.target.value); save()"
                                    data-testid="slider-{{ $criterion->id }}"
                                    class="h-8 w-full cursor-pointer accent-indigo-600"
                                    :class="grade === null && 'opacity-50'"
                                />
                            </div>
                        @endforeach
                        <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400"><span>1 = très mauvais</span><span>Glissez le curseur</span><span>20 = excellent</span></div>

                        <flux:textarea wire:model="comment" label="Commentaire (facultatif)" rows="3" />

                        <div class="space-y-2">
                            <div class="text-sm font-medium">Enregistrement de l'appel (facultatif)</div>
                            @if ($feedback->hasRecording())
                                <flux:callout variant="success" icon="check-circle" heading="Enregistrement envoyé" />
                            @else
                                <flux:text class="text-sm text-amber-600 dark:text-amber-400">Enregistrement manquant.</flux:text>
                            @endif
                            <input
                                type="file"
                                wire:model="recordingUpload"
                                accept="audio/*"
                                class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:font-medium dark:file:bg-zinc-800"
                            />
                            <div wire:loading wire:target="recordingUpload" class="text-sm text-zinc-500 dark:text-zinc-400">Envoi en cours...</div>
                            @error('recordingUpload')
                                <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    </div>
                @endif
            </div>

            @if ($feedback->outcome === \App\Enums\FeedbackCallOutcome::Completed)
                <div class="fixed inset-x-0 bottom-0 z-20 border-t border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mx-auto max-w-xl">
                        <flux:button
                            variant="primary"
                            class="w-full"
                            size="base"
                            wire:click="saveAndNext"
                            wire:loading.attr="disabled"
                            wire:target="saveAndNext,recordingUpload"
                            :disabled="! $this->allCriteriaRated()"
                            data-testid="save-and-next"
                        >
                            Enregistrer et suivant
                        </flux:button>
                    </div>
                </div>
            @endif
        @else
            <flux:callout icon="check-badge" variant="success" heading="Plus personne à appeler pour le moment." text="Consultez l'onglet « À rappeler » pour les clients qui n'ont pas répondu." />
        @endif

        <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 text-sm dark:bg-zinc-800">
            @foreach ([\App\Livewire\BackOffice\DepartFeedbackCallQueue::TAB_TO_CALL => ['À appeler', 'toCall'], \App\Livewire\BackOffice\DepartFeedbackCallQueue::TAB_TO_CALL_BACK => ['À rappeler', 'toCallBack'], \App\Livewire\BackOffice\DepartFeedbackCallQueue::TAB_FINISHED => ['Terminés', 'finished']] as $tabKey => [$tabLabel, $countKey])
                <button
                    type="button"
                    wire:click="selectTab('{{ $tabKey }}')"
                    @class([
                        'flex-1 rounded-md px-2 py-2 font-medium',
                        'bg-white shadow-sm dark:bg-zinc-700 dark:text-white' => $activeTab === $tabKey,
                    ])
                >
                    {{ $tabLabel }} ({{ $this->tabCounts[$countKey] }})
                </button>
            @endforeach
        </div>

        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->listedCards as $listedCard)
                <div class="flex items-center justify-between gap-3 p-3" wire:key="listed-card-{{ $listedCard->id }}">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $listedCard->customer->full_name }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $listedCard->customer->phone_number }}
                            @if ($listedCard->outcome)
                                · {{ $listedCard->outcome->label() }}
                            @endif
                            @if ($listedCard->isFinished() && $listedCard->averageScore() !== null)
                                · {{ $listedCard->averageScore() }}/20
                            @endif
                        </div>
                    </div>
                    @unless ($listedCard->isFinished())
                        <flux:button size="sm" wire:click="openCard({{ $listedCard->id }})">Appeler</flux:button>
                    @endunless
                </div>
            @empty
                <div class="p-4 text-sm text-zinc-500 dark:text-zinc-400">Aucun client dans cette liste.</div>
            @endforelse
        </div>
    @endif
</div>
