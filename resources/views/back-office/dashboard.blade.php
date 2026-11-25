<x-layouts.back-office title="Tableau de bord — Back Office">
    @php
        $formatCount = fn (int $count): string => number_format($count, 0, ',', ' ');
        $formatAmount = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
        $formatPercentage = fn (float $percentage): string => number_format($percentage, 1, ',', ' ').' %';
        $ambassadorShareRings = [
            [
                'label' => 'Clients amenés',
                'count' => $dashboard->newCustomersBroughtByAmbassadorsCount,
                'percentage' => $dashboard->newCustomersBroughtByAmbassadorsPercentage(),
                'caption' => 'des nouveaux clients',
                'color' => '#00a3ff',
            ],
            [
                'label' => 'Réservations amenées',
                'count' => $dashboard->bookingsBroughtByAmbassadorsCount,
                'percentage' => $dashboard->bookingsBroughtByAmbassadorsPercentage(),
                'caption' => 'des réservations',
                'color' => '#3fb950',
            ],
        ];
    @endphp

    {{-- The "dark" class makes the Flux inputs inside the panel render in their dark variant --}}
    <div class="dark mx-auto w-full max-w-6xl space-y-6 rounded-3xl bg-[#1a2332] p-4 text-white sm:p-6">
        <form method="GET" action="{{ route('back-office.dashboard') }}" class="grid gap-4 rounded-3xl bg-[#202b3f] p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-[1fr_1fr_1.4fr_auto] lg:items-end">
            <flux:field>
                <flux:label>Du</flux:label>
                <flux:input type="date" name="date_from" :value="$dashboard->periodStart->toDateString()" class="!rounded-full" />
                <flux:error name="date_from" />
            </flux:field>
            <flux:field>
                <flux:label>Au</flux:label>
                <flux:input type="date" name="date_to" :value="$dashboard->periodEnd->toDateString()" class="!rounded-full" />
                <flux:error name="date_to" />
            </flux:field>
            <flux:field>
                <flux:label>Trajet</flux:label>
                <flux:select name="trajet_id" class="!rounded-full">
                    <flux:select.option value="">Tous les trajets</flux:select.option>
                    @foreach ($trajetOptions as $trajetOption)
                        <flux:select.option :value="$trajetOption->id" :selected="$dashboard->trajetId === $trajetOption->id">{{ $trajetOption->public_name ?: $trajetOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="trajet_id" />
            </flux:field>
            <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-[#00a3ff] px-6 text-sm font-semibold text-white transition hover:bg-[#0b8fe0]">
                <flux:icon.funnel class="size-4" /> Appliquer
            </button>
        </form>

        <div class="grid gap-4 lg:grid-cols-[2fr_1fr_1fr_1fr]">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#00a3ff] to-[#0b7bd6] p-6 sm:p-8">
                <flux:icon.chart-pie class="pointer-events-none absolute -right-6 -top-6 size-44 text-white/15" />
                <h1 class="text-2xl font-bold sm:text-3xl">Tableau de bord</h1>
                <p class="mt-1 text-sm text-white/85">
                    Du {{ $dashboard->periodStart->locale('fr')->translatedFormat('d F Y') }}
                    au {{ $dashboard->periodEnd->locale('fr')->translatedFormat('d F Y') }}
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <div class="rounded-full bg-white/20 px-6 py-3 text-center backdrop-blur">
                        <div class="text-2xl font-bold">{{ $formatCount($dashboard->newCustomersCount) }}</div>
                        <div class="text-xs text-white/90">Nouveaux clients</div>
                    </div>
                    <div class="rounded-full bg-white/20 px-6 py-3 text-center backdrop-blur">
                        <div class="text-2xl font-bold">{{ $formatCount($dashboard->newCustomersBroughtByAmbassadorsCount) }}</div>
                        <div class="text-xs text-white/90">Par les ambassadeurs</div>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl bg-[#4b2c3d] p-6">
                <div class="flex size-14 items-center justify-center rounded-full bg-[#e0607e]"><flux:icon.ticket class="size-7 text-white" /></div>
                <div class="mt-5 text-3xl font-bold">{{ $formatCount($dashboard->bookingsCount) }}</div>
                <div class="mt-3 text-base font-semibold text-white/60">Réservations</div>
            </div>

            <div class="rounded-3xl bg-[#34325f] p-6">
                <div class="flex size-14 items-center justify-center rounded-full bg-[#7c5cdb]"><flux:icon.calendar-days class="size-7 text-white" /></div>
                <div class="mt-5 text-3xl font-bold">{{ $formatCount($dashboard->departsCount) }}</div>
                <div class="mt-3 text-base font-semibold text-white/60">Départs</div>
            </div>

            <div class="rounded-3xl bg-[#1f4a4a] p-6">
                <div class="flex size-14 items-center justify-center rounded-full bg-[#3fb950]"><flux:icon.truck class="size-7 text-white" /></div>
                <div class="mt-5 text-3xl font-bold">{{ $formatCount($dashboard->busesCount) }}</div>
                <div class="mt-3 text-base font-semibold text-white/60">Bus</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-3xl bg-[#202b3f] p-6 lg:col-span-2">
                <h2 class="text-lg font-bold">Apport des ambassadeurs</h2>
                <div class="mt-6 grid gap-6 sm:grid-cols-2">
                    @foreach ($ambassadorShareRings as $ring)
                        <div class="flex items-center gap-5">
                            <div class="relative size-28 shrink-0 rounded-full" style="background: conic-gradient({{ $ring['color'] }} {{ min($ring['percentage'], 100) }}%, #2e3b55 0)">
                                <div class="absolute inset-2.5 flex items-center justify-center rounded-full bg-[#202b3f] text-lg font-bold">
                                    {{ $formatPercentage($ring['percentage']) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-3xl font-bold">{{ $formatCount($ring['count']) }}</div>
                                <div class="mt-1 text-sm font-semibold">{{ $ring['label'] }}</div>
                                <div class="text-xs text-white/55">{{ $ring['caption'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col overflow-hidden rounded-3xl bg-[#202b3f]">
                <div class="px-6 pt-6 text-lg font-bold">Dû aux ambassadeurs</div>
                <div class="grid grid-cols-2 divide-x divide-white/10 py-5">
                    <div class="space-y-1 px-6">
                        <div class="flex items-center gap-2 text-sm text-white/60"><span class="size-2.5 rounded-full bg-[#f0a43c]"></span>En attente</div>
                        <div class="text-lg font-bold">{{ $formatAmount($dashboard->amountDueToAmbassadors->pendingBalance) }}</div>
                    </div>
                    <div class="space-y-1 px-6">
                        <div class="flex items-center gap-2 text-sm text-white/60"><span class="size-2.5 rounded-full bg-[#00a3ff]"></span>Validé</div>
                        <div class="text-lg font-bold">{{ $formatAmount($dashboard->amountDueToAmbassadors->availableBalance) }}</div>
                    </div>
                </div>
                <div class="mt-auto border-t border-white/10 bg-[#00a3ff]/15 px-6 py-5">
                    <div class="text-sm text-white/70">Total dû</div>
                    <div class="text-2xl font-bold text-[#00a3ff]">{{ $formatAmount($dashboard->amountDueToAmbassadors->totalBalance) }}</div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.back-office>
