@props([
    'status' => session('status'),
    'error' => session('error'),
])

{{--
    Success / error messages as toasts pinned to the bottom-right of the viewport, so they stay
    visible wherever the user is scrolled (notably on mobile). Each toast is keyed by its message
    so Livewire morphing mounts a fresh one (and restarts the auto-dismiss timer) on every new message.
--}}
@if ($status || $error)
    <div class="pointer-events-none fixed inset-x-4 bottom-4 z-[100] flex flex-col items-end gap-2 sm:left-auto sm:w-full sm:max-w-sm">
        @if ($status)
            <div
                wire:key="flash-toast-status-{{ md5($status) }}"
                x-data="{ open: true }"
                x-init="setTimeout(() => open = false, 6000)"
                x-show="open"
                x-transition
                role="status"
                class="pointer-events-auto flex w-full items-start gap-3 rounded-lg bg-green-600 px-4 py-3 text-sm font-medium text-white shadow-lg"
            >
                <flux:icon.check-circle variant="mini" class="mt-0.5 size-5 shrink-0" />
                <span class="flex-1">{{ $status }}</span>
                <button type="button" x-on:click="open = false" aria-label="Fermer" class="shrink-0 opacity-80 hover:opacity-100">
                    <flux:icon.x-mark variant="mini" class="size-5" />
                </button>
            </div>
        @endif

        @if ($error)
            <div
                wire:key="flash-toast-error-{{ md5($error) }}"
                x-data="{ open: true }"
                x-init="setTimeout(() => open = false, 8000)"
                x-show="open"
                x-transition
                role="alert"
                class="pointer-events-auto flex w-full items-start gap-3 rounded-lg bg-red-600 px-4 py-3 text-sm font-medium text-white shadow-lg"
            >
                <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 size-5 shrink-0" />
                <span class="flex-1">{{ $error }}</span>
                <button type="button" x-on:click="open = false" aria-label="Fermer" class="shrink-0 opacity-80 hover:opacity-100">
                    <flux:icon.x-mark variant="mini" class="size-5" />
                </button>
            </div>
        @endif
    </div>
@endif
