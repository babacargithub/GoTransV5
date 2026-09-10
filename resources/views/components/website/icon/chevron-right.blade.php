{{-- Heroicons (outline) chevron-right. Public website icon — plain SVG, no Flux. --}}
@props(['class' => 'h-5 w-5', 'strokeWidth' => 2])

<svg {{ $attributes }} class="{{ $class }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $strokeWidth }}" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
</svg>
