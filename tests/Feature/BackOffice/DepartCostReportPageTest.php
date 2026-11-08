<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Enums\PermissionName;
use App\Livewire\BackOffice\DepartCostReportPage;
use App\Models\Account;
use App\Models\Bus;
use App\Models\Caisse;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DepartCostReportPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The dev database holds dozens of upcoming départs; keep every test's départs on one page.
        config(['app.back_office_departs_per_page' => 1000]);
    }

    private function trajetWithStops(): Trajet
    {
        return Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();
    }

    private function createDepartWithBuses(int $numberOfBuses = 1): Depart
    {
        $depart = Depart::create([
            'name' => 'DEPART COST REPORT '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $this->trajetWithStops()->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        foreach (range(1, $numberOfBuses) as $busNumber) {
            $depart->buses()->create([
                'name' => "Bus Cost Report {$busNumber}",
                'nombre_place' => 57,
                'ticket_price' => 3550,
                'gp_ticket_price' => 6000,
                'closed' => false,
            ]);
        }

        return $depart->fresh();
    }

    private function sellTicketOnBus(Bus $bus, int $ticketPrice): void
    {
        $trajet = $this->trajetWithStops();
        $customer = Customer::create([
            'prenom' => 'awa',
            'nom' => 'ndiaye',
            'phone_number' => 770000000 + random_int(1, 9999999),
        ]);

        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => $ticketPrice,
            'payment_method' => 'wave',
            'used' => true,
            'soldBy' => 'agence test',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        $bus->bookings()->create([
            'customer_id' => $customer->id,
            'depart_id' => $bus->depart_id,
            'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $trajet->destinations()->firstOrFail()->id,
            'seat_id' => $bus->seats()->create([
                'seat_id' => Seat::query()->inRandomOrder()->firstOrFail()->id,
                'booked' => true,
                'price' => $ticketPrice,
            ])->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ]);
    }

    private function createFundedCaisseAndAccount(int $balance, string $name): array
    {
        $caisse = Caisse::create(['name' => "Caisse {$name} ".uniqid(), 'balance' => 0]);
        $account = Account::create(['name' => "Compte {$name} ".uniqid(), 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        app(AccountService::class)->processEntreeDeCaisse($caisse, $account, $balance, 'Fonds de test');

        return [$caisse->fresh(), $account->fresh()];
    }

    /**
     * @return array{0: Depart, 1: Bus, 2: Bus, 3: DepartCostReport}
     */
    private function createSavedCostReportWithTwoRentedBuses(): array
    {
        $depart = $this->createDepartWithBuses(2);
        [$firstBus, $secondBus] = $depart->buses->all();
        $costReport = $depart->costReport()->create();
        $costReport->busRentals()->create(['bus_id' => $firstBus->id, 'bus_owner_name' => 'Mamadou Sow', 'rental_fee' => 200000]);
        $costReport->busRentals()->create(['bus_id' => $secondBus->id, 'bus_owner_name' => 'Ibrahima Fall', 'rental_fee' => 100000]);

        return [$depart, $firstBus, $secondBus, $costReport];
    }

    public function test_depart_list_offers_to_record_the_cost_report_until_it_is_saved(): void
    {
        $depart = $this->createDepartWithBuses();
        $user = $this->createUserWithFullAccess();

        $this->actingAs($user)
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSee(route('back-office.departs.cost-report', $depart->id), false)
            ->assertSee('Enregistrer le bilan');

        $depart->costReport()->create();

        $this->actingAs($user)
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSee('Voir le bilan');
    }

    public function test_a_depart_without_cost_report_opens_on_the_form_with_computed_ticket_sales(): void
    {
        $depart = $this->createDepartWithBuses(2);
        [$firstBus, $secondBus] = $depart->buses->all();
        $this->sellTicketOnBus($firstBus, 3550);
        $this->sellTicketOnBus($firstBus, 3550);
        $this->sellTicketOnBus($secondBus, 6000);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertSet('isEditing', true)
            ->assertSet('busRentalRows', [
                $firstBus->id => ['busOwnerName' => '', 'rentalFee' => ''],
                $secondBus->id => ['busOwnerName' => '', 'rentalFee' => ''],
            ])
            ->assertSet('totalTicketSales', 13100);
    }

    public function test_the_form_of_a_single_bus_depart_hides_the_bus_card_title_and_suggests_known_owners(): void
    {
        [$otherDepart] = $this->createSavedCostReportWithTwoRentedBuses();
        $depart = $this->createDepartWithBuses();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertDontSee('Bus Cost Report 1')
            ->assertSee('Montant de la location')
            ->assertSee('Mamadou Sow')
            ->assertSee('Créer nouveau');

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $otherDepart])
            ->call('startEditing')
            ->assertSee('Bus Cost Report 1');
    }

    public function test_ticket_sales_and_profit_are_hidden_from_users_without_full_access(): void
    {
        [$depart] = $this->createSavedCostReportWithTwoRentedBuses();

        $usersWithoutFullAccess = $this->createUserWithPermissions([PermissionName::ManageDepartCostReport->value]);

        $unsavedDepart = $this->createDepartWithBuses();

        Livewire::actingAs($usersWithoutFullAccess)
            ->test(DepartCostReportPage::class, ['depart' => $unsavedDepart])
            ->assertSee('Location des bus')
            ->assertDontSee('Billets vendus')
            ->assertDontSee('Bénéfice');

        Livewire::actingAs($usersWithoutFullAccess)
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertSee('Location des bus')
            ->assertDontSee('Billets vendus')
            ->assertDontSee('Bénéfice');

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertSee('Billets vendus')
            ->assertSee('Bénéfice');
    }

    public function test_it_saves_the_cost_report_with_one_rental_line_per_bus(): void
    {
        $depart = $this->createDepartWithBuses(2);
        [$firstBus, $secondBus] = $depart->buses->all();
        $user = $this->createUserWithFullAccess();

        Livewire::actingAs($user)
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->set("busRentalRows.{$firstBus->id}.busOwnerName", 'Mamadou Sow')
            ->set("busRentalRows.{$firstBus->id}.rentalFee", '250000')
            ->set("busRentalRows.{$secondBus->id}.busOwnerName", 'Ibrahima Fall')
            ->set("busRentalRows.{$secondBus->id}.rentalFee", '300000')
            ->set('notes', 'Retard de 30 minutes au départ.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('isEditing', false)
            ->assertSee('Mamadou Sow')
            ->assertSee('Retard de 30 minutes au départ.');

        $savedCostReport = DepartCostReport::query()->where('depart_id', $depart->id)->firstOrFail();
        $this->assertSame('Retard de 30 minutes au départ.', $savedCostReport->notes);
        $this->assertSame($user->id, $savedCostReport->created_by);
        $this->assertSame(550000, $savedCostReport->fresh()->totalRentalFees());
        $this->assertSame(
            'Ibrahima Fall',
            $savedCostReport->busRentals()->where('bus_id', $secondBus->id)->firstOrFail()->bus_owner_name,
        );
    }

    public function test_saving_requires_an_owner_and_a_valid_fee_for_every_bus(): void
    {
        $depart = $this->createDepartWithBuses();
        $bus = $depart->buses->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->set("busRentalRows.{$bus->id}.rentalFee", '-5')
            ->call('save')
            ->assertHasErrors(["busRentalRows.{$bus->id}.busOwnerName", "busRentalRows.{$bus->id}.rentalFee"]);

        $this->assertNull($depart->costReport()->first());
    }

    public function test_modifier_edits_the_saved_cost_report_without_creating_a_second_one(): void
    {
        $depart = $this->createDepartWithBuses();
        $bus = $depart->buses->firstOrFail();
        $costReport = $depart->costReport()->create(['notes' => 'Avant']);
        $costReport->busRentals()->create(['bus_id' => $bus->id, 'bus_owner_name' => 'Ancien Propriétaire', 'rental_fee' => 100000]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertSet('isEditing', false)
            ->assertSee('Modifier')
            ->call('startEditing')
            ->assertSet('isEditing', true)
            ->assertSet("busRentalRows.{$bus->id}.busOwnerName", 'Ancien Propriétaire')
            ->assertSet("busRentalRows.{$bus->id}.rentalFee", '100000')
            ->set("busRentalRows.{$bus->id}.busOwnerName", 'Nouveau Propriétaire')
            ->set("busRentalRows.{$bus->id}.rentalFee", '120000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('isEditing', false);

        $this->assertSame(1, DepartCostReport::query()->where('depart_id', $depart->id)->count());
        $rental = $costReport->busRentals()->firstOrFail();
        $this->assertSame('Nouveau Propriétaire', $rental->bus_owner_name);
        $this->assertSame(120000, $rental->rental_fee);
    }

    public function test_a_user_without_any_special_permission_can_record_and_edit_the_cost_report(): void
    {
        $depart = $this->createDepartWithBuses();
        $bus = $depart->buses->firstOrFail();
        $userWithoutSpecialPermission = $this->createUserWithPermissions([PermissionName::CreateDeparts->value]);

        $component = Livewire::actingAs($userWithoutSpecialPermission)
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertSet('isEditing', true)
            ->assertDontSee("n'a pas encore été enregistré")
            ->set("busRentalRows.{$bus->id}.busOwnerName", 'Moussa')
            ->set("busRentalRows.{$bus->id}.rentalFee", '90000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('isEditing', false)
            ->assertSee('Modifier');

        $this->assertSame(90000, $depart->costReport()->first()->busRentals()->first()->rental_fee);

        $component->call('startEditing')
            ->assertSet('isEditing', true)
            ->set("busRentalRows.{$bus->id}.rentalFee", '95000')
            ->call('save')
            ->assertSet('isEditing', false);

        $this->assertSame(95000, $depart->costReport()->first()->busRentals()->first()->rental_fee);
    }

    public function test_the_cash_out_button_is_only_visible_to_full_access_users_once_the_cost_report_is_saved(): void
    {
        [$depart] = $this->createSavedCostReportWithTwoRentedBuses();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertSee('wire:click="openCashOutModal"', false);

        Livewire::actingAs($this->createUserWithPermissions([PermissionName::ManageDepartCostReport->value]))
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->assertDontSee('openCashOutModal', false);

        $unsavedCostReportDepart = $this->createDepartWithBuses();
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $unsavedCostReportDepart])
            ->assertDontSee('openCashOutModal', false);
    }

    public function test_cash_out_is_refused_to_users_without_full_access(): void
    {
        [$depart, $firstBus, , $costReport] = $this->createSavedCostReportWithTwoRentedBuses();
        [$caisse, $account] = $this->createFundedCaisseAndAccount(500000, 'Refus');

        Livewire::actingAs($this->createUserWithPermissions([PermissionName::ManageDepartCostReport->value, PermissionName::ManageCaisse->value]))
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('openCashOutModal')
            ->assertSet('showCashOutModal', false)
            ->set("paidAmountByBusId.{$firstBus->id}", '100000')
            ->set('cashOutCaisseRows', [['caisseId' => (string) $caisse->id, 'amount' => '100000']])
            ->set('cashOutAccountRows', [['accountId' => (string) $account->id, 'amount' => '100000']])
            ->set('cashOutLabel', 'Location de bus')
            ->call('saveCashOut');

        $this->assertSame(500000, $caisse->fresh()->balance);
        $this->assertSame(0, $costReport->busRentals()->sum('paid_amount'));
    }

    public function test_cash_out_prefills_label_and_fills_single_caisse_and_account_with_the_total(): void
    {
        [$depart, $firstBus, $secondBus] = $this->createSavedCostReportWithTwoRentedBuses();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('openCashOutModal')
            ->assertSet('showCashOutModal', true)
            ->assertSet('cashOutLabel', 'Location de bus '.$depart->identifier(with_trajet_prefix: true))
            ->set("paidAmountByBusId.{$firstBus->id}", '200000')
            ->set("paidAmountByBusId.{$secondBus->id}", '50000')
            ->assertSet('cashOutCaisseRows.0.amount', '250000')
            ->assertSet('cashOutAccountRows.0.amount', '250000');
    }

    public function test_cash_out_debits_several_caisses_and_accounts_and_marks_buses_as_paid(): void
    {
        [$depart, $firstBus, $secondBus, $costReport] = $this->createSavedCostReportWithTwoRentedBuses();
        [$waveCaisse, $waveAccount] = $this->createFundedCaisseAndAccount(500000, 'Wave');
        [$omCaisse, $omAccount] = $this->createFundedCaisseAndAccount(500000, 'OM');
        $user = $this->createUserWithFullAccess();

        Livewire::actingAs($user)
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('openCashOutModal')
            ->set("paidAmountByBusId.{$firstBus->id}", '200000')
            ->set("paidAmountByBusId.{$secondBus->id}", '100000')
            ->call('addCashOutCaisseRow')
            ->set('cashOutCaisseRows.0.caisseId', (string) $waveCaisse->id)
            ->set('cashOutCaisseRows.0.amount', '200000')
            ->set('cashOutCaisseRows.1.caisseId', (string) $omCaisse->id)
            ->set('cashOutCaisseRows.1.amount', '100000')
            ->call('addCashOutAccountRow')
            ->set('cashOutAccountRows.0.accountId', (string) $waveAccount->id)
            ->set('cashOutAccountRows.0.amount', '250000')
            ->set('cashOutAccountRows.1.accountId', (string) $omAccount->id)
            ->set('cashOutAccountRows.1.amount', '50000')
            ->call('saveCashOut')
            ->assertHasNoErrors()
            ->assertSet('showCashOutModal', false);

        $this->assertSame(300000, $waveCaisse->fresh()->balance);
        $this->assertSame(400000, $omCaisse->fresh()->balance);
        $this->assertSame(250000, $waveAccount->fresh()->balance);
        $this->assertSame(450000, $omAccount->fresh()->balance);
        $this->assertSame(200000, $costReport->busRentals()->where('bus_id', $firstBus->id)->value('paid_amount'));
        $this->assertSame(100000, $costReport->busRentals()->where('bus_id', $secondBus->id)->value('paid_amount'));

        $debit = $waveAccount->transactions()->latest('id')->firstOrFail();
        $this->assertSame(AccountTransactionCategory::Expense, $debit->category);
        $this->assertSame('DEPART_COST_REPORT', $debit->reference_type);
        $this->assertSame($costReport->id, $debit->reference_id);
        $this->assertSame($user->id, $debit->user_id);
        $this->assertSame(0, app(AccountService::class)->computeTotalAccountBalance() - app(AccountService::class)->computeTotalCaisseBalance());
    }

    public function test_cash_out_is_rejected_when_caisse_total_differs_from_the_total_paid(): void
    {
        [$depart, $firstBus, , $costReport] = $this->createSavedCostReportWithTwoRentedBuses();
        [$caisse, $account] = $this->createFundedCaisseAndAccount(500000, 'Ecart');

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('openCashOutModal')
            ->set("paidAmountByBusId.{$firstBus->id}", '200000')
            ->call('addCashOutCaisseRow')
            ->set('cashOutCaisseRows.0.caisseId', (string) $caisse->id)
            ->set('cashOutCaisseRows.0.amount', '100000')
            ->set('cashOutCaisseRows.1.caisseId', (string) Caisse::query()->where('id', '!=', $caisse->id)->value('id'))
            ->set('cashOutCaisseRows.1.amount', '50000')
            ->set('cashOutAccountRows.0.accountId', (string) $account->id)
            ->call('saveCashOut')
            ->assertHasErrors('cashOutCaisseRows');

        $this->assertSame(500000, $caisse->fresh()->balance);
        $this->assertSame(0, $costReport->busRentals()->sum('paid_amount'));
    }

    public function test_cash_out_cannot_pay_a_bus_more_than_its_remaining_rental_fee(): void
    {
        [$depart, $firstBus, , $costReport] = $this->createSavedCostReportWithTwoRentedBuses();
        [$caisse, $account] = $this->createFundedCaisseAndAccount(900000, 'Plafond');
        $costReport->busRentals()->where('bus_id', $firstBus->id)->update(['paid_amount' => 150000]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('openCashOutModal')
            ->set("paidAmountByBusId.{$firstBus->id}", '60000')
            ->set('cashOutCaisseRows.0.caisseId', (string) $caisse->id)
            ->set('cashOutAccountRows.0.accountId', (string) $account->id)
            ->call('saveCashOut');

        $this->assertSame(900000, $caisse->fresh()->balance);
        $this->assertSame(900000, $account->fresh()->balance);
        $this->assertSame(150000, $costReport->busRentals()->where('bus_id', $firstBus->id)->value('paid_amount'));
    }

    public function test_cash_out_is_rolled_back_entirely_when_a_caisse_balance_is_insufficient(): void
    {
        [$depart, $firstBus, , $costReport] = $this->createSavedCostReportWithTwoRentedBuses();
        [$richCaisse, $richAccount] = $this->createFundedCaisseAndAccount(500000, 'Riche');
        [$poorCaisse, $poorAccount] = $this->createFundedCaisseAndAccount(10000, 'Pauvre');

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('openCashOutModal')
            ->set("paidAmountByBusId.{$firstBus->id}", '200000')
            ->call('addCashOutCaisseRow')
            ->set('cashOutCaisseRows.0.caisseId', (string) $richCaisse->id)
            ->set('cashOutCaisseRows.0.amount', '100000')
            ->set('cashOutCaisseRows.1.caisseId', (string) $poorCaisse->id)
            ->set('cashOutCaisseRows.1.amount', '100000')
            ->set('cashOutAccountRows.0.accountId', (string) $richAccount->id)
            ->call('saveCashOut');

        $this->assertSame(500000, $richCaisse->fresh()->balance);
        $this->assertSame(500000, $richAccount->fresh()->balance);
        $this->assertSame(10000, $poorCaisse->fresh()->balance);
        $this->assertSame(0, $costReport->busRentals()->sum('paid_amount'));
    }

    public function test_the_rental_fee_cannot_be_lowered_below_what_was_already_paid(): void
    {
        [$depart, $firstBus, , $costReport] = $this->createSavedCostReportWithTwoRentedBuses();
        $costReport->busRentals()->where('bus_id', $firstBus->id)->update(['paid_amount' => 150000]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportPage::class, ['depart' => $depart])
            ->call('startEditing')
            ->set("busRentalRows.{$firstBus->id}.rentalFee", '100000')
            ->call('save')
            ->assertHasErrors("busRentalRows.{$firstBus->id}.rentalFee");

        $this->assertSame(200000, $costReport->busRentals()->where('bus_id', $firstBus->id)->value('rental_fee'));
    }
}
