{{--
    Card shared by the back-office finance pages (Comptes, Caisses).

    Tinted header band (optional `selector` slot, logo or icon, name, `status` slot), optional
    `subtitle` slot, the balance, then compact action buttons (`actions` slot).
--}}
@props(['name', 'balance', 'logoUrl' => null, 'icon' => null])

@php
    if ($logoUrl === null && str_contains(mb_strtolower($name), 'cbao')) {
        $logoUrl = asset('images/banks/cbao.png');
    }
@endphp

<flux:card {{ $attributes->class('flex flex-col gap-3') }}>
    <div class="-mx-6 -mt-6 flex items-start justify-between gap-2 rounded-t-[inherit] border-b border-zinc-200 bg-zinc-100 px-6 py-3 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex min-w-0 items-center gap-2">
            {{ $selector ?? '' }}
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="" class="size-7 shrink-0 rounded-full object-contain">
            @elseif ($icon)
                <flux:icon :name="$icon" class="size-7 shrink-0 text-green-600" />
            @endif
            <flux:heading size="lg" class="min-w-0 break-words !font-bold">{{ $name }}</flux:heading>
        </div>
        {{ $status ?? '' }}
    </div>

    @isset($subtitle)
        <div class="flex flex-wrap gap-1">{{ $subtitle }}</div>
    @endisset

    <flux:heading size="xl">{{ number_format($balance, 0, ',', ' ') }} FCFA</flux:heading>

    <div class="mt-auto flex flex-wrap items-center justify-end gap-1 border-t border-zinc-200 pt-3 dark:border-zinc-700 [&_[data-flux-button]]:!size-[1.6rem] [&_[data-flux-button]_svg]:!size-[0.8rem]">
        {{ $actions }}
    </div>
</flux:card>
