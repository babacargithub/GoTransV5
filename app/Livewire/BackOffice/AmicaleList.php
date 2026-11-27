<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Models\Amicale;
use App\Models\Trajet;
use App\Services\AmicaleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Amicales" list: the partner student associations, their subdomain, commission per ticket and
 * the account their managers log in with. All rules live in {@see AmicaleService}; this component only checks
 * permissions and surfaces the outcome.
 */
#[Layout('components.layouts.back-office')]
class AmicaleList extends Component
{
    public bool $showAmicaleModal = false;

    public string $amicaleName = '';

    public string $amicaleSlug = '';

    public ?string $welcomeMessage = null;

    public int|string|null $commissionPerTicket = null;

    /** @var array<int, int|string> */
    public array $servedTrajetIds = [];

    public bool $showTrajetsModal = false;

    public ?int $editingTrajetsAmicaleId = null;

    public string $accountUsername = '';

    public string $accountPassword = '';

    public function mount(): void
    {
        PermissionName::ManageAmicales->authorizeForCurrentUser();
    }

    /**
     * @return Collection<int, Amicale>
     */
    #[Computed]
    public function amicales(): Collection
    {
        return Amicale::query()
            ->with('trajets')
            ->withCount(['bookings as paid_bookings_count' => fn ($bookings) => $bookings->where('paye', true)])
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, Trajet>
     */
    #[Computed]
    public function trajetOptions(): Collection
    {
        return Trajet::query()->publiclyVisible()->get();
    }

    public function openEditTrajets(int $amicaleId): void
    {
        if (! $this->allowed(PermissionName::ManageAmicales)) {
            return;
        }

        $this->editingTrajetsAmicaleId = $amicaleId;
        $this->showTrajetsModal = true;
        $this->servedTrajetIds = Amicale::findOrFail($amicaleId)->trajets()->pluck('trajets.id')->map(fn ($id) => (string) $id)->all();
    }

    public function closeEditTrajets(): void
    {
        $this->showTrajetsModal = false;
        $this->editingTrajetsAmicaleId = null;
        $this->servedTrajetIds = [];
    }

    public function saveServedTrajets(AmicaleService $amicaleService): void
    {
        if (! $this->allowed(PermissionName::ManageAmicales) || $this->editingTrajetsAmicaleId === null) {
            return;
        }

        $validated = $this->validate([
            'servedTrajetIds' => ['array'],
            'servedTrajetIds.*' => ['integer', Rule::exists('trajets', 'id')],
        ]);

        $amicale = Amicale::findOrFail($this->editingTrajetsAmicaleId);
        $amicaleService->syncServedTrajets($amicale, array_map('intval', $validated['servedTrajetIds']));

        $this->closeEditTrajets();
        session()->flash('status', "Les trajets de « {$amicale->name} » ont été mis à jour.");
    }

    public function openCreateAmicale(): void
    {
        if (! $this->allowed(PermissionName::ManageAmicales)) {
            return;
        }

        $this->resetAmicaleForm();
        $this->showAmicaleModal = true;
    }

    public function closeAmicaleModal(): void
    {
        $this->showAmicaleModal = false;
        $this->resetAmicaleForm();
    }

    public function saveAmicale(AmicaleService $amicaleService): void
    {
        if (! $this->allowed(PermissionName::ManageAmicales)) {
            return;
        }

        $this->amicaleSlug = mb_strtolower(trim($this->amicaleSlug));

        $validated = $this->validate([
            'amicaleName' => ['required', 'string', 'max:150'],
            'amicaleSlug' => [
                'required', 'string', 'max:63', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(Amicale::RESERVED_SLUGS),
                Rule::unique('amicales', 'slug'),
            ],
            'welcomeMessage' => ['nullable', 'string', 'max:500'],
            'commissionPerTicket' => ['required', 'integer', 'min:0', 'max:100000'],
            'accountUsername' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'accountPassword' => ['required', 'string', 'min:8', 'max:255'],
            'servedTrajetIds' => ['array'],
            'servedTrajetIds.*' => ['integer', Rule::exists('trajets', 'id')],
        ], attributes: [
            'amicaleName' => 'nom',
            'amicaleSlug' => 'sous-domaine',
            'welcomeMessage' => 'message de bienvenue',
            'commissionPerTicket' => 'commission par billet',
            'accountUsername' => 'identifiant',
            'accountPassword' => 'mot de passe',
        ]);

        $amicale = $amicaleService->createAmicaleWithAdminAccount(
            [
                'name' => $validated['amicaleName'],
                'slug' => $validated['amicaleSlug'],
                'welcome_message' => filled($validated['welcomeMessage']) ? $validated['welcomeMessage'] : null,
                'commission_per_ticket' => (int) $validated['commissionPerTicket'],
            ],
            ['username' => $validated['accountUsername'], 'password' => $validated['accountPassword']],
            array_map('intval', $validated['servedTrajetIds']),
        );

        $this->closeAmicaleModal();
        session()->flash('status', "L'amicale « {$amicale->name} » a été créée avec son compte.");
    }

    public function toggleAmicaleActive(int $amicaleId, AmicaleService $amicaleService): void
    {
        if (! $this->allowed(PermissionName::ManageAmicales)) {
            return;
        }

        $amicale = Amicale::findOrFail($amicaleId);
        $amicaleService->setActive($amicale, ! $amicale->is_active);

        session()->flash('status', $amicale->is_active
            ? "L'amicale « {$amicale->name} » est activée."
            : "L'amicale « {$amicale->name} » est désactivée.");
    }

    public function render(): View
    {
        return view('livewire.back-office.amicale-list')->title('Amicales — Globe One Transport');
    }

    private function allowed(PermissionName $permission): bool
    {
        if ($permission->allowedForCurrentUser()) {
            return true;
        }

        session()->flash('error', $permission->unauthorizedMessage());

        return false;
    }

    private function resetAmicaleForm(): void
    {
        $this->reset(['amicaleName', 'amicaleSlug', 'welcomeMessage', 'commissionPerTicket', 'accountUsername', 'accountPassword', 'servedTrajetIds']);
        $this->resetValidation();
    }
}
