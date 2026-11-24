<?php

namespace App\Livewire\BackOffice;

use App\Enums\FeedbackCallOutcome;
use App\Enums\PermissionName;
use App\Models\Depart;
use App\Models\DepartFeedback;
use App\Models\DepartFeedbackRating;
use App\Models\FeedbackCriterion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Avis clients": for one arrived départ, the average score of each criterion, how many customers answered,
 * every call with its recording, and a written "what to improve" conclusion (display only).
 */
#[Layout('components.layouts.back-office')]
class DepartFeedbackReport extends Component
{
    #[Url(as: 'depart')]
    public ?int $selectedDepartId = null;

    public string $conclusion = '';

    public ?string $flashStatusMessage = null;

    public function mount(): void
    {
        PermissionName::ViewDepartFeedback->authorizeForCurrentUser();

        $this->selectedDepartId ??= $this->reportableDeparts->first()?->id;
        $this->loadConclusion();
    }

    public function updatedSelectedDepartId(): void
    {
        $this->flashStatusMessage = null;
        $this->loadConclusion();
    }

    public function saveConclusion(): void
    {
        PermissionName::ViewDepartFeedback->authorizeForCurrentUser();

        $this->validate(['conclusion' => ['nullable', 'string', 'max:5000']]);

        $depart = $this->selectedDepart();

        if ($depart === null) {
            return;
        }

        $depart->forceFill(['feedback_conclusion' => trim($this->conclusion) === '' ? null : trim($this->conclusion)])->save();
        $this->flashStatusMessage = 'Conclusion enregistrée.';
    }

    /**
     * Départs that have feedback calls, most recently arrived first.
     *
     * @return Collection<int, Depart>
     */
    #[Computed]
    public function reportableDeparts(): Collection
    {
        return Depart::query()
            ->whereNotNull('arrived_at')
            ->has('feedbacks')
            ->with('trajet')
            ->orderByDesc('arrived_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return array{totalCalls: int, answered: int, refused: int, noAnswer: int, unreachable: int, notCalled: int, responseRate: int}
     */
    #[Computed]
    public function callSummary(): array
    {
        $callsCountByOutcome = DepartFeedback::query()
            ->where('depart_id', $this->selectedDepartId)
            ->selectRaw('outcome, count(*) as calls_count')
            ->groupBy('outcome')
            ->pluck('calls_count', 'outcome');

        $countFor = fn (?FeedbackCallOutcome $outcome): int => (int) ($callsCountByOutcome[$outcome?->value ?? ''] ?? 0);

        $totalCalls = (int) $callsCountByOutcome->sum();
        $answered = DepartFeedback::query()->where('depart_id', $this->selectedDepartId)->finished()->where('outcome', FeedbackCallOutcome::Completed->value)->count();

        return [
            'totalCalls' => $totalCalls,
            'answered' => $answered,
            'refused' => $countFor(FeedbackCallOutcome::Refused),
            'noAnswer' => $countFor(FeedbackCallOutcome::NoAnswer),
            'unreachable' => $countFor(FeedbackCallOutcome::Unreachable),
            'notCalled' => $countFor(null),
            'responseRate' => $totalCalls === 0 ? 0 : (int) round($answered / $totalCalls * 100),
        ];
    }

    /**
     * Average score of each criterion over the answered calls of the départ.
     *
     * @return Collection<int, array{label: string, average: float, ratingsCount: int}>
     */
    #[Computed]
    public function criterionAverages(): Collection
    {
        $averageRows = DepartFeedbackRating::query()
            ->join('depart_feedbacks', 'depart_feedbacks.id', '=', 'depart_feedback_ratings.depart_feedback_id')
            ->where('depart_feedbacks.depart_id', $this->selectedDepartId)
            ->whereNotNull('depart_feedbacks.completed_at')
            ->selectRaw('feedback_criterion_id, avg(score) as average_score, count(*) as ratings_count')
            ->groupBy('feedback_criterion_id')
            ->get()
            ->keyBy('feedback_criterion_id');

        return FeedbackCriterion::query()->inDisplayOrder()->get()
            ->filter(fn (FeedbackCriterion $criterion): bool => $averageRows->has($criterion->id))
            ->map(fn (FeedbackCriterion $criterion): array => [
                'label' => $criterion->label,
                'average' => round((float) $averageRows[$criterion->id]->average_score, 1),
                'ratingsCount' => (int) $averageRows[$criterion->id]->ratings_count,
            ])
            ->values();
    }

    /**
     * @return Collection<int, DepartFeedback>
     */
    #[Computed]
    public function calls(): Collection
    {
        return DepartFeedback::query()
            ->with(['customer', 'ratings.criterion', 'claimedBy'])
            ->where('depart_id', $this->selectedDepartId)
            ->whereNotNull('outcome')
            ->orderByDesc('completed_at')
            ->orderByDesc('called_at')
            ->limit(300)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-feedback-report')->title('Avis clients');
    }

    private function selectedDepart(): ?Depart
    {
        return $this->selectedDepartId === null ? null : Depart::query()->whereNotNull('arrived_at')->find($this->selectedDepartId);
    }

    private function loadConclusion(): void
    {
        $this->conclusion = $this->selectedDepart()?->feedback_conclusion ?? '';
    }
}
