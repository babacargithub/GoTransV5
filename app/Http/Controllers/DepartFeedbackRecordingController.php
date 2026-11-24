<?php

namespace App\Http\Controllers;

use App\Enums\PermissionName;
use App\Models\DepartFeedback;
use App\Services\DepartFeedbackService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams a feedback call recording. Recordings are private customer audio, so they live on the local disk and
 * are only reachable here, by users allowed to listen; each listen is logged on the feedback row.
 */
class DepartFeedbackRecordingController extends Controller
{
    public function show(DepartFeedback $departFeedback): BinaryFileResponse
    {
        PermissionName::ListenDepartFeedbackRecordings->authorizeForCurrentUser();

        $recordingDisk = Storage::disk(DepartFeedbackService::RECORDING_DISK);

        abort_if($departFeedback->recording_path === null || ! $recordingDisk->exists($departFeedback->recording_path), 404);

        $departFeedback->forceFill([
            'recording_last_listened_at' => now(),
            'recording_last_listened_by' => auth()->id(),
        ])->save();

        return response()->file($recordingDisk->path($departFeedback->recording_path));
    }
}
