<?php

namespace Database\Factories;

use App\Enums\AmbassadorProgramDocumentType;
use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorContractSignature;
use App\Models\AmbassadorProgramDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ambassador>
 */
class AmbassadorFactory extends Factory
{
    /**
     * Define the model's default state: an active ambassador with a contract running for a year.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone_number' => '77'.fake()->unique()->numerify('#######'),
            'code' => strtoupper(fake()->unique()->lexify('????')).fake()->numerify('##'),
            'status' => AmbassadorStatus::Active,
            'contract_starts_at' => today()->subMonth(),
            'contract_ends_at' => today()->addYear(),
        ];
    }

    /**
     * Active and suspended ambassadors have signed the current contract, as they would in production: tests of the
     * unsigned situation opt out with withoutSignedContract().
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Ambassador $ambassador): void {
            if (! in_array($ambassador->status, [AmbassadorStatus::Active, AmbassadorStatus::Suspended], true)) {
                return;
            }

            $currentContract = AmbassadorProgramDocument::current(AmbassadorProgramDocumentType::Contract)
                ?? AmbassadorProgramDocument::factory()->contract()->create();

            AmbassadorContractSignature::factory()->create([
                'ambassador_id' => $ambassador->id,
                'contract_document_id' => $currentContract->id,
                'content_snapshot' => $currentContract->content,
                'content_sha256' => hash('sha256', $currentContract->content),
                'typed_full_name' => $ambassador->full_name,
                'confirmed_phone_number' => $ambassador->phone_number,
            ]);
        });
    }

    public function withoutSignedContract(): static
    {
        return $this->afterCreating(fn (Ambassador $ambassador) => $ambassador->contractSignatures()->delete());
    }

    public function invited(): static
    {
        return $this->state(fn (): array => [
            'status' => AmbassadorStatus::Invited,
            'invitation_token' => Str::random(40),
            'contract_starts_at' => null,
            'contract_ends_at' => null,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (): array => [
            'status' => AmbassadorStatus::PendingApproval,
            'contract_starts_at' => null,
            'contract_ends_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AmbassadorStatus::Suspended]);
    }

    public function withExpiredContract(): static
    {
        return $this->state(fn (): array => [
            'contract_starts_at' => today()->subYear(),
            'contract_ends_at' => today()->subDay(),
        ]);
    }

    public function sponsoredBy(Ambassador $sponsor): static
    {
        return $this->state(fn (): array => ['sponsor_ambassador_id' => $sponsor->id]);
    }
}
