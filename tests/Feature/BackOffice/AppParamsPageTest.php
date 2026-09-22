<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\AppParamsPage;
use App\Models\AppParams;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class AppParamsPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.parametres.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_prefills_the_form_from_app_params(): void
    {
        AppParams::query()->delete();
        AppParams::create(['data' => [
            'app_name' => 'Globe Transports',
            'main_customer_service_number' => '771273535',
            'discount_price' => 500,
            'trajets' => [['id' => 1, 'name' => 'UGB vers DAKAR']],
        ]]);

        Livewire::actingAs(User::factory()->create())
            ->test(AppParamsPage::class)
            ->assertOk()
            ->assertSet('appName', 'Globe Transports')
            ->assertSet('mainCustomerServiceNumber', '771273535')
            ->assertSet('discountPrice', 500);
    }

    public function test_saving_merges_the_edited_keys_and_preserves_the_others(): void
    {
        AppParams::query()->delete();
        AppParams::create(['data' => [
            'app_name' => 'Ancien nom',
            'trajets' => [['id' => 1, 'name' => 'UGB vers DAKAR']],
        ]]);

        Livewire::actingAs(User::factory()->create())
            ->test(AppParamsPage::class)
            ->set('appName', 'Nouveau nom')
            ->set('discountPrice', 750)
            ->call('save')
            ->assertHasNoErrors();

        $data = AppParams::query()->first()->data;
        $this->assertSame('Nouveau nom', $data['app_name']);
        $this->assertSame(750, $data['discount_price']);
        $this->assertSame([['id' => 1, 'name' => 'UGB vers DAKAR']], $data['trajets']);
    }

    public function test_saving_requires_the_app_name(): void
    {
        AppParams::query()->delete();
        AppParams::create(['data' => ['app_name' => 'Globe']]);

        Livewire::actingAs(User::factory()->create())
            ->test(AppParamsPage::class)
            ->set('appName', '')
            ->call('save')
            ->assertHasErrors(['appName']);
    }
}
