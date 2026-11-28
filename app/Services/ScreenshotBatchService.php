<?php

namespace App\Services;

use App\Models\ScreenshotBatch;
use App\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Stores the screenshots field agents submit. A batch is one row; its images live in a private folder named
 * after the batch's uuid, so listing a batch is listing that folder and there is no per-image record.
 */
class ScreenshotBatchService
{
    public const DISK = 'local';

    public const MAXIMUM_IMAGES_PER_BATCH = 200;

    public function createBatch(User $submittingUser, ?string $note): ScreenshotBatch
    {
        return ScreenshotBatch::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $submittingUser->id,
            'note' => filled($note) ? trim($note) : null,
        ]);
    }

    /**
     * Adds images to a batch, never beyond the batch maximum.
     *
     * @param  list<UploadedFile>  $images
     * @return int the number of images stored
     *
     * @throws DomainException when the batch would go over its maximum
     */
    public function addImagesToBatch(ScreenshotBatch $batch, array $images): int
    {
        return DB::transaction(function () use ($batch, $images): int {
            $lockedBatch = ScreenshotBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($lockedBatch->file_count + count($images) > self::MAXIMUM_IMAGES_PER_BATCH) {
                throw new DomainException('Un lot ne peut pas contenir plus de '.self::MAXIMUM_IMAGES_PER_BATCH.' images.');
            }

            foreach ($images as $image) {
                $image->storeAs(
                    $this->folderOf($lockedBatch),
                    Str::orderedUuid().'.'.($image->guessExtension() ?? 'jpg'),
                    self::DISK,
                );
            }

            $lockedBatch->increment('file_count', count($images));
            $batch->file_count = $lockedBatch->file_count;

            return count($images);
        });
    }

    /**
     * @return list<string> the file names of the batch's images, in upload order
     */
    public function imageFileNames(ScreenshotBatch $batch): array
    {
        $disk = Storage::disk(self::DISK);

        return collect($disk->files($this->folderOf($batch)))
            ->sortBy([
                fn (string $firstPath, string $secondPath): int => $disk->lastModified($firstPath) <=> $disk->lastModified($secondPath),
                fn (string $firstPath, string $secondPath): int => basename($firstPath) <=> basename($secondPath),
            ])
            ->map(fn (string $path): string => basename($path))
            ->values()
            ->all();
    }

    /**
     * Packs every image of the batch into a temporary zip, the images being renamed capture-001, capture-002...
     * in upload order. The caller owns the returned file and must delete it once sent.
     *
     * @return string the absolute path of the zip file
     *
     * @throws RuntimeException when the zip cannot be created
     */
    public function createZipOfBatchImages(ScreenshotBatch $batch): string
    {
        $disk = Storage::disk(self::DISK);
        $zipFilePath = tempnam(sys_get_temp_dir(), 'screenshot-batch-');
        $zipArchive = new ZipArchive;

        if ($zipArchive->open($zipFilePath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le fichier zip.');
        }

        foreach ($this->imageFileNames($batch) as $imageNumber => $imageFileName) {
            $zipArchive->addFile(
                $disk->path($this->imagePath($batch, $imageFileName)),
                sprintf('capture-%03d.%s', $imageNumber + 1, pathinfo($imageFileName, PATHINFO_EXTENSION)),
            );
        }

        $zipArchive->close();

        return $zipFilePath;
    }

    public function imagePath(ScreenshotBatch $batch, string $fileName): string
    {
        return $this->folderOf($batch).'/'.basename($fileName);
    }

    private function folderOf(ScreenshotBatch $batch): string
    {
        return 'screenshot-batches/'.$batch->uuid;
    }
}
