<?php

namespace Tests\Feature\Ambassador;

use App\Enums\AmbassadorEarningType;
use App\Livewire\Ambassador\ContractSigning;
use App\Models\Ambassador;
use App\Models\AmbassadorProgramDocument;
use App\Models\AmbassadorRewardRule;
use App\Models\User;
use App\Services\AmbassadorContractTextRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ContractPlaceholdersTest extends TestCase
{
    use DatabaseTransactions;

    private function createUnsignedAmbassador(): Ambassador
    {
        return Ambassador::factory()->withoutSignedContract()->create([
            'first_name' => 'Aminata',
            'last_name' => 'Diop',
            'phone_number' => '771234567',
            'contract_starts_at' => '2026-12-01',
            'contract_ends_at' => '2027-06-30',
        ]);
    }

    private function createRewardRule(AmbassadorEarningType $earningType, int $amount): void
    {
        AmbassadorRewardRule::query()->create(['earning_type' => $earningType, 'amount' => $amount, 'is_enabled' => true]);
    }

    public function test_placeholders_are_replaced_with_the_values_of_the_ambassador(): void
    {
        $ambassador = $this->createUnsignedAmbassador();
        $this->createRewardRule(AmbassadorEarningType::Acquisition, 1500);
        $this->createRewardRule(AmbassadorEarningType::SponsorshipBonus, 12000);

        $renderedText = app(AmbassadorContractTextRenderer::class)->render(
            '{{ambassador_full_name}} ({{ambassador_phone_number}}) du {{contract_start}} au {{contract_end}} : {{acquisition_earning}} / {{promotion_earning}} FCFA, {{unknown_value}}',
            $ambassador,
        );

        $this->assertSame('Aminata Diop (771234567) du 01/12/2026 au 30/06/2027 : 1 500 / 12 000 FCFA, {{unknown_value}}', $renderedText);
    }

    public function test_the_unsigned_ambassador_reads_their_filled_contract_and_signs_the_filled_text(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);
        $ambassador = $this->createUnsignedAmbassador();
        $ambassador->update(['user_account_id' => $user->id]);
        AmbassadorProgramDocument::factory()->contract()->create(['version' => 99, 'content' => 'Entre la Société et {{ambassador_full_name}}.']);

        $this->actingAs($user);

        Livewire::test(ContractSigning::class)
            ->assertSee('Entre la Société et Aminata Diop.')
            ->assertDontSee('{{ambassador_full_name}}');
    }
}
