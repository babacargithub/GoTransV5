{{--
    Free-text input with type-ahead suggestions of already-known names.

    Typing filters the known names; when nothing matches, a "Créer nouveau" row confirms that the
    typed text will be saved as a new name. The typed text is always the value (any name is allowed).
    Usage: <x-back-office.owner-name-combobox model="path.on.component" :options="$names" />
--}}
@props(['model', 'options' => [], 'placeholder' => 'Nom du propriétaire'])

<div
    x-data="{
        value: $wire.$entangle('{{ $model }}'),
        options: @js(array_values($options)),
        open: false,
        get typedText() { return (this.value ?? '').trim() },
        get matches() {
            const needle = this.typedText.toLowerCase()
            return this.options.filter((name) => name.toLowerCase().includes(needle)).slice(0, 8)
        },
        get isNewName() {
            return this.typedText !== '' && ! this.options.some((name) => name.toLowerCase() === this.typedText.toLowerCase())
        },
        choose(name) { this.value = name; this.open = false },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false"
    class="relative"
>
    <flux:input
        x-model="value"
        x-on:focus="open = true"
        x-on:input="open = true"
        autocomplete="off"
        :placeholder="$placeholder"
    />

    <div
        x-show="open && (matches.length > 0 || isNewName)"
        x-cloak
        class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-zinc-200 bg-white py-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-800"
    >
        <template x-for="name in matches" :key="name">
            <button type="button" x-on:click="choose(name)" x-text="name"
                class="block w-full px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-700"></button>
        </template>

        <button
            type="button"
            x-show="isNewName && matches.length === 0"
            x-on:click="open = false"
            class="block w-full px-3 py-2 text-left text-indigo-600 hover:bg-zinc-100 dark:text-indigo-400 dark:hover:bg-zinc-700"
        >
            Créer nouveau : « <span x-text="typedText"></span> »
        </button>
    </div>
</div>
