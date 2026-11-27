<?php

namespace Tests\Feature\Amicale;

use App\Enums\PermissionName;
use App\Livewire\Amicale\Home;
use App\Livewire\Auth\Login;
use App\Livewire\BackOffice\AmicaleList;
use App\Models\Ambassador;
use App\Models\Amicale;
use App\Models\Booking;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\CreatesCustomersWithBookings;
use Tests\TestCase;

class AmicaleSpaceTest extends TestCase
{
    use CreatesCustomersWithBookings;
    use DatabaseTransactions;

    private function amicaleSpaceUrl(): string
    {
        return 'http://'.config('app.amicale_admin_domain').'/';
    }

    /**
     * @param  array<string, mixed>  $amicaleAttributes
     * @return array{user: User, amicale: Amicale}
     */
    private function createAmicaleWithAccount(array $amicaleAttributes = []): array
    {
        $user = User::factory()->create();
        $user->assignRole(Amicale::ACCOUNT_ROLE_NAME);
        $amicale = Amicale::factory()->create(array_merge(['user_account_id' => $user->id], $amicaleAttributes));

        return ['user' => $user, 'amicale' => $amicale];
    }

    private function createBookingOfAmicale(Amicale $amicale, bool $isPaid = true, bool $isCancelled = false): Booking
    {
        $booking = $this->createBookingOfCustomer($this->createCustomerBroughtBy(null), $isCancelled);
        $booking->forceFill(['amicale_id' => $amicale->id, 'paye' => $isPaid])->save();

        return $booking;
    }

