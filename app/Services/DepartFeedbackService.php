<?php

namespace App\Services;

use App\Enums\FeedbackCallOutcome;
use App\Models\Booking;
use App\Models\Depart;
use App\Models\DepartFeedback;
use App\Models\DepartFeedbackRating;
use App\Models\FeedbackCriterion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Business rules of the post-depart customer feedback calls: which customers must be called, how agents
 * share the queue without calling the same person twice, and how a call is recorded.
 */
class DepartFeedbackService
{
    public const RECORDING_DISK = 'local';

    public const RECORDING_DIRECTORY = 'depart-feedback-recordings';

    /**
     * Marks the depart as arrived and creates one pending feedback call per customer to phone.
     * Idempotent: a depart already marked arrived is left untouched and 0 is returned.
     *
     * @return int the number of feedback calls created
     */
    public function markDepartArrived(Depart $depart, User $markedBy): int
    {
        return DB::transaction(function () use ($depart, $markedBy): int {
            $lockedDepart = Depart::query()->lockForUpdate()->findOrFail($depart->id);

            if ($lockedDepart->hasArrived()) {
                return 0;
            }

            $lockedDepart->forceFill([
                'arrived_at' => now(),
                'arrived_marked_by' => $markedBy->id,
            ])->save();

            $createdFeedbackCount = 0;

            foreach ($this->bookingsToCall($lockedDepart) as $bookingToCall) {
                $feedback = DepartFeedback::query()->firstOrCreate(
                    ['booking_id' => $bookingToCall->id],
                    ['depart_id' => $lockedDepart->id, 'customer_id' => $bookingToCall->customer_id],
                );

                if ($feedback->wasRecentlyCreated) {
                    $createdFeedbackCount++;
                }
            }

            $depart->refresh();

            return $createdFeedbackCount;
        });
    }

    /**
     * One paid booking per customer to phone: a group is called once, through its main booker.
     *
     * @return Collection<int, Booking>
     */
    public function bookingsToCall(Depart $depart)
    {
        return $depart->bookings()
            ->whereNotNull('bookings.ticket_id')
            ->whereNotNull('bookings.customer_id')
            ->orderBy('bookings.id')
            ->get()
            ->groupBy(fn (Booking $booking): string => $booking->group_id !== null ? 'group-'.$booking->group_id : 'booking-'.$booking->id)
            ->map(fn ($groupBookings): Booking => $groupBookings->firstWhere('is_main_booking', true) ?? $groupBookings->first())
            ->values();
    }

    /**
     * The card the agent is already working on, if any: claimed by them, answered but not yet rated, or
     * simply opened and not yet closed.
     */
    public function cardInProgressFor(Depart $depart, User $agent): ?DepartFeedback
    {
        return DepartFeedback::query()
            ->where('depart_id', $depart->id)
            ->stillToCall()
            ->where('claimed_by_user_id', $agent->id)
            ->where('claimed_at', '>', now()->subMinutes(DepartFeedback::CLAIM_EXPIRY_MINUTES))
            ->orderBy('claimed_at')
            ->first();
    }

