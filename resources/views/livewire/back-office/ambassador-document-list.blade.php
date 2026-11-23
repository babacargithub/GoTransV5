<div class="mx-auto w-full max-w-6xl">
    @vite(['resources/js/markdown-document-editor.js'])

    <div>
        <flux:heading size="xl" level="1">Documents</flux:heading>
        <flux:text class="mt-1">Les documents partagés avec les candidats ambassadeurs par lien à usage unique</flux:text>
    </div>

    <x-flash-toasts />

    <flux:callout class="mt-6" icon="information-circle">
        <flux:callout.text>
            Le contenu s'édite comme dans un traitement de texte. Une modification s'applique à tous les liens pas encore ouverts.
            Le bouton « Générer un lien » crée un lien qui expire dès qu'il est ouvert une fois.
        </flux:callout.text>
    </flux:callout>

    <flux:separator class="my-6" variant="subtle" />

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Titre</flux:table.column>
                <flux:table.column>Dernière modification</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->documents as $document)
                    <flux:table.row wire:key="ambassador-document-{{ $document->id }}">
                        <flux:table.cell variant="strong">{{ $document->type->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $document->title }}</flux:table.cell>
                        <flux:table.cell>{{ $document->updated_at->format('d/m/Y H:i') }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($this->canManageDocuments())
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button size="sm" variant="filled" icon="link" wire:click="openGenerateLink({{ $document->id }})">Générer un lien</flux:button>
                                    <x-back-office.edit-button wire:click="openEditDocument({{ $document->id }})" label="Modifier le document" />
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Modifier un document --}}
    <flux:modal wire:model.self="showDocumentModal" wire:key="ambassador-document-modal" class="w-full max-w-4xl">
        <form wire:submit="saveDocument" class="space-y-6">
            <flux:heading size="lg">Modifier le document</flux:heading>

            <flux:field>
                <flux:label>Titre</flux:label>
                <flux:input wire:model="documentTitle" />
                <flux:error name="documentTitle" />
            </flux:field>

            <flux:field>
                <flux:label>Contenu</flux:label>
                @if ($showDocumentModal)
                    <div
                        wire:key="ambassador-document-editor-{{ $editingDocumentId }}"
                        wire:ignore
                        x-data
                        x-init="mountMarkdownDocumentEditor($refs.editor, $wire.documentContent, (markdown) => { $wire.documentContent = markdown })"
                    >
                        <div x-ref="editor"></div>
                    </div>
                @endif
                <flux:error name="documentContent" />
            </flux:field>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDocumentModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveDocument">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Générer un lien à usage unique --}}
    <flux:modal wire:model.self="showLinkModal" wire:key="ambassador-document-link-modal" class="w-full max-w-xl">
        <div class="space-y-6">
            <flux:heading size="lg">Lien à usage unique</flux:heading>
            <flux:text>{{ $linkDocumentTitle }}. Le lien expire dès qu'il est ouvert une fois, ou au bout de la durée choisie s'il n'est jamais ouvert.</flux:text>

            <form wire:submit="generateLink" class="flex items-end gap-3">
                <flux:field class="flex-1">
                    <flux:label>Valable (jours)</flux:label>
                    <flux:input type="number" min="1" max="90" wire:model="linkDaysBeforeExpiry" />
                    <flux:error name="linkDaysBeforeExpiry" />
                </flux:field>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="generateLink">Générer</flux:button>
            </form>

            @if ($oneTimeUrl !== '')
                <div x-data="{ copied: false }" class="space-y-3">
                    <flux:textarea readonly rows="3" class="font-mono text-xs" x-ref="url">{{ $oneTimeUrl }}</flux:textarea>
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        <flux:callout.text>N'ouvrez pas ce lien vous-même : il serait consommé. Copiez-le et envoyez-le au candidat.</flux:callout.text>
                    </flux:callout>
                    <flux:button
                        icon="clipboard-document"
                        x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                    >
                        <span x-text="copied ? 'Copié !' : 'Copier le lien'"></span>
                    </flux:button>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closeLinkModal">Fermer</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
