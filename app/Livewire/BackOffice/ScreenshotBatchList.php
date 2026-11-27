<?php

namespace App\Livewire\BackOffice;

use App\Models\ScreenshotBatch;
use App\Services\ScreenshotBatchService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * "Captures d'écran": where a field agent submits the screenshots of their groups' members and sees the batches
 * they already sent. An agent only ever sees their own batches.
 *
 * Up to 200 images cannot travel in one request (PHP caps files per request), so the browser creates the batch
 * first, then sends the images in small chunks, each chunk being attached to the batch as soon as it arrives.
 */
#[Layout('components.layouts.back-office')]
class ScreenshotBatchList extends Component
{
    use WithFileUploads;
    use WithPagination;

    private const MAXIMUM_IMAGE_SIZE_KILOBYTES = 10240;

    public string $note = '';

    /**
     * The chunk of images currently being attached to the batch.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $pendingImages = [];

    /**
     * Creates the batch the images of this submission will be attached to.
     *
     * @return array{batchUuid: ?string, error: ?string}
     */
    public function startBatch(ScreenshotBatchService $screenshotBatchService): array
    {
        try {
            $this->validate(['note' => ['required', 'string', 'max:150']], [
                'note.required' => 'Indiquez d\'où viennent les fichiers.',
                'note.max' => 'Le nom du lot ne doit pas dépasser 150 caractères.',
            ]);
        } catch (ValidationException $validationException) {
            return ['batchUuid' => null, 'error' => $validationException->validator->errors()->first()];
        }

        $batch = $screenshotBatchService->createBatch(auth()->user(), $this->note);

        return ['batchUuid' => $batch->uuid, 'error' => null];
    }

    /**
     * Moves the chunk of images just uploaded into the batch.
     *
     * @return array{storedCount: int, error: ?string}
     */
    public function attachPendingImages(string $batchUuid, ScreenshotBatchService $screenshotBatchService): array
    {
        $batch = ScreenshotBatch::query()
            ->where('uuid', $batchUuid)
            ->where('user_id', auth()->id())
            ->first();

        if ($batch === null) {
            return ['storedCount' => 0, 'error' => 'Lot introuvable.'];
        }

        try {
            $this->validate([
                'pendingImages' => ['required', 'array'],
                'pendingImages.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAXIMUM_IMAGE_SIZE_KILOBYTES],
            ], [
                'pendingImages.required' => 'Aucune image reçue.',
                'pendingImages.*.image' => 'Seules des images sont acceptées.',
                'pendingImages.*.mimes' => 'Formats acceptés : jpg, png, webp.',
                'pendingImages.*.max' => 'Chaque image ne doit pas dépasser 10 Mo.',
            ]);

            $storedCount = $screenshotBatchService->addImagesToBatch($batch, array_values($this->pendingImages));
        } catch (ValidationException $validationException) {
            return ['storedCount' => 0, 'error' => $validationException->validator->errors()->first()];
        } catch (DomainException $domainException) {
            return ['storedCount' => 0, 'error' => $domainException->getMessage()];
        } finally {
            $this->pendingImages = [];
        }

        return ['storedCount' => $storedCount, 'error' => null];
    }

    /**
     * Called once the whole submission is done, so the list shows the new batch.
     */
    public function finishSubmission(): void
    {
        $this->note = '';
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.back-office.screenshot-batch-list', [
            'batches' => ScreenshotBatch::query()
                ->where('user_id', auth()->id())
                ->latest()
                ->paginate(15),
            'maximumImagesPerBatch' => ScreenshotBatchService::MAXIMUM_IMAGES_PER_BATCH,
        ]);
    }
}