    // ---------------------------------------------------------------- access

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get($this->amicaleSpaceUrl())->assertRedirect();
    }

    public function test_a_user_without_the_amicale_role_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())->get($this->amicaleSpaceUrl())->assertForbidden();
    }

    public function test_an_ambassador_account_cannot_open_the_amicale_space(): void
    {
        $ambassadorUser = User::factory()->create();
        $ambassadorUser->assignRole(Ambassador::ACCOUNT_ROLE_NAME);

        $this->actingAs($ambassadorUser)->get($this->amicaleSpaceUrl())->assertForbidden();
    }

    public function test_an_amicale_account_cannot_open_the_back_office(): void
    {
        ['user' => $user] = $this->createAmicaleWithAccount();

        $this->actingAs($user)->get(route('back-office.departs.index'))->assertForbidden();
    }

    public function test_a_deactivated_amicale_is_signed_out_of_its_space(): void
    {
        ['user' => $user] = $this->createAmicaleWithAccount(['is_active' => false]);

        $this->actingAs($user)->get($this->amicaleSpaceUrl())->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---------------------------------------------------------------- login

    public function test_an_amicale_account_signs_in_on_the_amicale_domain_and_lands_on_its_space(): void
    {
        $user = User::factory()->create(['username' => 'amicale_gaston', 'password' => Hash::make('password')]);
        $user->assignRole(Amicale::ACCOUNT_ROLE_NAME);
        Amicale::factory()->create(['user_account_id' => $user->id]);
        request()->headers->set('HOST', config('app.amicale_admin_domain'));

        Livewire::test(Login::class)
            ->set('username', 'amicale_gaston')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('amicale.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_amicale_account_cannot_sign_in_on_the_back_office_domain(): void
    {
        $user = User::factory()->create(['username' => 'amicale_ailleurs', 'password' => Hash::make('password')]);
        $user->assignRole(Amicale::ACCOUNT_ROLE_NAME);
        Amicale::factory()->create(['user_account_id' => $user->id]);

        Livewire::test(Login::class)
            ->set('username', 'amicale_ailleurs')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('username');

        $this->assertGuest();
    }

    public function test_a_staff_account_cannot_sign_in_on_the_amicale_domain(): void
    {
        User::factory()->create(['username' => 'staff_member', 'password' => Hash::make('password')]);
        request()->headers->set('HOST', config('app.amicale_admin_domain'));

        Livewire::test(Login::class)
            ->set('username', 'staff_member')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('username');

        $this->assertGuest();
    }

    public function test_a_deactivated_amicale_cannot_sign_in(): void
    {
        $user = User::factory()->create(['username' => 'amicale_eteinte', 'password' => Hash::make('password')]);
        $user->assignRole(Amicale::ACCOUNT_ROLE_NAME);
        Amicale::factory()->inactive()->create(['user_account_id' => $user->id]);
        request()->headers->set('HOST', config('app.amicale_admin_domain'));

        Livewire::test(Login::class)
            ->set('username', 'amicale_eteinte')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('username');

        $this->assertGuest();
    }

    // ---------------------------------------------------------------- balance and bookings

    public function test_the_balance_is_the_paid_bookings_times_the_commission_per_ticket(): void
    {
        ['user' => $user, 'amicale' => $amicale] = $this->createAmicaleWithAccount(['commission_per_ticket' => 250]);
        $this->createBookingOfAmicale($amicale);
        $this->createBookingOfAmicale($amicale);
        $this->createBookingOfAmicale($amicale);

        Livewire::actingAs($user)->test(Home::class)
            ->assertSeeInOrder(['Solde de votre amicale', '750', 'FCFA', 'Billets payés', '3']);
    }

    public function test_unpaid_and_cancelled_bookings_earn_nothing(): void
    {
        ['user' => $user, 'amicale' => $amicale] = $this->createAmicaleWithAccount(['commission_per_ticket' => 250]);
        $this->createBookingOfAmicale($amicale);
        $this->createBookingOfAmicale($amicale, isPaid: false);
        $this->createBookingOfAmicale($amicale, isCancelled: true);

        Livewire::actingAs($user)->test(Home::class)
            ->assertSeeInOrder(['Solde de votre amicale', '250', 'FCFA', 'Billets payés', '1']);
    }

    public function test_an_amicale_only_sees_its_own_bookings(): void
    {
        ['user' => $user, 'amicale' => $amicale] = $this->createAmicaleWithAccount(['commission_per_ticket' => 250]);
        $otherAmicale = Amicale::factory()->create();
        $ownBooking = $this->createBookingOfAmicale($amicale);
        $otherBooking = $this->createBookingOfAmicale($otherAmicale);
        $ownBooking->customer->update(['prenom' => 'Moussa', 'nom' => 'Propre']);
        $otherBooking->customer->update(['prenom' => 'Fatou', 'nom' => 'Etrangere']);

        Livewire::actingAs($user)->test(Home::class)
            ->assertSee('Moussa Propre')
            ->assertDontSee('Fatou Etrangere')
            ->assertSeeInOrder(['Solde de votre amicale', '250', 'FCFA']);
    }

    public function test_an_amicale_without_bookings_sees_a_zero_balance(): void
    {
        ['user' => $user] = $this->createAmicaleWithAccount();

        Livewire::actingAs($user)->test(Home::class)
            ->assertSeeInOrder(['Solde de votre amicale', '0', 'FCFA'])
            ->assertSee('Aucune réservation pour le moment.');
    }

    // ---------------------------------------------------------------- back office

    public function test_a_back_office_user_with_the_permission_creates_an_amicale_and_its_account(): void
    {
        $staffUser = $this->createUserWithPermissions([PermissionName::ManageAmicales->value]);

        Livewire::actingAs($staffUser)->test(AmicaleList::class)
            ->set('amicaleName', 'Amicale des Sciences')
            ->set('amicaleSlug', 'Amicale-Sciences')
            ->set('commissionPerTicket', 300)
            ->set('accountUsername', 'amicale.sciences')
            ->set('accountPassword', 'un-mot-de-passe')
            ->call('saveAmicale')
            ->assertHasNoErrors();

        $amicale = Amicale::where('slug', 'amicale-sciences')->firstOrFail();
        $this->assertSame(300, $amicale->commission_per_ticket);
        $this->assertTrue($amicale->userAccount->hasRole(Amicale::ACCOUNT_ROLE_NAME));
        $this->assertTrue(Hash::check('un-mot-de-passe', $amicale->userAccount->password));
    }

    public function test_a_reserved_or_taken_subdomain_is_refused(): void
    {
        $staffUser = $this->createUserWithPermissions([PermissionName::ManageAmicales->value]);
        Amicale::factory()->create(['slug' => 'deja-prise']);

        foreach (['amicales', 'deja-prise', 'Pas Valide'] as $refusedSlug) {
            Livewire::actingAs($staffUser)->test(AmicaleList::class)
                ->set('amicaleName', 'Amicale X')
                ->set('amicaleSlug', $refusedSlug)
                ->set('commissionPerTicket', 300)
                ->set('accountUsername', 'amicale.x.'.md5($refusedSlug))
                ->set('accountPassword', 'un-mot-de-passe')
                ->call('saveAmicale')
                ->assertHasErrors('amicaleSlug');
        }
    }

    public function test_a_back_office_user_without_the_permission_cannot_open_or_create_amicales(): void
    {
        $staffUser = $this->createUserWithPermissions([]);

        Livewire::actingAs($staffUser)->test(AmicaleList::class)->assertForbidden();
    }

    public function test_the_served_trajets_of_an_amicale_can_be_changed_from_the_back_office(): void
    {
        $staffUser = $this->createUserWithPermissions([PermissionName::ManageAmicales->value]);
        $amicale = Amicale::factory()->create();
        $trajet = Trajet::create(['name' => 'Trajet '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'MBOUR']);

        Livewire::actingAs($staffUser)->test(AmicaleList::class)
            ->call('openEditTrajets', $amicale->id)
            ->set('servedTrajetIds', [(string) $trajet->id])
            ->call('saveServedTrajets')
            ->assertHasNoErrors();

        $this->assertSame([$trajet->id], $amicale->trajets()->pluck('trajets.id')->all());
    }
}
