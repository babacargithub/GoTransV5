<?php

namespace Tests\Feature;

use App\Enums\AmbassadorAbilityName;
use App\Enums\PermissionName;
use App\Livewire\Ambassador\SponsoredAmbassadors;
use App\Livewire\BackOffice\AmbassadorList;
use App\Models\Ambassador;
use App\Models\AmbassadorAbility;
use App\Models\User;
use App\Services\AmbassadorService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class AmbassadorAbilityTest extends TestCase
{
    use DatabaseTransactions;

    private function sponsorAbility(): AmbassadorAbility
    {
        return AmbassadorAbility::findByName(AmbassadorAbilityName::Sponsor);
    }

    public function test_a_new_ambassador_starts_with_the_sponsor_ability(): void
    {
        $ambassador = Ambassador::factory()->create();

        $this->assertTrue($ambassador->hasAbility(AmbassadorAbilityName::Sponsor));
    }

    public function test_an_ambassador_without_the_sponsor_ability_cannot_invite(): void
    {
        $sponsor = Ambassador::factory()->create();
        $sponsor->abilities()->detach($this->sponsorAbility());

        $this->expectException(DomainException::class);

        app(AmbassadorService::class)->inviteSponsoredAmbassador($sponsor, [
            'first_name' => 'Awa',
            'last_name' => 'Diop',
            'phone_number' => '771112233',
            'wave_phone_number' => null,
        ]);
    }

    public function test_the_invitation_form_is_hidden_from_an_ambassador_without_the_sponsor_ability(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);
        $ambassador = Ambassador::factory()->create(['user_account_id' => $user->id]);

        Livewire::actingAs($user)->test(SponsoredAmbassadors::class)->assertSee('Inviter un ambassadeur');

        $ambassador->abilities()->detach($this->sponsorAbility());

        Livewire::actingAs($user)->test(SponsoredAmbassadors::class)->assertDontSee('Inviter un ambassadeur');
    }

    public function test_a_manager_revokes_and_restores_abilities_from_the_dialog(): void
    {
        $manager = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value, PermissionName::EditAmbassador->value]);
        $ambassador = Ambassador::factory()->create();
        $sponsorAbilityId = $this->sponsorAbility()->id;

        Livewire::actingAs($manager)
            ->test(AmbassadorList::class)
            ->call('openAbilities', $ambassador->id)
            ->assertSet("abilityGrants.{$sponsorAbilityId}", true)
            ->set("abilityGrants.{$sponsorAbilityId}", false)
            ->call('saveAbilities');

        $this->assertFalse($ambassador->hasAbility(AmbassadorAbilityName::Sponsor));

        Livewire::actingAs($manager)
            ->test(AmbassadorList::class)
            ->call('openAbilities', $ambassador->id)
            ->assertSet("abilityGrants.{$sponsorAbilityId}", false)
            ->set("abilityGrants.{$sponsorAbilityId}", true)
            ->call('saveAbilities');

        $this->assertTrue($ambassador->hasAbility(AmbassadorAbilityName::Sponsor));
    }

    public function test_a_user_who_cannot_edit_ambassadors_cannot_open_the_abilities_dialog(): void
    {
        $viewer = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value]);

        Livewire::actingAs($viewer)
            ->test(AmbassadorList::class)
            ->call('openAbilities', Ambassador::factory()->create()->id)
            ->assertSet('showAbilitiesModal', false);
    }

    public function test_the_team_nav_button_and_home_invite_button_are_hidden_from_an_ambassador_without_the_sponsor_ability(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);
        $ambassador = Ambassador::factory()->create(['user_account_id' => $user->id]);

        $teamPageUrl = route('ambassador.sponsored-ambassadors');
        $navButtonLink = 'href="'.$teamPageUrl.'"';

        $this->actingAs($user)->get(route('ambassador.home'))->assertSee($navButtonLink, false)->assertSee('Inviter un ambassadeur');

        $ambassador->abilities()->detach($this->sponsorAbility());

        $this->actingAs($user)->get(route('ambassador.home'))->assertOk()->assertDontSee($navButtonLink, false)->assertDontSee('Inviter un ambassadeur');
    }

    public function test_the_invitation_form_is_open_by_default_only_when_nobody_was_invited_yet(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);
        $sponsor = Ambassador::factory()->create(['user_account_id' => $user->id]);

        Livewire::actingAs($user)->test(SponsoredAmbassadors::class)->assertSet('showInvitationForm', true);

        Ambassador::factory()->create(['sponsor_ambassador_id' => $sponsor->id]);

        Livewire::actingAs($user)
            ->test(SponsoredAmbassadors::class)
            ->assertSet('showInvitationForm', false)
            ->assertDontSee('Envoyer l\'invitation', false)
            ->set('showInvitationForm', true)
            ->assertSee('Envoyer l\'invitation', false);
    }
}
