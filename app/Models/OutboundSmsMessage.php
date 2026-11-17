<?php

namespace App\Models;

use App\Enums\OutboundSmsMessageStatus;
use Database\Factories\OutboundSmsMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The trace of one SMS the app sends through Orange. It is stored as Pending before Orange is called, then
 * marked Sent, Failed or Skipped. Its id is meant to be the callback data Orange echoes in delivery receipts.
 */
class OutboundSmsMessage extends Model
{
    /** @use HasFactory<OutboundSmsMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'recipient_phone_number',
        'content',
        'status',
        'provider_resource_url',
        'failure_reason',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutboundSmsMessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function markAsSent(?string $providerResourceUrl): void
    {
        $this->update([
            'status' => OutboundSmsMessageStatus::Sent,
            'provider_resource_url' => $providerResourceUrl,
            'sent_at' => now(),
        ]);
    }

    public function markAsFailed(string $failureReason): void
    {
        $this->update([
            'status' => OutboundSmsMessageStatus::Failed,
            'failure_reason' => $failureReason,
        ]);
    }

    public function markAsSkipped(): void
    {
        $this->update(['status' => OutboundSmsMessageStatus::Skipped]);
    }
}
