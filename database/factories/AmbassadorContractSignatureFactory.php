<?php

namespace Database\Factories;

use App\Models\Ambassador;
use App\Models\AmbassadorContractSignature;
use App\Models\AmbassadorProgramDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AmbassadorContractSignature>
 */
class AmbassadorContractSignatureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contractContent = '# '.fake()->sentence(3);

        return [
            'ambassador_id' => Ambassador::factory(),
            'contract_document_id' => AmbassadorProgramDocument::factory()->contract(),
            'content_snapshot' => $contractContent,
            'content_sha256' => hash('sha256', $contractContent),
            'typed_full_name' => fake()->name(),
            'confirmed_phone_number' => '77'.fake()->numerify('#######'),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'signed_at' => now(),
        ];
    }
}