    /**
     * Claims the agent's card in progress, else the next customer never called. Returns null when nobody is left.
     */
    public function claimNextCard(Depart $depart, User $agent): ?DepartFeedback
    {
        $cardInProgress = $this->cardInProgressFor($depart, $agent);

        if ($cardInProgress !== null) {
            return $this->claim($cardInProgress, $agent);
        }

        return DB::transaction(function () use ($depart, $agent): ?DepartFeedback {
            $nextFeedback = DepartFeedback::query()
                ->where('depart_id', $depart->id)
                ->stillToCall()
                ->whereNull('outcome')
                ->where(function ($unclaimedQuery): void {
                    $unclaimedQuery->whereNull('claimed_by_user_id')
                        ->orWhere('claimed_at', '<=', now()->subMinutes(DepartFeedback::CLAIM_EXPIRY_MINUTES));
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            return $nextFeedback === null ? null : $this->claim($nextFeedback, $agent);
        });
    }

    /**
     * Moves on to another customer without calling this one: the card goes back to the queue and the agent
     * takes the next never-called customer after it (wrapping around). Returns null, keeping the card, when nobody else is left.
     *
     * @throws InvalidArgumentException when the card is finished, answered already or held by another agent
     */
    public function skipToNextCard(DepartFeedback $currentFeedback, User $agent): ?DepartFeedback
    {
        $this->assertHeldBy($currentFeedback, $agent);

        if ($currentFeedback->outcome !== null) {
            throw new InvalidArgumentException('Cet appel a déjà un résultat, enregistrez-le avant de passer au suivant.');
        }

        return DB::transaction(function () use ($currentFeedback, $agent): ?DepartFeedback {
            $nextFeedback = DepartFeedback::query()
                ->where('depart_id', $currentFeedback->depart_id)
                ->whereKeyNot($currentFeedback->id)
                ->stillToCall()
                ->whereNull('outcome')
                ->where(function ($unclaimedQuery): void {
                    $unclaimedQuery->whereNull('claimed_by_user_id')
                        ->orWhere('claimed_at', '<=', now()->subMinutes(DepartFeedback::CLAIM_EXPIRY_MINUTES));
                })
                ->orderByRaw('id > ? desc', [$currentFeedback->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($nextFeedback === null) {
                return null;
            }

            $this->release($currentFeedback);

            return $this->claim($nextFeedback, $agent);
        });
    }

    /**
     * Takes a specific card (typically from the "to call back" list). Refused when another agent holds it.
     *
     * @throws InvalidArgumentException when the card is finished or held by another agent
     */
    public function claimCard(DepartFeedback $feedback, User $agent): DepartFeedback
    {
        return DB::transaction(function () use ($feedback, $agent): DepartFeedback {
            $lockedFeedback = DepartFeedback::query()->lockForUpdate()->findOrFail($feedback->id);

            if ($lockedFeedback->isFinished()) {
                throw new InvalidArgumentException('Cet appel est déjà terminé.');
            }

            if ($lockedFeedback->isClaimedByAnotherAgent($agent->id)) {
                throw new InvalidArgumentException('Ce client est déjà pris en charge par un autre agent.');
            }

            return $this->claim($lockedFeedback, $agent);
        });
    }

    /**
     * Keeps the claim alive and returns the card.
     */
    public function claim(DepartFeedback $feedback, User $agent): DepartFeedback
    {
        $feedback->forceFill([
            'claimed_by_user_id' => $agent->id,
            'claimed_at' => now(),
        ])->save();

        return $feedback;
    }

    /**
     * Gives the card back to the queue.
     */
    public function release(DepartFeedback $feedback): void
    {
        $feedback->forceFill(['claimed_by_user_id' => null, 'claimed_at' => null])->save();
    }

    /**
     * The agent tapped "Appeler": remembers when the call started.
     */
    public function markCalled(DepartFeedback $feedback, User $agent): void
    {
        $this->assertHeldBy($feedback, $agent);

        $feedback->forceFill(['called_at' => now()])->save();
        $this->claim($feedback, $agent);
    }

    /**
     * Records how the call went. An unanswered or unreachable customer returns to the "to call back" list;
     * a refusal closes the call; "answered" leaves the card open until the ratings are saved.
     *
     * @throws InvalidArgumentException when the card is held by another agent or already finished
     */
    public function recordOutcome(DepartFeedback $feedback, User $agent, FeedbackCallOutcome $outcome): void
    {
        $this->assertHeldBy($feedback, $agent);

        $feedback->outcome = $outcome;
        $feedback->called_at ??= now();

        if ($outcome === FeedbackCallOutcome::Refused) {
            $feedback->completed_at = now();
        }

        $feedback->save();

        if ($outcome === FeedbackCallOutcome::Completed) {
            $this->claim($feedback, $agent);

            return;
        }

        $this->release($feedback);
    }

    /**
     * Saves one score straight away so an app switch during the call never loses it.
     *
     * @throws InvalidArgumentException on an invalid score, an inactive criterion or a card not answered
     */
    public function recordScore(DepartFeedback $feedback, User $agent, int $feedbackCriterionId, int $score): void
    {
        $this->assertHeldBy($feedback, $agent);

        if ($feedback->outcome !== FeedbackCallOutcome::Completed) {
            throw new InvalidArgumentException('Indiquez d\'abord que le client a répondu.');
        }

        if ($score < DepartFeedbackRating::MINIMUM_SCORE || $score > DepartFeedbackRating::MAXIMUM_SCORE) {
            throw new InvalidArgumentException('La note doit être comprise entre 1 et 5.');
        }

        $criterion = FeedbackCriterion::query()->active()->find($feedbackCriterionId);

        if ($criterion === null) {
            throw new InvalidArgumentException('Ce critère n\'existe plus.');
        }

        DepartFeedbackRating::query()->updateOrCreate(
            ['depart_feedback_id' => $feedback->id, 'feedback_criterion_id' => $criterion->id],
            ['score' => $score],
        );

        $this->claim($feedback, $agent);
    }

    /**
     * Closes an answered call once every active criterion is rated.
     *
     * @throws InvalidArgumentException when a criterion is still unrated
     */
    public function completeCall(DepartFeedback $feedback, User $agent, ?string $comment): void
    {
        $this->assertHeldBy($feedback, $agent);

        if ($feedback->outcome !== FeedbackCallOutcome::Completed) {
            throw new InvalidArgumentException('Indiquez d\'abord que le client a répondu.');
        }

        $ratedCriterionIds = $feedback->ratings()->pluck('feedback_criterion_id');
        $missingCriteriaCount = FeedbackCriterion::query()->active()->whereNotIn('id', $ratedCriterionIds)->count();

        if ($missingCriteriaCount > 0) {
            throw new InvalidArgumentException('Notez tous les critères avant d\'enregistrer.');
        }

        $feedback->forceFill([
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'completed_at' => now(),
        ])->save();

        $this->release($feedback);
    }

    /**
     * Stores the call recording on the private disk, replacing a previous one.
     */
    public function attachRecording(DepartFeedback $feedback, User $agent, UploadedFile $recording): void
    {
        $this->assertHeldBy($feedback, $agent, allowFinished: true);

        $previousRecordingPath = $feedback->recording_path;

        $newRecordingPath = $recording->store(self::RECORDING_DIRECTORY.'/'.$feedback->depart_id, self::RECORDING_DISK);

        $feedback->forceFill([
            'recording_path' => $newRecordingPath,
            'recording_uploaded_at' => now(),
        ])->save();

        if ($previousRecordingPath !== null) {
            Storage::disk(self::RECORDING_DISK)->delete($previousRecordingPath);
        }
    }

    /**
     * Guards every write of an agent: the card must not be finished (unless allowed, to retry a recording
     * upload) nor held by another agent.
     *
     * @throws InvalidArgumentException
     */
    private function assertHeldBy(DepartFeedback $feedback, User $agent, bool $allowFinished = false): void
    {
        $feedback->refresh();

        if (! $allowFinished && $feedback->isFinished()) {
            throw new InvalidArgumentException('Cet appel est déjà terminé.');
        }

        if ($feedback->isClaimedByAnotherAgent($agent->id)) {
            throw new InvalidArgumentException('Ce client est déjà pris en charge par un autre agent.');
        }
    }
}
