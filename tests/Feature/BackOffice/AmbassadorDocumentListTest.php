<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AmbassadorProgramDocumentType;
use App\Enums\PermissionName;
use App\Livewire\BackOffice\AmbassadorDocumentList;
use App\Models\AmbassadorProgramDocument;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class AmbassadorDocumentListTest extends TestCase
{
    use DatabaseTransactions;

    private function createPresentation(): AmbassadorProgramDocument
    {
        return AmbassadorProgramDocument::query()->updateOrCreate(
            ['type' => AmbassadorProgramDocumentType::Presentation],
            ['title' => 'Ancien titre', 'content' => 'Ancien contenu'],
        );
    }

    public function test_editor_lists_documents_and_saves_an_edit(): void
    {
        $presentation = $this->createPresentation();
        $editor = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value, PermissionName::ManageAmbassadorDocuments->value]);

        Livewire::actingAs($editor)
            ->test(AmbassadorDocumentList::class)
            ->assertSee('Présentation du programme')
            ->call('openEditDocument', $presentation->id)
            ->assertSet('documentTitle', 'Ancien titre')
            ->assertSet('documentContent', 'Ancien contenu')
            ->set('documentTitle', 'Nouveau titre')
            ->set('documentContent', '# Nouveau contenu')
            ->call('saveDocument')
            ->assertHasNoErrors()
            ->assertSet('showDocumentModal', false);

        $presentation->refresh();
        $this->assertSame('Nouveau titre', $presentation->title);
        $this->assertSame('# Nouveau contenu', $presentation->content);
    }

    public function test_title_and_content_are_required(): void
    {
        $presentation = $this->createPresentation();
        $editor = $this->createUserWithPermissions([PermissionName::ManageAmbassadorDocuments->value]);

        Livewire::actingAs($editor)
            ->test(AmbassadorDocumentList::class)
            ->call('openEditDocument', $presentation->id)
            ->set('documentTitle', '')
            ->set('documentContent', '')
            ->call('saveDocument')
            ->assertHasErrors(['documentTitle' => 'required', 'documentContent' => 'required']);

        $this->assertSame('Ancien titre', $presentation->fresh()->title);
    }

    public function test_user_without_the_permission_cannot_edit(): void
    {
        $presentation = $this->createPresentation();
        $viewer = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value]);

        Livewire::actingAs($viewer)
            ->test(AmbassadorDocumentList::class)
            ->call('openEditDocument', $presentation->id)
            ->assertSet('showDocumentModal', false)
            ->set('editingDocumentId', $presentation->id)
            ->set('documentTitle', 'Piraté')
            ->set('documentContent', 'Piraté')
            ->call('saveDocument');

        $this->assertSame('Ancien titre', $presentation->fresh()->title);
    }

    public function test_editor_generates_a_working_one_time_link(): void
    {
        $presentation = $this->createPresentation();
        $editor = $this->createUserWithPermissions([PermissionName::ManageAmbassadorDocuments->value]);

        $component = Livewire::actingAs($editor)
            ->test(AmbassadorDocumentList::class)
            ->call('openGenerateLink', $presentation->id)
            ->set('linkDaysBeforeExpiry', 3)
            ->call('generateLink')
            ->assertHasNoErrors();

        $oneTimeUrl = $component->get('oneTimeUrl');
        $this->assertStringContainsString('/programme/presentation/', $oneTimeUrl);

        $this->get($oneTimeUrl)->assertOk()->assertSee('Ancien titre');
    }

    public function test_user_without_the_permission_cannot_generate_a_link(): void
    {
        $presentation = $this->createPresentation();
        $viewer = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value]);

        Livewire::actingAs($viewer)
            ->test(AmbassadorDocumentList::class)
            ->call('openGenerateLink', $presentation->id)
            ->assertSet('showLinkModal', false)
            ->call('generateLink')
            ->assertSet('oneTimeUrl', '');
    }
}
