<?php

namespace App\Livewire\BackOffice;

use App\Enums\AmbassadorProgramDocumentType;
use App\Enums\PermissionName;
use App\Models\AmbassadorProgramDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Back office "Documents": the markdown documents (presentation, contract) shared with ambassador candidates
 * through one-time links. The presentation is edited in place; editing the contract creates a new version for the
 * ambassadors who have not signed yet, the signed versions staying untouched.
 */
#[Layout('components.layouts.back-office')]
class AmbassadorDocumentList extends Component
{
    public bool $showDocumentModal = false;

    public ?int $editingDocumentId = null;

    public string $documentTitle = '';

    public string $documentContent = '';

    public bool $showLinkModal = false;

    public string $linkDocumentTitle = '';

    public int $linkDaysBeforeExpiry = 7;

    public string $oneTimeUrl = '';

    #[Locked]
    public ?int $linkDocumentId = null;

    /**
     * @return Collection<int, AmbassadorProgramDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        return AmbassadorProgramDocument::query()->currentVersions()->orderBy('id')->get();
    }

    public function canManageDocuments(): bool
    {
        return PermissionName::ManageAmbassadorDocuments->allowedForCurrentUser();
    }

    public function openEditDocument(int $documentId): void
    {
        if (! $this->allowedToManageDocuments()) {
            return;
        }

        $document = AmbassadorProgramDocument::findOrFail($documentId);

        $this->resetDocumentForm();
        $this->editingDocumentId = $document->id;
        $this->documentTitle = $document->title;
        $this->documentContent = $document->content;
        $this->showDocumentModal = true;
    }

    public function closeDocumentModal(): void
    {
        $this->showDocumentModal = false;
        $this->resetDocumentForm();
    }

    public function saveDocument(): void
    {
        if (! $this->allowedToManageDocuments()) {
            return;
        }

        $validated = $this->validate([
            'documentTitle' => ['required', 'string', 'max:255'],
            'documentContent' => ['required', 'string', 'max:200000'],
        ], attributes: [
            'documentTitle' => 'titre',
            'documentContent' => 'contenu',
        ]);

        $editedDocument = AmbassadorProgramDocument::findOrFail($this->editingDocumentId);

        if ($editedDocument->type === AmbassadorProgramDocumentType::Contract) {
            $this->saveContractAsNewVersion($editedDocument, $validated['documentTitle'], $validated['documentContent']);
        } else {
            $editedDocument->update([
                'title' => $validated['documentTitle'],
                'content' => $validated['documentContent'],
            ]);

            session()->flash('status', 'Le document a été modifié.');
        }

        $this->closeDocumentModal();
    }

    public function openGenerateLink(int $documentId): void
    {
        if (! $this->allowedToManageDocuments()) {
            return;
        }

        $document = AmbassadorProgramDocument::findOrFail($documentId);

        $this->linkDocumentId = $document->id;
        $this->linkDocumentTitle = $document->title;
        $this->linkDaysBeforeExpiry = 7;
        $this->oneTimeUrl = '';
        $this->showLinkModal = true;
    }

    public function generateLink(): void
    {
        if (! $this->allowedToManageDocuments()) {
            return;
        }

        $this->validate([
            'linkDaysBeforeExpiry' => ['required', 'integer', 'min:1', 'max:90'],
        ], attributes: [
            'linkDaysBeforeExpiry' => 'durée de validité',
        ]);

        $this->oneTimeUrl = AmbassadorProgramDocument::findOrFail($this->linkDocumentId)
            ->type
            ->createOneTimeUrl($this->linkDaysBeforeExpiry);
    }

    public function closeLinkModal(): void
    {
        $this->showLinkModal = false;
        $this->reset(['linkDocumentId', 'linkDocumentTitle', 'oneTimeUrl']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.back-office.ambassador-document-list')->title('Documents ambassadeurs — Back Office');
    }

    private function allowedToManageDocuments(): bool
    {
        if ($this->canManageDocuments()) {
            return true;
        }

        session()->flash('error', PermissionName::ManageAmbassadorDocuments->unauthorizedMessage());

        return false;
    }

    /**
     * Stores the edited contract as the next version, unless nothing changed. Ambassadors who already signed keep the version they signed.
     */
    private function saveContractAsNewVersion(AmbassadorProgramDocument $currentContract, string $title, string $content): void
    {
        if ($title === $currentContract->title && $content === $currentContract->content) {
            session()->flash('status', 'Aucune modification : la version du contrat reste inchangée.');

            return;
        }

        $newVersion = AmbassadorProgramDocument::query()->create([
            'type' => AmbassadorProgramDocumentType::Contract,
            'version' => $currentContract->version + 1,
            'title' => $title,
            'content' => $content,
        ]);

        session()->flash('status', "Version {$newVersion->version} du contrat enregistrée : elle sera proposée aux ambassadeurs qui n'ont pas encore signé. Les contrats déjà signés ne changent pas.");
    }

    private function resetDocumentForm(): void
    {
        $this->reset(['editingDocumentId', 'documentTitle', 'documentContent']);
        $this->resetValidation();
    }
}
