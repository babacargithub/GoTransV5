<?php

namespace App\Services;

use App\Enums\AmbassadorProgramDocumentType;
use App\Models\Ambassador;
use App\Models\AmbassadorContractSignature;
use App\Models\AmbassadorProgramDocument;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets an ambassador who has not signed yet sign the latest version of the contract online. The signature is confirmed by a 6-digit code
 * sent by SMS to the phone number of the ambassador, and stores the exact text signed with its fingerprint.
 * The code is kept hashed, expires quickly, and is limited in attempts and in resends.
 */
class AmbassadorContractSigningService
{
    public const CODE_VALIDITY_MINUTES = 10;

    public const MAXIMUM_WRONG_CODE_ATTEMPTS = 5;

    private const MAXIMUM_CODES_SENT_PER_HOUR = 5;

    private const SECONDS_BETWEEN_CODES = 60;

    public function __construct(private readonly NotificationService $notificationService) {}

    public function currentContract(): ?AmbassadorProgramDocument
    {
        return AmbassadorProgramDocument::current(AmbassadorProgramDocumentType::Contract);
    }

    /**
     * Texts are compared without accents, case and extra spaces: "  aMINATA diop " matches "Aminata Diop".
     */
    public function typedNameMatchesAmbassador(Ambassador $ambassador, string $typedFullName): bool
    {
        return $this->normalizeName($typedFullName) === $this->normalizeName($ambassador->full_name);
    }

    /**
     * Sends a new signing code to the ambassador, replacing any previous one.
     *
     * @throws DomainException when a code was sent less than a minute ago, too many were sent this hour, or the SMS failed
     */
    public function sendSigningCode(Ambassador $ambassador): void
    {
        $throttleKey = "ambassador-contract-code-sent:{$ambassador->id}";

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAXIMUM_CODES_SENT_PER_HOUR)) {
            throw new DomainException('Trop de codes demandés. Réessayez dans une heure.');
        }

        if (Cache::has($this->cooldownCacheKey($ambassador))) {
            throw new DomainException('Un code vient d\'être envoyé. Patientez une minute avant d\'en demander un autre.');
        }

        $signingCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $this->notificationService->notifyAmbassadorOfContractSigningCode($ambassador, $signingCode);
        } catch (Throwable $smsFailure) {
            report($smsFailure);

            throw new DomainException('Le SMS n\'a pas pu être envoyé. Réessayez dans un instant.');
        }

        RateLimiter::hit($throttleKey, 3600);
        Cache::put($this->cooldownCacheKey($ambassador), true, self::SECONDS_BETWEEN_CODES);
        Cache::put($this->codeCacheKey($ambassador), [
            'code_hash' => $this->hashCode($ambassador, $signingCode),
            'wrong_attempts' => 0,
        ], now()->addMinutes(self::CODE_VALIDITY_MINUTES));
    }

    /**
     * Signs the contract version the ambassador was reading. An ambassador signs once: the version they signed stays
     * theirs when a newer one is published.
     *
     * @throws DomainException when the ambassador already signed, the contract changed meanwhile, the typed name or the code is wrong, or the code expired
     * @throws Throwable when the signature could not be stored
     */
    public function sign(
        Ambassador $ambassador,
        int $readContractId,
        string $typedFullName,
        string $enteredCode,
        ?string $ipAddress,
        ?string $userAgent,
    ): AmbassadorContractSignature {
        if ($ambassador->hasSignedContract()) {
            throw new DomainException('Vous avez déjà signé votre contrat.');
        }

        $currentContract = $this->currentContract();

        if ($currentContract === null || $currentContract->id !== $readContractId) {
            throw new DomainException('Le contrat a été mis à jour. Relisez la nouvelle version avant de la signer.');
        }

        if (! $this->typedNameMatchesAmbassador($ambassador, $typedFullName)) {
            throw new DomainException('Le nom saisi ne correspond pas à votre nom complet.');
        }

        $this->consumeSigningCode($ambassador, $enteredCode);

        return DB::transaction(fn (): AmbassadorContractSignature => AmbassadorContractSignature::query()->firstOrCreate(
            [
                'ambassador_id' => $ambassador->id,
                'contract_document_id' => $currentContract->id,
            ],
            [
                'content_snapshot' => $currentContract->content,
                'content_sha256' => hash('sha256', $currentContract->content),
                'typed_full_name' => trim($typedFullName),
                'confirmed_phone_number' => $ambassador->phone_number,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
                'signed_at' => now(),
            ],
        ));
    }

    /**
     * Checks the code and burns it: a code works once, and five wrong tries kill it.
     *
     * @throws DomainException
     */
    private function consumeSigningCode(Ambassador $ambassador, string $enteredCode): void
    {
        $cacheKey = $this->codeCacheKey($ambassador);
        $pendingCode = Cache::get($cacheKey);

        if ($pendingCode === null) {
            throw new DomainException('Code expiré ou non demandé. Demandez un nouveau code.');
        }

        if (! hash_equals($pendingCode['code_hash'], $this->hashCode($ambassador, trim($enteredCode)))) {
            $pendingCode['wrong_attempts']++;

            if ($pendingCode['wrong_attempts'] >= self::MAXIMUM_WRONG_CODE_ATTEMPTS) {
                Cache::forget($cacheKey);

                throw new DomainException('Trop de tentatives. Demandez un nouveau code.');
            }

            Cache::put($cacheKey, $pendingCode, now()->addMinutes(self::CODE_VALIDITY_MINUTES));

            throw new DomainException('Code incorrect.');
        }

        Cache::forget($cacheKey);
    }

    private function hashCode(Ambassador $ambassador, string $code): string
    {
        return hash_hmac('sha256', "{$ambassador->id}:{$code}", (string) config('app.key'));
    }

    private function normalizeName(string $fullName): string
    {
        return (string) Str::of($fullName)->ascii()->lower()->replaceMatches('/\s+/', ' ')->trim();
    }

    private function codeCacheKey(Ambassador $ambassador): string
    {
        return "ambassador-contract-code:{$ambassador->id}";
    }

    private function cooldownCacheKey(Ambassador $ambassador): string
    {
        return "ambassador-contract-code-cooldown:{$ambassador->id}";
    }
}
