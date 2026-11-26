<?php

namespace App\Services;

use App\Enums\AmbassadorAbilityName;
use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Back-office management of ambassadors: listing, CRUD and the status lifecycle
 * (approve / reject / suspend / reactivate).
 *
 * Deliberately holds no earnings, payout or reporting logic yet. Callers are
 * responsible for checking permissions before invoking a mutation.
 */
class AmbassadorService
{
    /**
     * Paginated list for the back office, optionally filtered by status and a search term
     * (name, phone number or code).
     */
    public function paginateForBackOffice(
        ?string $searchTerm = null,
        ?AmbassadorStatus $status = null,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return Ambassador::query()
            ->with('sponsor')
            ->withCount(['customersWithActiveBooking as customers_count', 'sponsoredAmbassadors'])
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($searchTerm, function (Builder $query, string $searchTerm): void {
                $likeTerm = '%'.trim($searchTerm).'%';

                $query->where(fn (Builder $searchQuery) => $searchQuery
                    ->where('first_name', 'like', $likeTerm)
                    ->orWhere('last_name', 'like', $likeTerm)
                    ->orWhere('phone_number', 'like', $likeTerm)
                    ->orWhere('code', 'like', $likeTerm));
            })
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Create an ambassador from the back office. They are active immediately because a back-office
     * user vouches for them; a code is generated when none is given. When login credentials are
     * given, a user account with the ambassador role is created and linked in the same transaction.
     *
     * @param  array{first_name: string, last_name: string, phone_number: string, wave_phone_number?: ?string, code?: ?string, sponsor_ambassador_id?: ?int, contract_starts_at?: mixed, contract_ends_at: mixed}  $attributes
     * @param  array{username: string, password: string}|null  $accountCredentials
     */
    public function createActiveAmbassador(array $attributes, User $creator, ?array $accountCredentials = null): Ambassador
    {
        $attributes['code'] = filled($attributes['code'] ?? null)
            ? $attributes['code']
            : $this->generateUniqueCode();

        return DB::transaction(function () use ($attributes, $creator, $accountCredentials): Ambassador {
            if ($accountCredentials !== null) {
                $attributes['user_account_id'] = $this->createAccountForAmbassador(
                    $attributes['first_name'].' '.$attributes['last_name'],
                    $accountCredentials,
                )->id;
            }

            return Ambassador::create($attributes + [
                'status' => AmbassadorStatus::Active,
                'approved_by_user_id' => $creator->id,
                'approved_at' => now(),
            ]);
        });
    }

    /**
     * Records the invitation an ambassador sends to someone they sponsor. The invited ambassador starts as
     * `invited`, without contract nor login account, and carries the secret token of the link they must
     * open to accept ({@see self::acceptInvitation()}). The sponsor is fixed here and can never change
     * afterwards.
     *
     * Guards, re-checked under a lock on the sponsor row: the sponsor must be active (a suspended, pending
     * or rejected ambassador cannot recruit), the invited person cannot be the sponsor themselves (same
     * phone or Wave number) and cannot already be an ambassador. A brand-new ambassador has no sponsored
     * ambassadors yet, so a sponsorship cycle is impossible by construction.
     *
     * Inviting a number that still has a pending invitation issues a new invitation: the token is replaced (the
     * previous link stops working) and the latest inviter becomes the sponsor.
     *
     * @param  array{first_name: string, last_name: string, phone_number: string, wave_phone_number?: ?string}  $applicantAttributes
     *
     * @throws DomainException when one of the guards above fails
     */
    public function inviteSponsoredAmbassador(Ambassador $sponsor, array $applicantAttributes): Ambassador
    {
        return DB::transaction(function () use ($sponsor, $applicantAttributes): Ambassador {
            $lockedSponsor = Ambassador::query()->lockForUpdate()->findOrFail($sponsor->id);

            if ($lockedSponsor->status !== AmbassadorStatus::Active) {
                throw new DomainException("Ce lien de parrainage n'est plus valide.");
            }

            if (! $lockedSponsor->hasAbility(AmbassadorAbilityName::Sponsor)) {
                throw new DomainException("Vous n'êtes pas autorisé à parrainer d'autres ambassadeurs.");
            }

            $applicantPhoneNumber = (int) $applicantAttributes['phone_number'];
            $applicantWaveNumber = filled($applicantAttributes['wave_phone_number'] ?? null)
                ? (int) $applicantAttributes['wave_phone_number']
                : null;

            if (in_array($lockedSponsor->phone_number, [$applicantPhoneNumber, $applicantWaveNumber], true)
                || ($lockedSponsor->wave_phone_number !== null && in_array((int) $lockedSponsor->wave_phone_number, [$applicantPhoneNumber, $applicantWaveNumber], true))) {
                throw new DomainException('Vous ne pouvez pas vous parrainer vous-même.');
            }

            $existingAmbassador = Ambassador::query()->where('phone_number', $applicantPhoneNumber)->lockForUpdate()->first();

            if ($existingAmbassador !== null && $existingAmbassador->status !== AmbassadorStatus::Invited) {
                throw new DomainException('Ce numéro correspond déjà à un ambassadeur.');
            }

            if ($existingAmbassador !== null) {
                $existingAmbassador->update([
                    'sponsor_ambassador_id' => $lockedSponsor->id,
                    'first_name' => $applicantAttributes['first_name'],
                    'last_name' => $applicantAttributes['last_name'],
                    'wave_phone_number' => $applicantWaveNumber,
                    'invitation_token' => Str::random(40),
                ]);

                return $existingAmbassador;
            }

            return Ambassador::create([
                'sponsor_ambassador_id' => $lockedSponsor->id,
                'first_name' => $applicantAttributes['first_name'],
                'last_name' => $applicantAttributes['last_name'],
                'phone_number' => $applicantPhoneNumber,
                'wave_phone_number' => $applicantWaveNumber,
                'code' => $this->generateUniqueCode(),
                'invitation_token' => Str::random(40),
                'status' => AmbassadorStatus::Invited,
            ]);
        });
    }

    /**
     * The invited ambassador behind an invitation link, null when the token is unknown or already used.
     */
    public function findInvitedAmbassadorByToken(string $invitationToken): ?Ambassador
    {
        return Ambassador::query()
            ->where('invitation_token', $invitationToken)
            ->where('status', AmbassadorStatus::Invited)
            ->first();
    }

    /**
     * The invited person accepts their sponsor's invitation: the link is spent and the ambassador now waits
     * for a back-office user to approve them. A sponsor suspended in the meantime cannot get recruits.
     *
     * @throws DomainException when the invitation is unknown, already accepted, or its sponsor is no longer active
     */
    public function acceptInvitation(string $invitationToken): Ambassador
    {
        return DB::transaction(function () use ($invitationToken): Ambassador {
            $invitedAmbassador = Ambassador::query()
                ->where('invitation_token', $invitationToken)
                ->where('status', AmbassadorStatus::Invited)
                ->lockForUpdate()
                ->first();

            if ($invitedAmbassador === null || $invitedAmbassador->sponsor?->status !== AmbassadorStatus::Active) {
                throw new DomainException("Ce lien d'invitation n'est plus valide.");
            }

            $invitedAmbassador->update([
                'status' => AmbassadorStatus::PendingApproval,
                'invitation_token' => null,
            ]);

            return $invitedAmbassador;
        });
    }

    /**
     * @param  array{username: string, password: string}  $accountCredentials
     */
    private function createAccountForAmbassador(string $fullName, array $accountCredentials): User
    {
        $username = mb_strtolower($accountCredentials['username']);

        // The users table requires a unique email, which ambassadors are not asked for.
        $userAccount = (new User)->forceFill([
            'name' => $fullName,
            'username' => $username,
            'email' => $username.'@users.invalid',
            'password' => $accountCredentials['password'],
            'active' => true,
        ]);
        $userAccount->save();
        $userAccount->assignRole(Ambassador::ACCOUNT_ROLE_NAME);

        return $userAccount;
    }

    /**
     * Update an ambassador's identity, payout number, code and contract dates. Status changes go
     * through the dedicated lifecycle methods; the sponsor can never be changed afterwards.
     *
     * @param  array{first_name?: string, last_name?: string, phone_number?: string, wave_phone_number?: ?string, code?: string, contract_starts_at?: mixed, contract_ends_at?: mixed}  $attributes
     */
    public function updateAmbassador(Ambassador $ambassador, array $attributes): Ambassador
    {
        $ambassador->update(array_intersect_key($attributes, array_flip([
            'first_name',
            'last_name',
            'phone_number',
            'wave_phone_number',
            'code',
            'contract_starts_at',
            'contract_ends_at',
        ])));

        return $ambassador;
    }

    /**
     * Approve a pending ambassador, setting the contract that makes them able to acquire customers. When login
     * credentials are given, their user account is created in the same transaction, so an approval never
     * leaves an ambassador half set up.
     *
     * @param  array{username: string, password: string}|null  $accountCredentials
     *
     * @throws DomainException when the ambassador is not pending approval or already has a login account
     */
    public function approveAmbassador(
        Ambassador $ambassador,
        User $approver,
        mixed $contractStartsAt,
        mixed $contractEndsAt,
        ?array $accountCredentials = null,
    ): Ambassador {
        $this->assertStatus($ambassador, AmbassadorStatus::PendingApproval, 'validé');

        if ($accountCredentials !== null && $ambassador->user_account_id !== null) {
            throw new DomainException('Cet ambassadeur a déjà un compte utilisateur.');
        }

        DB::transaction(function () use ($ambassador, $approver, $contractStartsAt, $contractEndsAt, $accountCredentials): void {
            $attributes = [
                'status' => AmbassadorStatus::Active,
                'contract_starts_at' => $contractStartsAt,
                'contract_ends_at' => $contractEndsAt,
                'approved_by_user_id' => $approver->id,
                'approved_at' => now(),
            ];

            if ($accountCredentials !== null) {
                $attributes['user_account_id'] = $this->createAccountForAmbassador($ambassador->full_name, $accountCredentials)->id;
            }

            $ambassador->update($attributes);
        });

        return $ambassador;
    }

    /**
     * @throws DomainException when the ambassador is not pending approval
     */
    public function rejectAmbassador(Ambassador $ambassador, User $reviewer): Ambassador
    {
        $this->assertStatus($ambassador, AmbassadorStatus::PendingApproval, 'refusé');

        $ambassador->update([
            'status' => AmbassadorStatus::Rejected,
            'approved_by_user_id' => $reviewer->id,
            'approved_at' => now(),
        ]);

        return $ambassador;
    }

    /**
     * @throws DomainException when the ambassador is not active
     */
    public function suspendAmbassador(Ambassador $ambassador): Ambassador
    {
        $this->assertStatus($ambassador, AmbassadorStatus::Active, 'suspendu');

        $ambassador->update(['status' => AmbassadorStatus::Suspended]);

        return $ambassador;
    }

    /**
     * @throws DomainException when the ambassador is not suspended
     */
    public function reactivateAmbassador(Ambassador $ambassador): Ambassador
    {
        $this->assertStatus($ambassador, AmbassadorStatus::Suspended, 'réactivé');

        $ambassador->update(['status' => AmbassadorStatus::Active]);

        return $ambassador;
    }

    /**
     * Whether the ambassador has no history and can safely be deleted.
     */
    public function canDeleteAmbassador(Ambassador $ambassador): bool
    {
        return ! $ambassador->customers()->exists()
            && ! $ambassador->bookings()->exists()
            && ! $ambassador->earnings()->exists()
            && ! $ambassador->payouts()->exists()
            && ! $ambassador->sponsoredAmbassadors()->exists();
    }

    /**
     * @throws DomainException when the ambassador already has customers, earnings or sponsored ambassadors
     */
    public function deleteAmbassador(Ambassador $ambassador): void
    {
        if (! $this->canDeleteAmbassador($ambassador)) {
            throw new DomainException('Cet ambassadeur a un historique (clients, gains ou filleuls) : suspendez-le plutôt que de le supprimer.');
        }

        $ambassador->delete();
    }

    /**
     * The ambassador whose code was entered (case-insensitive), only if that code may currently attribute
     * new customers. Unknown, suspended or out-of-contract codes simply resolve to null.
     */
    public function findAcquiringAmbassadorByCode(?string $enteredCode): ?Ambassador
    {
        if (blank($enteredCode)) {
            return null;
        }

        return Ambassador::query()
            ->acquiringCustomers()
            ->withCode($enteredCode)
            ->first();
    }

    /**
     * A 3-character upper-case code (kept short for URLs, no 0/O/1/I) that no other ambassador uses.
     */
    public function generateUniqueCode(): string
    {
        $codeAlphabet = str_split('ABCDEFGHJKLMNPQRSTUVWXYZ23456789');

        do {
            $candidateCode = implode('', array_map(
                fn (): string => $codeAlphabet[array_rand($codeAlphabet)],
                range(1, 3),
            ));
        } while (Ambassador::withCode($candidateCode)->exists());

        return $candidateCode;
    }

    /**
     * @throws DomainException
     */
    private function assertStatus(Ambassador $ambassador, AmbassadorStatus $requiredStatus, string $actionLabel): void
    {
        if ($ambassador->status !== $requiredStatus) {
            throw new DomainException(
                "Cet ambassadeur ne peut pas être {$actionLabel} : statut actuel « {$ambassador->status->label()} »."
            );
        }
    }
}
