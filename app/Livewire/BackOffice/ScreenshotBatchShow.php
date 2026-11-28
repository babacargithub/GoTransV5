<?php

namespace App\Livewire\BackOffice;

use App\Models\ScreenshotBatch;
use App\Services\ScreenshotBatchService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One batch of screenshots with all its images. Only the agent who submitted it, or a full-access user, can open it.
 */
#[Layout('components.layouts.back-office')]
class ScreenshotBatchShow extends Component
{
    public ScreenshotBatch $screenshotBatch;

    public function mount(ScreenshotBatch $screenshotBatch): void
    {
        abort_unless($screenshotBatch->isViewableBy(auth()->user()), 404);

        $this->screenshotBatch = $screenshotBatch;
    }

    public function render(ScreenshotBatchService $screenshotBatchService): View
    {
        return view('livewire.back-office.screenshot-batch-show', [
            'imageFileNames' => $screenshotBatchService->imageFileNames($this->screenshotBatch),
        ]);
    }
}
