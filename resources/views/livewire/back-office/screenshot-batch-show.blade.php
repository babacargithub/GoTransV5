<div class="mx-auto w-full max-w-4xl">
    <flux:button variant="ghost" icon="arrow-left" :href="route('back-office.screenshot-batches.index')" wire:navigate>Mes envois</flux:button>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ $screenshotBatch->note ?? 'Sans nom' }}</flux:heading>
            <flux:text class="mt-1">Envoi du {{ $screenshotBatch->created_at->locale('fr')->translatedFormat('d F Y à H:i') }} · {{ $screenshotBatch->file_count }} image(s)</flux:text>
        </div>
        <flux:badge :color="$screenshotBatch->status->badgeColor()">{{ $screenshotBatch->status->label() }}</flux:badge>
    </div>

    <flux:separator class="my-6" variant="subtle" />

    @if ($imageFileNames === [])
        <flux:text>Ce lot ne contient aucune image.</flux:text>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            @foreach ($imageFileNames as $imageFileName)
                @php($imageUrl = route('back-office.screenshot-batches.image', [$screenshotBatch->uuid, $imageFileName]))
                <a href="{{ $imageUrl }}" target="_blank" rel="noopener" wire:key="screenshot-{{ $imageFileName }}">
                    <img src="{{ $imageUrl }}" alt="Capture d'écran" loading="lazy" class="aspect-[9/16] w-full rounded-lg border border-zinc-200 object-cover dark:border-zinc-700">
                </a>
            @endforeach
        </div>
    @endif
</div>
