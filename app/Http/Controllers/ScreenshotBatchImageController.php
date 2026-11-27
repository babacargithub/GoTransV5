<?php

namespace App\Http\Controllers;

use App\Models\ScreenshotBatch;
use App\Services\ScreenshotBatchService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams one screenshot of a batch. Screenshots are private (they show group members' names and numbers), so
 * they are only reachable here, by the agent who submitted the batch.
 */
class ScreenshotBatchImageController extends Controller
{
    public function show(ScreenshotBatch $screenshotBatch, string $fileName, ScreenshotBatchService $screenshotBatchService): BinaryFileResponse
    {
        abort_unless($screenshotBatch->isOwnedBy(auth()->user()), 404);

        $imagePath = $screenshotBatchService->imagePath($screenshotBatch, $fileName);
        $disk = Storage::disk(ScreenshotBatchService::DISK);

        abort_unless($disk->exists($imagePath), 404);

        return response()->file($disk->path($imagePath));
    }
}
