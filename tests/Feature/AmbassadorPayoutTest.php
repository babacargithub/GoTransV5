<?php

namespace Tests\Feature;

use App\Enums\AmbassadorPayoutStatus;
use App\Models\AmbassadorPayout;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmbassadorPayoutTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function payoutStatesProvider(): array
    {
        return [
            'failed with attempts left' => [['status' => AmbassadorPayoutStatus::Failed, 'attempts_count' => 1], true],
            'failed past the maximum attempts' => [['status' => AmbassadorPayoutStatus::Failed, 'attempts_count' => AmbassadorPayout::MAX_ATTEMPTS], false],
            'pending never sent' => [['status' => AmbassadorPayoutStatus::Pending, 'provider_payout_id' => null], true],
            'pending accepted by wave' => [['status' => AmbassadorPayoutStatus::Pending, 'provider_payout_id' => 'pt-1'], false],
            'pending never sent past the maximum attempts' => [['status' => AmbassadorPayoutStatus::Pending, 'provider_payout_id' => null, 'attempts_count' => AmbassadorPayout::MAX_ATTEMPTS], false],
            'succeeded' => [['status' => AmbassadorPayoutStatus::Success], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $payoutAttributes
     */
    #[DataProvider('payoutStatesProvider')]
    public function test_can_be_sent_depends_on_status_attempts_and_wave_acceptance(array $payoutAttributes, bool $expectedCanBeSent): void
    {
        $payout = new AmbassadorPayout($payoutAttributes + ['attempts_count' => 0]);

        $this->assertSame($expectedCanBeSent, $payout->canBeSent());
    }
}
