<?php

namespace Tests\Feature;

use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrajetControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'username' => 'test_user_' . uniqid(),
            'active' => true,
        ]));
    }

    private function validTrajetPayload(array $overrides = []): array
    {
        $suffix = uniqid();

        return array_merge([
            'name' => "Test Trajet $suffix",
            'start_point' => 'Start Point',
            'end_point' => 'End Point',
            'point_departs' => [
                [
                    'name' => 'Point Dep 1',
                    'heure_point_dep' => '07:00',
                    'heure_point_dep_soir' => '14:00',
                ],
            ],
            'destinations' => [
                ['name' => 'Destination 1'],
            ],
            'horaires' => [
                ['name' => 'Matin', 'bus_leave_time' => '07:00'],
            ],
        ], $overrides);
    }

    public function test_creating_a_trajet_persists_public_name_departure_city_arrival_city_code_and_length(): void
    {
        $this->actingAsUser();

        $suffix = uniqid();
        $payload = $this->validTrajetPayload([
            'public_name' => 'Dakar - Saint-Louis Express',
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
            'code' => "TEST-$suffix",
            'length' => 264.5,
        ]);

        $response = $this->postJson('/api/trajets', $payload);

        $response->assertStatus(201);

        $trajet = Trajet::where('name', $payload['name'])->first();
        $this->assertNotNull($trajet);
        $this->assertSame('Dakar - Saint-Louis Express', $trajet->public_name);
        $this->assertSame('DAKAR', $trajet->departure_city);
        $this->assertSame('SAINT-LOUIS', $trajet->arrival_city);
        $this->assertSame("TEST-$suffix", $trajet->code);
        $this->assertEquals(264.5, $trajet->length);
    }

    public function test_creating_a_trajet_without_the_optional_fields_still_works(): void
    {
        $this->actingAsUser();

        $payload = $this->validTrajetPayload();

        $response = $this->postJson('/api/trajets', $payload);

        $response->assertStatus(201);

        $trajet = Trajet::where('name', $payload['name'])->first();
        $this->assertNotNull($trajet);
        $this->assertNull($trajet->public_name);
        $this->assertNull($trajet->departure_city);
        $this->assertNull($trajet->arrival_city);
        $this->assertNull($trajet->code);
        $this->assertNull($trajet->length);
    }
}
