<?php

namespace App\Enums;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The catalogue of application permissions.
 *
 * The backing string is the stable internal constant every guard checks against
 * (never edited from the UI). {@see self::defaultLabel()} is only the seed value
 * for the editable French `permissions.label` column managers see in the UI.
 *
 * `FullAccess` is the super-admin permission: a user holding it passes every
 * permission check (wired through a `Gate::before` hook), so guards never need to
 * name it explicitly.
 */
enum PermissionName: string
{
    case FullAccess = 'full-access';
    case CreateDeparts = 'create-departs';
    case UpdateDeparts = 'update-departs';
    case CancelDeparts = 'cancel-departs';
    case CloseDepart = 'close-depart';
    case FreezeDepart = 'freeze-depart';
    case UnfreezeDepart = 'unfreeze-depart';
    case TransferBooking = 'transfer-booking';
    case TransferPastBooking = 'transfer-past-booking';
    case CancelUnpaidBooking = 'cancel-unpaid-booking';
    case CancelPaidBooking = 'cancel-paid-booking';
    case RefundTicket = 'refund-ticket';
    case UpdateCustomerInfo = 'update-customer-info';
    case CreateCustomer = 'create-customer';
    case DeleteCustomer = 'delete-customer';
    case CreateBus = 'create-bus';
    case CloseBus = 'close-bus';
    case UncloseBus = 'unclose-bus';
    case EditBus = 'edit-bus';
    case DeleteBus = 'delete-bus';
    case UpdateSchedules = 'update-schedules';
    case SendMessages = 'send-messages';
    case ManageCaisse = 'manage-caisse';
    case ManageAccounts = 'manage-accounts';

    /**
     * Transferring a booking away from a departed trip loses the company money,
     * so it needs the higher "past" privilege; an upcoming trip needs only the basic one.
     */
    public static function forBookingTransfer(bool $isDepartPassed): self
    {
        return $isDepartPassed ? self::TransferPastBooking : self::TransferBooking;
    }

    /**
     * The "past" transfer permission is the higher privilege, so it also covers upcoming trips.
     */
    public static function isBookingTransferAllowedForCurrentUser(bool $isDepartPassed): bool
    {
        return self::forBookingTransfer($isDepartPassed)->allowedForCurrentUser()
            || self::TransferPastBooking->allowedForCurrentUser();
    }

    public static function authorizeBookingTransferForCurrentUser(bool $isDepartPassed): void
    {
        if (! self::isBookingTransferAllowedForCurrentUser($isDepartPassed)) {
            self::forBookingTransfer($isDepartPassed)->authorizeForCurrentUser();
        }
    }

    /**
     * The French label seeded into the editable `permissions.label` column.
     */
    public function defaultLabel(): string
    {
        return match ($this) {
            self::FullAccess => 'Accès total (super-administrateur)',
            self::CreateDeparts => 'Créer des départs',
            self::UpdateDeparts => 'Modifier des départs',
            self::CancelDeparts => 'Annuler des départs',
            self::CloseDepart => 'Clôturer un départ',
            self::FreezeDepart => 'Geler un départ',
            self::UnfreezeDepart => 'Dégeler un départ',
            self::TransferBooking => 'Transférer réservation',
            self::TransferPastBooking => 'Transférer réservation passée',
            self::CancelUnpaidBooking => 'Annuler une réservation non payée',
            self::CancelPaidBooking => 'Annuler une réservation payée',
            self::RefundTicket => 'Rembourser un billet',
            self::UpdateCustomerInfo => 'Modifier les informations d\'un client',
            self::CreateCustomer => 'Créer un client',
            self::DeleteCustomer => 'Supprimer un client',
            self::CreateBus => 'Créer un bus',
            self::CloseBus => 'Clôturer un bus',
            self::UncloseBus => 'Réouvrir un bus',
            self::EditBus => 'Modifier un bus',
            self::DeleteBus => 'Supprimer un bus',
            self::UpdateSchedules => 'Modifier les horaires bus / départ',
            self::SendMessages => 'Envoyer des messages',
            self::ManageCaisse => 'Gérer les caisses',
            self::ManageAccounts => 'Gérer les comptes',
        };
    }

    /**
     * Every permission name as its backing string.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }

    /**
     * Whether the currently authenticated user may perform this action.
     *
     * Goes through the Gate, so a user holding {@see self::FullAccess} passes
     * every check (see App\Providers\AuthServiceProvider).
     */
    public function allowedForCurrentUser(): bool
    {
        return auth()->check() && auth()->user()->can($this->value);
    }

    /**
     * Assert the currently authenticated user may perform this action, aborting
     * with a 403 otherwise. Used to guard sensitive controller and Livewire
     * actions with an implicit "OR full-access".
     *
     * @throws AuthorizationException
     */
    public function authorizeForCurrentUser(): void
    {
        if (! $this->allowedForCurrentUser()) {
            throw new AuthorizationException(
                'Action non autorisée : la permission « '.$this->defaultLabel().' » est requise.'
            );
        }
    }
}
