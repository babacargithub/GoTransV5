<?php

namespace App\Livewire\BackOffice;

use App\Enums\FeedbackCallOutcome;
use App\Enums\PermissionName;
use App\Models\Depart;
use App\Models\DepartFeedback;
use App\Models\FeedbackCriterion;
use App\Models\User;
use App\Services\DepartFeedbackService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Appels à passer": the phone-first screen agents use to call the customers of an arrived départ, one at a
 * time. Several agents share the queue; a card is claimed by the agent who opens it (see DepartFeedbackService).
 *
 * Everything is saved server-side as soon as it is tapped (outcome, each score) so switching to the phone app
 * and back never loses the call; the recording is a separate, optional step so a failed upload keeps the ratings.
 */
#[Layout('components.layouts.back-office')]
class DepartFeedbackCallQueue extends Component
{
    use WithFileUploads;

    public const TAB_TO_CALL = 'to-call';

    public const TAB_TO_CALL_BACK = 'to-call-back';

    public const TAB_FINISHED = 'finished';

    private const MAXIMUM_RECORDING_SIZE_KILOBYTES = 25600;

    private const MAXIMUM_LISTED_CARDS = 100;

    #[Url(as: 'depart')]
    public ?int $selectedDepartId = null;

    public string $activeTab = self::TAB_TO_CALL;

    public ?int $currentFeedbackId = null;

    /**
     * Score given to each criterion on the open card, keyed by criterion id.
     *
     * @var array<int, int>
     */
    public array $scores = [];

    public string $comment = '';

    public ?TemporaryUploadedFile $recordingUpload = null;

    public ?string $flashStatusMessage = null;

    public ?string $flashErrorMessage = null;

    public function mount(): void
    {
        PermissionName::ConductDepartFeedbackCalls->authorizeForCurrentUser();

        if ($this->selectedDepartId !== null && ! $this->arrivedDeparts->contains('id', $this->selectedDepartId)) {
            $this->selectedDepartId = null;
        }

        if ($this->selectedDepartId !== null) {
            $this->openNextCard();
        }
    }

    public function updatedSelectedDepartId(): void
    {
        $this->resetMessages();
        $this->activeTab = self::TAB_TO_CALL;
        $this->currentFeedbackId = null;

        if ($this->selectedDepartId === null || $this->selectedDepartId === 0) {
            $this->selectedDepartId = null;

            return;
        }

        $this->openNextCard();
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = in_array($tab, [self::TAB_TO_CALL, self::TAB_TO_CALL_BACK, self::TAB_FINISHED], true)
            ? $tab
            : self::TAB_TO_CALL;
    }

    /**
     * Opens the card the agent already holds, else the next customer never called.
     */
    public function openNextCard(): void
    {
        $this->resetMessages();
        $agent = $this->authorizedAgent();

        $depart = $this->selectedDepart();

        if ($depart === null) {
            return;
        }

        $nextFeedback = app(DepartFeedbackService::class)->claimNextCard($depart, $agent);

        $this->loadCard($nextFeedback);
    }

