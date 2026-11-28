<?php

namespace App\Models;

use App\Enums\ScreenshotBatchStatus;
use Database\Factories\ScreenshotBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A batch of screenshots (group members' lists) a field agent submitted at once. The images are not records:
 * they sit in one private folder named after the batch's uuid (see ScreenshotBatchService).
 */
class ScreenshotBatch extends Model
{
    /** @use HasFactory<ScreenshotBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'note',
        'status',
        'file_count',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Whether the user may open the batch and its images: the agent who submitted it, or a full-access user.
     */
    public function isViewableBy(User $user): bool
    {
        return $this->isOwnedBy($user) || $user->hasFullAccess();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScreenshotBatchStatus::class,
        ];
    }
}
