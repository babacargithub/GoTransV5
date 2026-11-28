<?php

namespace App\Http\Controllers;

use App\Models\ScreenshotBatch;
use App\Services\ScreenshotBatchService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams one screenshot of a batch. Screenshots are private (they show group members' names and numbers), so
 * they are only reachable here, by the agent who submitted the batch.
 */
class ScreenshotBatchImageController extends Controller
{
    public function download(ScreenshotBatch $screenshotBatch, ScreenshotBatchService $screenshotBatchService): BinaryFileResponse
    {
        abort_unless($screenshotBatch->isViewableBy(auth()->user()), 404);
        abort_if($screenshotBatch->file_count === 0, 404);

        $zipFilePath = $screenshotBatchService->createZipOfBatchImages($screenshotBatch);

        return response()
            ->download($zipFilePath, Str::slug($screenshotBatch->note ?? 'captures-ecran').'.zip')
            ->deleteFileAfterSend();
    }

    public function show(ScreenshotBatch $screenshotBatch, string $fileName, ScreenshotBatchService $screenshotBatchService): BinaryFileResponse
    {
        abort_unless($screenshotBatch->isViewableBy(auth()->user()), 404);

        $imagePath = $screenshotBatchService->imagePath($screenshotBatch, $fileName);
        $disk = Storage::disk(ScreenshotBatchService::DISK);

        abort_unless($disk->exists($imagePath), 404);

        return response()->file($disk->path($imagePath));
    }
}