    /**
     * Takes a customer chosen from the lists (typically one to call back).
     */
    public function openCard(int $feedbackId): void
    {
        $this->resetMessages();
        $agent = $this->authorizedAgent();

        $feedback = DepartFeedback::query()
            ->where('depart_id', $this->selectedDepartId)
            ->findOrFail($feedbackId);

        try {
            $this->loadCard(app(DepartFeedbackService::class)->claimCard($feedback, $agent));
        } catch (InvalidArgumentException $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }

    /**
     * "Au suivant": skips the open customer for now and opens the next one still to call.
     */
    public function skipToNextCard(): void
    {
        $this->resetMessages();
        $agent = $this->authorizedAgent();
        $feedback = $this->currentFeedback;

        if ($feedback === null) {
            return;
        }

        try {
            $nextFeedback = app(DepartFeedbackService::class)->skipToNextCard($feedback, $agent);
        } catch (InvalidArgumentException $exception) {
            $this->flashErrorMessage = $exception->getMessage();
            unset($this->currentFeedback);

            return;
        }

        if ($nextFeedback === null) {
            $this->flashErrorMessage = 'Il n\'y a pas d\'autre client à appeler pour le moment.';

            return;
        }

        $this->loadCard($nextFeedback);
    }

    /**
     * The agent tapped "Appeler": remember when the call started.
     */
    public function startCall(): void
    {
        $this->runOnCurrentCard(
            fn (DepartFeedback $feedback, User $agent) => app(DepartFeedbackService::class)->markCalled($feedback, $agent),
        );
    }

    public function chooseOutcome(string $outcomeValue): void
    {
        $outcome = FeedbackCallOutcome::tryFrom($outcomeValue);

        if ($outcome === null) {
            return;
        }

        $outcomeSaved = $this->runOnCurrentCard(
            fn (DepartFeedback $feedback, User $agent) => app(DepartFeedbackService::class)->recordOutcome($feedback, $agent, $outcome),
        );

        if ($outcomeSaved && $outcome !== FeedbackCallOutcome::Completed) {
            $outcomeSavedMessage = $outcome->label().' : enregistré.';
            $this->openNextCard();
            $this->flashStatusMessage = $outcomeSavedMessage;
        }

        unset($this->currentFeedback);
    }

    public function setScore(int $feedbackCriterionId, int $score): void
    {
        $scoreSaved = $this->runOnCurrentCard(
            fn (DepartFeedback $feedback, User $agent) => app(DepartFeedbackService::class)->recordScore($feedback, $agent, $feedbackCriterionId, $score),
        );

        if ($scoreSaved) {
            $this->scores[$feedbackCriterionId] = $score;
        }
    }

    public function saveAndNext(): void
    {
        $this->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $commentToSave = $this->comment;

        $callCompleted = $this->runOnCurrentCard(
            fn (DepartFeedback $feedback, User $agent) => app(DepartFeedbackService::class)->completeCall($feedback, $agent, $commentToSave),
        );

        if ($callCompleted) {
            $this->openNextCard();
            $this->flashStatusMessage = 'Avis enregistré.';
        }
    }

    /**
     * Uploads the recording as soon as it is picked; the ratings are already saved, so a failure only asks to retry.
     */
    public function updatedRecordingUpload(): void
    {
        $this->resetMessages();

        $this->validate([
            'recordingUpload' => ['required', 'file', 'mimetypes:audio/*,video/3gpp,video/mp4,application/ogg', 'max:'.self::MAXIMUM_RECORDING_SIZE_KILOBYTES],
        ], [
            'recordingUpload.mimetypes' => 'Choisissez un fichier audio (mp3, m4a, wav, ogg, 3gp).',
            'recordingUpload.max' => 'L\'enregistrement ne doit pas dépasser 25 Mo.',
            'recordingUpload.uploaded' => 'L\'envoi de l\'enregistrement a échoué, réessayez.',
        ]);

        $recording = $this->recordingUpload;

        $recordingSaved = $this->runOnCurrentCard(
            fn (DepartFeedback $feedback, User $agent) => app(DepartFeedbackService::class)->attachRecording($feedback, $agent, $recording),
        );

        $this->recordingUpload = null;

        if ($recordingSaved) {
            $this->flashStatusMessage = 'Enregistrement envoyé.';
            unset($this->currentFeedback);
        }
    }

    /**
     * Departs marked arrived that still have customers to call.
     *
     * @return Collection<int, Depart>
     */
    #[Computed]
    public function arrivedDeparts(): Collection
    {
        return Depart::query()
            ->whereNotNull('arrived_at')
            ->whereHas('feedbacks', fn ($feedbackQuery) => $feedbackQuery->stillToCall())
            ->withCount([
                'feedbacks as total_calls_count',
                'feedbacks as finished_calls_count' => fn ($feedbackQuery) => $feedbackQuery->finished(),
            ])
            ->with('trajet')
            ->orderByDesc('arrived_at')
            ->limit(30)
            ->get();
    }

    /**
     * @return array{total: int, finished: int, percentage: int}
     */
    #[Computed]
    public function progress(): array
    {
        $total = DepartFeedback::query()->where('depart_id', $this->selectedDepartId)->count();
        $finished = DepartFeedback::query()->where('depart_id', $this->selectedDepartId)->finished()->count();

        return [
            'total' => $total,
            'finished' => $finished,
            'percentage' => $total === 0 ? 0 : (int) round($finished / $total * 100),
        ];
    }

    #[Computed]
    public function currentFeedback(): ?DepartFeedback
    {
        if ($this->currentFeedbackId === null) {
            return null;
        }

        return DepartFeedback::query()
            ->with(['customer', 'booking.seat', 'depart.trajet'])
            ->find($this->currentFeedbackId);
    }

    /**
     * @return Collection<int, FeedbackCriterion>
     */
    #[Computed]
    public function criteria(): Collection
    {
        return FeedbackCriterion::query()->active()->inDisplayOrder()->get();
    }

    /**
     * The cards of the open tab, other than the one being worked on.
     *
     * @return Collection<int, DepartFeedback>
     */
    #[Computed]
    public function listedCards(): Collection
    {
        if ($this->selectedDepartId === null) {
            return collect();
        }

        $cardsQuery = DepartFeedback::query()
            ->with(['customer', 'ratings'])
            ->where('depart_id', $this->selectedDepartId)
            ->when($this->currentFeedbackId !== null, fn ($query) => $query->whereKeyNot($this->currentFeedbackId));

        $cardsQuery = match ($this->activeTab) {
            self::TAB_TO_CALL_BACK => $cardsQuery->stillToCall()->whereIn('outcome', [FeedbackCallOutcome::NoAnswer->value, FeedbackCallOutcome::Unreachable->value])->orderBy('called_at'),
            self::TAB_FINISHED => $cardsQuery->finished()->orderByDesc('completed_at'),
            default => $cardsQuery->stillToCall()->whereNull('outcome')->orderBy('id'),
        };

        return $cardsQuery->limit(self::MAXIMUM_LISTED_CARDS)->get();
    }

    /**
     * @return array{toCall: int, toCallBack: int, finished: int}
     */
    #[Computed]
    public function tabCounts(): array
    {
        $departFeedbacks = fn () => DepartFeedback::query()->where('depart_id', $this->selectedDepartId);

        return [
            'toCall' => $departFeedbacks()->stillToCall()->whereNull('outcome')->count(),
            'toCallBack' => $departFeedbacks()->stillToCall()->whereIn('outcome', [FeedbackCallOutcome::NoAnswer->value, FeedbackCallOutcome::Unreachable->value])->count(),
            'finished' => $departFeedbacks()->finished()->count(),
        ];
    }

    public function allCriteriaRated(): bool
    {
        return $this->criteria->every(fn (FeedbackCriterion $criterion): bool => isset($this->scores[$criterion->id]));
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-feedback-call-queue')->title('Appels à passer');
    }

    private function selectedDepart(): ?Depart
    {
        return $this->selectedDepartId === null
            ? null
            : Depart::query()->whereNotNull('arrived_at')->find($this->selectedDepartId);
    }

    private function authorizedAgent(): User
    {
        PermissionName::ConductDepartFeedbackCalls->authorizeForCurrentUser();

        return auth()->user();
    }

    /**
     * Runs a write on the open card, turning the service's refusals into a flash message.
     *
     * @param  callable(DepartFeedback, User): mixed  $write
     * @return bool whether the write went through
     */
    private function runOnCurrentCard(callable $write): bool
    {
        $this->resetMessages();
        $agent = $this->authorizedAgent();
        $feedback = $this->currentFeedback;

        if ($feedback === null) {
            return false;
        }

        try {
            $write($feedback, $agent);
        } catch (InvalidArgumentException $exception) {
            $this->flashErrorMessage = $exception->getMessage();
            unset($this->currentFeedback);

            return false;
        }

        unset($this->currentFeedback);

        return true;
    }

    private function loadCard(?DepartFeedback $feedback): void
    {
        $this->currentFeedbackId = $feedback?->id;
        $this->scores = $feedback === null
            ? []
            : $feedback->ratings()->pluck('score', 'feedback_criterion_id')->map(fn ($score): int => (int) $score)->all();
        $this->comment = $feedback?->comment ?? '';
        $this->recordingUpload = null;

        unset($this->currentFeedback, $this->progress, $this->tabCounts, $this->listedCards, $this->arrivedDeparts);
    }

    private function resetMessages(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;
    }
}
