<?php

namespace Tests\Feature;

use App\Enums\AmbassadorStatus;
use App\Enums\PermissionName;
use App\Livewire\Ambassador\SponsoredAmbassadors;
use App\Livewire\BackOffice\AmbassadorList;
use App\Livewire\Website\AmbassadorInvitation;
use App\Models\Ambassador;
use App\Models\Employe;
use App\Models\User;
use App\Services\AmbassadorService;
use App\Services\NotificationService;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmbassadorSponsoredInvitationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{first_name: string, last_name: string, phone_number: string, wave_phone_number: ?string}
     */
    private function invitedPersonAttributes(string $phoneNumber = '771112233'): array
    {
        return ['first_name' => 'Awa', 'last_name' => 'Diop', 'phone_number' => $phoneNumber, 'wave_phone_number' => null];
    }

    /**
     * @return array{user: User, ambassador: Ambassador}
     */
    private function createLoggedInSponsor(): array
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);

        return ['user' => $user, 'ambassador' => Ambassador::factory()->create(['user_account_id' => $user->id])];
    }

    private function createEmployeForUser(User $user, int $phoneNumber): Employe
    {
        return (new Employe)->forceFill([
            'prenom' => 'Admin',
            'nom' => 'Test'.random_int(1000, 9999),
            'tel' => $phoneNumber,
            'email' => 'admin'.uniqid().'@example.com',
            'actif' => true,
            'user_account_id' => $user->id,
        ]);
    }

    public function test_an_active_sponsor_creates_an_invited_ambassador_with_a_token_and_no_contract_nor_account(): void
    {
        $sponsor = Ambassador::factory()->create();

        $invitedAmbassador = app(AmbassadorService::class)->inviteSponsoredAmbassador($sponsor, $this->invitedPersonAttributes());

        $this->assertSame(AmbassadorStatus::Invited, $invitedAmbassador->status);
        $this->assertSame($sponsor->id, $invitedAmbassador->sponsor_ambassador_id);
        $this->assertNotEmpty($invitedAmbassador->invitation_token);
        $this->assertNull($invitedAmbassador->contract_ends_at);
        $this->assertNull($invitedAmbassador->user_account_id);
        $this->assertFalse($invitedAmbassador->canAcquireCustomers());
    }

    public function test_a_sponsor_who_is_not_active_cannot_invite(): void
    {
        foreach ([Ambassador::factory()->suspended(), Ambassador::factory()->pendingApproval()] as $sponsorFactory) {
            $sponsor = $sponsorFactory->create();

            try {
                app(AmbassadorService::class)->inviteSponsoredAmbassador($sponsor, $this->invitedPersonAttributes());
                $this->fail('A non-active sponsor must not be able to invite.');
            } catch (DomainException) {
                $this->assertSame(0, $sponsor->sponsoredAmbassadors()->count());
            }
        }
    }

    public function test_a_sponsor_cannot_invite_themselves_with_their_phone_or_wave_number(): void
    {
        $sponsor = Ambassador::factory()->create(['phone_number' => '772223344', 'wave_phone_number' => '773334455']);

        foreach (['772223344', '773334455'] as $selfPhoneNumber) {
            try {
                app(AmbassadorService::class)->inviteSponsoredAmbassador($sponsor, $this->invitedPersonAttributes($selfPhoneNumber));
                $this->fail('Self-sponsoring must be refused.');
            } catch (DomainException) {
                $this->assertSame(0, $sponsor->sponsoredAmbassadors()->count());
            }
        }
    }

    public function test_an_existing_ambassador_cannot_be_invited(): void
    {
        $sponsor = Ambassador::factory()->create();
        $existingAmbassador = Ambassador::factory()->create();

        $this->expectException(DomainException::class);

        app(AmbassadorService::class)->inviteSponsoredAmbassador($sponsor, $this->invitedPersonAttributes((string) $existingAmbassador->phone_number));
    }

    public function test_inviting_a_number_that_is_still_invited_replaces_the_token_and_the_previous_link_stops_working(): void
    {
        $firstSponsor = Ambassador::factory()->create();
        $secondSponsor = Ambassador::factory()->create();
        $ambassadorService = app(AmbassadorService::class);
        $firstInvitation = $ambassadorService->inviteSponsoredAmbassador($firstSponsor, $this->invitedPersonAttributes());
        $firstToken = $firstInvitation->invitation_token;

        $secondInvitation = $ambassadorService->inviteSponsoredAmbassador($secondSponsor, $this->invitedPersonAttributes());

        $this->assertSame($firstInvitation->id, $secondInvitation->id);
        $this->assertNotSame($firstToken, $secondInvitation->invitation_token);
        $this->assertSame($secondSponsor->id, $secondInvitation->sponsor_ambassador_id);
        $this->assertNull($ambassadorService->findInvitedAmbassadorByToken($firstToken));
        $this->assertNotNull($ambassadorService->findInvitedAmbassadorByToken($secondInvitation->invitation_token));
    }

    public function test_an_ambassador_who_accepted_an_invitation_cannot_be_invited_again(): void
    {
        $sponsor = Ambassador::factory()->create();
        $ambassadorService = app(AmbassadorService::class);
        $invitation = $ambassadorService->inviteSponsoredAmbassador($sponsor, $this->invitedPersonAttributes());
        $ambassadorService->acceptInvitation($invitation->invitation_token);

        $this->expectException(DomainException::class);

        $ambassadorService->inviteSponsoredAmbassador($sponsor, $this->invitedPersonAttributes());
    }

    public function test_accepting_the_invitation_makes_the_ambassador_pending_and_spends_the_link(): void
    {
        $invitedAmbassador = Ambassador::factory()->invited()->sponsoredBy(Ambassador::factory()->create())->create();
        $invitationToken = $invitedAmbassador->invitation_token;

        $acceptedAmbassador = app(AmbassadorService::class)->acceptInvitation($invitationToken);

        $this->assertSame(AmbassadorStatus::PendingApproval, $acceptedAmbassador->status);
        $this->assertNull($acceptedAmbassador->fresh()->invitation_token);
        $this->assertNull(app(AmbassadorService::class)->findInvitedAmbassadorByToken($invitationToken));

        $this->expectException(DomainException::class);
        app(AmbassadorService::class)->acceptInvitation($invitationToken);
    }

    public function test_an_invitation_cannot_be_accepted_once_its_sponsor_is_suspended(): void
    {
        $invitedAmbassador = Ambassador::factory()->invited()->sponsoredBy(Ambassador::factory()->suspended()->create())->create();

        try {
            app(AmbassadorService::class)->acceptInvitation($invitedAmbassador->invitation_token);
            $this->fail('A suspended sponsor cannot get recruits.');
        } catch (DomainException) {
            $this->assertSame(AmbassadorStatus::Invited, $invitedAmbassador->fresh()->status);
        }
    }

    public function test_the_invitation_page_accepts_and_notifies_the_sponsor_and_the_administrators(): void
    {
        $invitedAmbassador = Ambassador::factory()->invited()->sponsoredBy(Ambassador::factory()->create())->create();

        $this->mock(NotificationService::class, function (MockInterface $notificationService): void {
            $notificationService->shouldReceive('notifySponsorOfAcceptedInvitation')->once();
            $notificationService->shouldReceive('notifyAdministratorsOfAmbassadorAwaitingApproval')->once();
        });

        Livewire::test(AmbassadorInvitation::class, ['invitationToken' => $invitedAmbassador->invitation_token])
            ->assertSee($invitedAmbassador->first_name)
            ->call('acceptInvitation')
            ->assertSet('invitationWasAccepted', true);

        $this->assertSame(AmbassadorStatus::PendingApproval, $invitedAmbassador->fresh()->status);
    }

    public function test_a_failing_sms_never_hides_a_recorded_acceptance(): void
    {
        $invitedAmbassador = Ambassador::factory()->invited()->sponsoredBy(Ambassador::factory()->create())->create();

        $this->mock(NotificationService::class, function (MockInterface $notificationService): void {
            $notificationService->shouldReceive('notifySponsorOfAcceptedInvitation')->andThrow(new \RuntimeException('SMS provider down'));
            $notificationService->shouldReceive('notifyAdministratorsOfAmbassadorAwaitingApproval')->once();
        });

        Livewire::test(AmbassadorInvitation::class, ['invitationToken' => $invitedAmbassador->invitation_token])
            ->call('acceptInvitation')
            ->assertSet('invitationWasAccepted', true);
    }

    public function test_an_unknown_or_spent_invitation_link_is_invalid(): void
    {
        $acceptedAmbassador = Ambassador::factory()->pendingApproval()->sponsoredBy(Ambassador::factory()->create())->create(['invitation_token' => null]);

        Livewire::test(AmbassadorInvitation::class, ['invitationToken' => 'unknown-token'])
            ->assertSee("Ce lien d'invitation n'est plus valide.", false)
            ->call('acceptInvitation')
            ->assertSet('invitationWasAccepted', false);

        $this->assertSame(AmbassadorStatus::PendingApproval, $acceptedAmbassador->fresh()->status);
    }

    public function test_a_sponsor_invites_from_their_space_and_the_invited_person_gets_the_link_by_sms(): void
    {
        ['user' => $user, 'ambassador' => $sponsor] = $this->createLoggedInSponsor();

        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->withArgs(fn (string $phoneNumber, string $message): bool => $phoneNumber === '771112233' && str_contains($message, '/devenir-ambassadeur/'));

        Livewire::actingAs($user)
            ->test(SponsoredAmbassadors::class)
            ->set('firstName', 'Awa')
            ->set('lastName', 'Diop')
            ->set('phoneNumber', '771112233')
            ->call('inviteAmbassador')
            ->assertHasNoErrors()
            ->assertSet('invitedFirstName', 'Awa');

        $invitedAmbassador = Ambassador::query()->where('phone_number', 771112233)->sole();
        $this->assertSame($sponsor->id, $invitedAmbassador->sponsor_ambassador_id);
        $this->assertSame(AmbassadorStatus::Invited, $invitedAmbassador->status);
    }

    #[DataProvider('newSenegalMobilePrefixPhoneNumbers')]
    public function test_the_invitation_form_accepts_the_new_75_and_71_mobile_prefixes(string $phoneNumber): void
    {
        ['user' => $user] = $this->createLoggedInSponsor();
        $this->mock(SMSSender::class)->shouldReceive('sendSms')->once();

        Livewire::actingAs($user)
            ->test(SponsoredAmbassadors::class)
            ->set('firstName', 'Awa')
            ->set('lastName', 'Diop')
            ->set('phoneNumber', $phoneNumber)
            ->call('inviteAmbassador')
            ->assertHasNoErrors();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function newSenegalMobilePrefixPhoneNumbers(): array
    {
        return [
            'prefix 75' => ['751112233'],
            'prefix 71' => ['711112233'],
        ];
    }

    public function test_the_invitation_form_rejects_an_invalid_phone_number_and_is_rate_limited(): void
    {
        ['user' => $user, 'ambassador' => $sponsor] = $this->createLoggedInSponsor();

        Livewire::actingAs($user)
            ->test(SponsoredAmbassadors::class)
            ->set('firstName', 'Awa')
            ->set('lastName', 'Diop')
            ->set('phoneNumber', '12345')
            ->call('inviteAmbassador')
            ->assertHasErrors(['phoneNumber']);

        $rateLimiterKey = 'ambassador-invitation:'.$sponsor->id;
        RateLimiter::clear($rateLimiterKey);
        foreach (range(1, SponsoredAmbassadors::MAX_INVITATIONS_PER_HOUR) as $unused) {
            RateLimiter::hit($rateLimiterKey, 3600);
        }

        Livewire::actingAs($user)
            ->test(SponsoredAmbassadors::class)
            ->set('firstName', 'Awa')
            ->set('lastName', 'Diop')
            ->set('phoneNumber', '771112233')
            ->call('inviteAmbassador')
            ->assertSet('invitedFirstName', null)
            ->assertSet('formError', "Trop d'invitations envoyées. Réessayez plus tard.");

        $this->assertSame(0, Ambassador::query()->where('phone_number', 771112233)->count());
        RateLimiter::clear($rateLimiterKey);
    }

    public function test_every_full_access_user_with_an_employee_record_is_told_an_ambassador_awaits_approval(): void
    {
        $fullAccessAdministrator = $this->createUserWithFullAccess();
        $this->createEmployeForUser($fullAccessAdministrator, 770000001)->save();
        $fullAccessAdministratorWithoutEmploye = $this->createUserWithFullAccess();
        $limitedUser = $this->createUserWithPermissions([]);
        $this->createEmployeForUser($limitedUser, 770000002)->save();

        $acceptedAmbassador = Ambassador::factory()->pendingApproval()->sponsoredBy(Ambassador::factory()->create())->create();

        $sentToPhoneNumbers = [];
        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->andReturnUsing(function (string $phoneNumber) use (&$sentToPhoneNumbers): void {
                $sentToPhoneNumbers[] = $phoneNumber;
            });

        app(NotificationService::class)->notifyAdministratorsOfAmbassadorAwaitingApproval($acceptedAmbassador);

        $this->assertContains('770000001', $sentToPhoneNumbers);
        $this->assertNotContains('770000002', $sentToPhoneNumbers);
        $this->assertNotNull($fullAccessAdministratorWithoutEmploye);
    }

    public function test_approving_an_ambassador_creates_their_account_and_sms_them_their_credentials(): void
    {
        $approver = $this->createUserWithPermissions([
            PermissionName::ViewAmbassadors->value,
            PermissionName::ApproveAmbassador->value,
            PermissionName::CreateUser->value,
        ]);
        $pendingAmbassador = Ambassador::factory()->pendingApproval()->create();

        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->withArgs(fn (string $phoneNumber, string $message): bool => $phoneNumber === (string) $pendingAmbassador->phone_number
                && str_contains($message, route('ambassador.home'))
                && str_contains($message, 'Identifiant : awa.test')
                && str_contains($message, 'Mot de passe : secret1234'));

        Livewire::actingAs($approver)->test(AmbassadorList::class)
            ->call('openApproval', $pendingAmbassador->id)
            ->assertSet('createUserAccount', true)
            ->set('accountUsername', 'awa.test')
            ->set('accountPassword', 'secret1234')
            ->call('approveAmbassador')
            ->assertHasNoErrors();

        $pendingAmbassador->refresh();
        $this->assertSame(AmbassadorStatus::Active, $pendingAmbassador->status);
        $this->assertSame('awa.test', $pendingAmbassador->userAccount->username);
        $this->assertTrue($pendingAmbassador->userAccount->hasRole(Ambassador::ACCOUNT_ROLE_NAME));
    }

    public function test_approving_without_account_creation_only_tells_the_ambassador_they_are_validated(): void
    {
        $approver = $this->createUserWithPermissions([
            PermissionName::ViewAmbassadors->value,
            PermissionName::ApproveAmbassador->value,
            PermissionName::CreateUser->value,
        ]);
        $pendingAmbassador = Ambassador::factory()->pendingApproval()->create();

        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->withArgs(fn (string $phoneNumber, string $message): bool => ! str_contains($message, 'Mot de passe'));

        Livewire::actingAs($approver)->test(AmbassadorList::class)
            ->call('openApproval', $pendingAmbassador->id)
            ->set('createUserAccount', false)
            ->call('approveAmbassador');

        $pendingAmbassador->refresh();
        $this->assertSame(AmbassadorStatus::Active, $pendingAmbassador->status);
        $this->assertNull($pendingAmbassador->user_account_id);
    }

    public function test_approval_with_an_already_used_username_is_refused_and_leaves_the_ambassador_pending(): void
    {
        $approver = $this->createUserWithPermissions([
            PermissionName::ViewAmbassadors->value,
            PermissionName::ApproveAmbassador->value,
            PermissionName::CreateUser->value,
        ]);
        $existingUser = User::factory()->create();
        $pendingAmbassador = Ambassador::factory()->pendingApproval()->create();

        $this->mock(SMSSender::class)->shouldReceive('sendSms')->never();

        Livewire::actingAs($approver)->test(AmbassadorList::class)
            ->call('openApproval', $pendingAmbassador->id)
            ->set('accountUsername', $existingUser->username)
            ->set('accountPassword', 'secret1234')
            ->call('approveAmbassador')
            ->assertHasErrors(['accountUsername']);

        $this->assertSame(AmbassadorStatus::PendingApproval, $pendingAmbassador->refresh()->status);
    }
}
