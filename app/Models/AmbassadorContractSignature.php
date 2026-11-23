<?php

namespace App\Models;

use Database\Factories\AmbassadorContractSignatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that an ambassador accepted one version of the contract: the exact text they were shown, who they said
 * they were, and the phone number that confirmed it with an SMS code.
 */
class AmbassadorContractSignature extends Model
{
    /** @use HasFactory<AmbassadorContractSignatureFactory> */
    use HasFactory;

    protected $fillable = [
        'ambassador_id',
        'contract_document_id',
        'content_snapshot',
        'content_sha256',
        'typed_full_name',
        'confirmed_phone_number',
        'ip_address',
        'user_agent',
        'signed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(Ambassador::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AmbassadorProgramDocument::class, 'contract_document_id');
    }
}
