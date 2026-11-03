<?php

namespace App\Services;

use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
            ->withCount(['customers', 'sponsoredAmbassadors'])
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
     * Approve a pending ambassador, setting the contract that makes them able to acquire customers.
     *
     * @throws DomainException when the ambassador is not pending approval
     */
    public function approveAmbassador(
        Ambassador $ambassador,
        User $approver,
        mixed $contractStartsAt,
        mixed $contractEndsAt,
    ): Ambassador {
        $this->assertStatus($ambassador, AmbassadorStatus::PendingApproval, 'validé');

        $ambassador->update([
            'status' => AmbassadorStatus::Active,
            'contract_starts_at' => $contractStartsAt,
            'contract_ends_at' => $contractEndsAt,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);

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
