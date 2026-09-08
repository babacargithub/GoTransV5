<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\EmployeList;
use App\Models\Employe;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createEmploye(): Employe
    {
        return Employe::create([
            'prenom' => 'Test',
            'nom' => 'Employe'.random_int(1000, 9999),
            'tel' => random_int(700000000, 799999999),
            'email' => 'employe'.uniqid().'@example.com',
            'actif' => true,
            'can_sell_ticket' => true,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.employes.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_employes_with_their_details(): void
    {
        $employe = $this->createEmploye();

        Livewire::actingAs(User::factory()->create())
            ->test(EmployeList::class)
            ->assertOk()
            ->assertSee($employe->full_name)
            ->assertSee((string) $employe->tel);
    }

    public function test_creating_an_employe_persists_it(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(EmployeList::class)
            ->call('openCreateEmploye')
            ->assertSet('showEmployeModal', true)
            ->set('employeFirstName', 'Awa')
            ->set('employeLastName', 'Diop'.random_int(1000, 9999))
            ->set('employePhoneNumber', (string) random_int(700000000, 799999999))
            ->set('employeEmail', 'awa'.uniqid().'@example.com')
            ->set('employeCanSellTicket', true)
            ->call('saveEmploye')
            ->assertHasNoErrors()
            ->assertSet('showEmployeModal', false);

        $this->assertDatabaseHas('employes', ['prenom' => 'Awa', 'can_sell_ticket' => true]);
    }

    public function test_creating_requires_the_name_and_phone(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(EmployeList::class)
            ->call('openCreateEmploye')
            ->set('employeFirstName', '')
            ->set('employePhoneNumber', '')
            ->call('saveEmploye')
            ->assertHasErrors(['employeFirstName', 'employePhoneNumber']);
    }

    public function test_editing_an_employe_updates_it(): void
    {
        $employe = $this->createEmploye();

        Livewire::actingAs(User::factory()->create())
            ->test(EmployeList::class)
            ->call('openEditEmploye', $employe->id)
            ->assertSet('editingEmployeId', $employe->id)
            ->set('employeJobTitle', 'Chef de gare')
            ->set('employeCanChooseSeats', true)
            ->call('saveEmploye')
            ->assertHasNoErrors();

        $freshEmploye = $employe->fresh();
        $this->assertSame('Chef de gare', $freshEmploye->poste);
        $this->assertTrue($freshEmploye->can_choose_seats);
    }

    public function test_toggling_active_flips_the_flag(): void
    {
        $employe = $this->createEmploye();

        Livewire::actingAs(User::factory()->create())
            ->test(EmployeList::class)
            ->call('toggleEmployeActive', $employe->id);

        $this->assertFalse($employe->fresh()->actif);
    }

    public function test_deleting_an_employe_removes_it(): void
    {
        $employe = $this->createEmploye();

        Livewire::actingAs(User::factory()->create())
            ->test(EmployeList::class)
            ->call('askToDeleteEmploye', $employe->id)
            ->assertSet('showDeleteEmployeModal', true)
            ->call('confirmDeleteEmploye');

        $this->assertDatabaseMissing('employes', ['id' => $employe->id]);
    }
}
