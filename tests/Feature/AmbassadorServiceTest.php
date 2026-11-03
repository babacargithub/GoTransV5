<?php

namespace Tests\Feature;

use App\Models\Ambassador;
use App\Services\AmbassadorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmbassadorServiceTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function blankCodes(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'only spaces' => ['   '],
        ];
    }

    #[DataProvider('blankCodes')]
    public function test_a_blank_code_resolves_to_no_ambassador(?string $blankCode): void
    {
        Ambassador::factory()->create();

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode($blankCode));
    }

    public function test_a_code_is_found_whatever_its_case_and_surrounding_spaces(): void
    {
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);

        $this->assertTrue(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('awa10')->is($ambassador));
        $this->assertTrue(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('Awa10')->is($ambassador));
        $this->assertTrue(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('  AWA10 ')->is($ambassador));
    }

    public function test_an_unknown_code_resolves_to_no_ambassador(): void
    {
        Ambassador::factory()->create(['code' => 'AWA10']);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('awa11'));
    }

    public function test_a_partial_code_does_not_match(): void
    {
        Ambassador::factory()->create(['code' => 'AWA10']);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('awa'));
        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('awa1'));
    }

    public function test_the_right_ambassador_is_returned_among_several(): void
    {
        Ambassador::factory()->create(['code' => 'AAA1']);
        $expected = Ambassador::factory()->create(['code' => 'BBB2']);
        Ambassador::factory()->create(['code' => 'CCC3']);

        $this->assertTrue(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('bbb2')->is($expected));
    }

    public function test_a_pending_approval_ambassador_code_is_not_accepted(): void
    {
        Ambassador::factory()->pendingApproval()->create(['code' => 'PEND1']);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('pend1'));
    }

    public function test_a_suspended_ambassador_code_is_not_accepted(): void
    {
        Ambassador::factory()->suspended()->create(['code' => 'SUSP1']);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('susp1'));
    }

    public function test_an_expired_contract_code_is_not_accepted(): void
    {
        Ambassador::factory()->withExpiredContract()->create(['code' => 'OLD1']);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('old1'));
    }

    public function test_a_contract_not_started_yet_code_is_not_accepted(): void
    {
        Ambassador::factory()->create(['code' => 'SOON1', 'contract_starts_at' => today()->addDay()]);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('soon1'));
    }

    public function test_a_contract_ending_today_code_is_still_accepted(): void
    {
        $ambassador = Ambassador::factory()->create(['code' => 'LAST1', 'contract_ends_at' => today()]);

        $this->assertTrue(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('last1')->is($ambassador));
    }

    public function test_a_contract_starting_today_code_is_accepted(): void
    {
        $ambassador = Ambassador::factory()->create(['code' => 'FIRST1', 'contract_starts_at' => today()]);

        $this->assertTrue(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('first1')->is($ambassador));
    }

    public function test_sql_wildcards_in_the_code_never_match_other_ambassadors(): void
    {
        Ambassador::factory()->create(['code' => 'AWA10']);

        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('%'));
        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode('AW_10'));
        $this->assertNull(app(AmbassadorService::class)->findAcquiringAmbassadorByCode("' OR '1'='1"));
    }
}
