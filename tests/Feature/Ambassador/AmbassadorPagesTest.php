<?php

namespace Tests\Feature\Ambassador;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Enums\AmbassadorPayoutStatus;
use App\Livewire\Ambassador\EarningsPage;
use App\Livewire\Ambassador\PayoutsPage;
use App\Livewire\Ambassador\SponsoredAmbassadors;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class AmbassadorPagesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{user: User, ambassador: Ambassador}
     */
    private function createLoggedInAmbassador(): array
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);

        return ['user' => $user, 'ambassador' => Ambassador::factory()->create(['user_account_id' => $user->id])];
    }

    private function pageUrl(string $path): string
    {
        return 'http://'.config('app.ambassador_domain').'/'.$path;
    }

    public function test_pages_require_the_ambassador_role(): void
    {
        $paths = ['gains', 'filleuls', 'paiements'];

        foreach ($paths as $path) {
            $this->get($this->pageUrl($path))->assertRedirect();
        }

        $this->actingAs(User::factory()->create());

        foreach ($paths as $path) {
            $this->get($this->pageUrl($path))->assertForbidden();
        }
    }

    public function test_the_earnings_page_lists_only_the_own_earnings_with_type_status_and_amount(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInAmbassador();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 1234, 'type' => AmbassadorEarningType::SponsorshipBonus]);
        AmbassadorEarning::factory()->create(['amount' => 987654]);

        Livewire::actingAs($user)->test(EarningsPage::class)
            ->assertSee('Prime de parrainage')
            ->assertSee('1 234 FCFA')
            ->assertSee('Approuvé')
            ->assertDontSee('987 654');
    }

    public function test_the_earnings_page_can_be_filtered_by_status(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInAmbassador();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 1111]);
        AmbassadorEarning::factory()->for($ambassador)->cancelled()->create(['amount' => 2222]);

        Livewire::actingAs($user)->test(EarningsPage::class)
            ->set('statusFilter', AmbassadorEarningStatus::Cancelled->value)
            ->assertSee('2 222 FCFA')
            ->assertDontSee('1 111 FCFA');
    }

    public function test_the_sponsored_ambassadors_page_shows_progress_and_only_own_sponsored_ambassadors(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInAmbassador();
        $sponsoredAmbassador = Ambassador::factory()->sponsoredBy($ambassador)->create(['first_name' => 'Fatou', 'last_name' => 'Filleule']);
        Ambassador::factory()->sponsoredBy(Ambassador::factory()->create())->create(['first_name' => 'Intrus', 'last_name' => 'Etranger']);
        AmbassadorEarning::factory()->for($sponsoredAmbassador)->count(2)->create(['type' => AmbassadorEarningType::Acquisition]);
        AmbassadorEarning::factory()->for($sponsoredAmbassador)->cancelled()->create(['type' => AmbassadorEarningType::Acquisition]);

        $component = Livewire::actingAs($user)->test(SponsoredAmbassadors::class)
            ->assertSee('Fatou Filleule')
            ->assertSee('2 /')
            ->assertDontSee('Prime obtenue')
            ->assertDontSee('Intrus');

        AmbassadorEarning::factory()->for($ambassador)->approved()->create([
            'type' => AmbassadorEarningType::SponsorshipBonus,
            'source_ambassador_id' => $sponsoredAmbassador->id,
        ]);

        $component->call('$refresh')->assertSee('Prime obtenue');
    }

    public function test_the_payouts_page_lists_only_own_payouts_without_exposing_the_failure_reason(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInAmbassador();
        AmbassadorPayout::factory()->for($ambassador)->succeeded()->create(['amount' => 7000]);
        AmbassadorPayout::factory()->for($ambassador)->create([
            'amount' => 3000,
            'status' => AmbassadorPayoutStatus::Failed,
            'failure_reason' => 'Wave HTTP 500 secret detail',
        ]);
        AmbassadorPayout::factory()->create(['amount' => 876543]);

        Livewire::actingAs($user)->test(PayoutsPage::class)
            ->assertSee('7 000 FCFA')
            ->assertSee('Payé')
            ->assertSee('3 000 FCFA')
            ->assertSee('Échoué')
            ->assertDontSee('secret detail')
            ->assertDontSee('876 543');
    }
}
