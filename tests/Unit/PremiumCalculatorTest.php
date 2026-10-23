<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculator();
    }

    public function test_assignment_sample_premium_calculation_matches_exact_result(): void
    {
        // Example intake request from assignment prompt:
        // Customer born 1998-04-10, trip 2026-11-10 to 2026-11-14 (5 days)
        // Age on 2026-11-10 is 28 -> multiplier 1.00
        // Destination: INTERNATIONAL -> 1.80
        // Plan: PLUS -> base rate 20
        // Coverage: 300,000 -> 1.25
        // Expected: 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            sumInsured: 300000,
            destination: 'INTERNATIONAL',
            dob: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertEquals(5, $result['travel_days']);
        $this->assertEquals(28, $result['customer_age']);
        $this->assertEquals('20.00', $result['base_daily_rate']);
        $this->assertEquals('1.00', $result['age_multiplier']);
        $this->assertEquals('1.80', $result['destination_multiplier']);
        $this->assertEquals('1.25', $result['coverage_multiplier']);
        $this->assertEquals('225.00', $result['premium_amount']);
    }

    public function test_age_multiplier_boundaries(): void
    {
        // 0-17 bracket
        $this->assertEquals('1.10', $this->calculator->getAgeMultiplier(0));
        $this->assertEquals('1.10', $this->calculator->getAgeMultiplier(17));

        // 18-60 bracket
        $this->assertEquals('1.00', $this->calculator->getAgeMultiplier(18));
        $this->assertEquals('1.00', $this->calculator->getAgeMultiplier(35));
        $this->assertEquals('1.00', $this->calculator->getAgeMultiplier(60));

        // 61-70 bracket
        $this->assertEquals('1.50', $this->calculator->getAgeMultiplier(61));
        $this->assertEquals('1.50', $this->calculator->getAgeMultiplier(65));
        $this->assertEquals('1.50', $this->calculator->getAgeMultiplier(70));

        // 71+ bracket
        $this->assertEquals('2.00', $this->calculator->getAgeMultiplier(71));
        $this->assertEquals('2.00', $this->calculator->getAgeMultiplier(85));
    }

    public function test_inclusive_travel_days_calculation(): void
    {
        // Same day trip (inclusive = 1 day)
        $this->assertEquals(1, $this->calculator->calculateTravelDays('2026-05-01', '2026-05-01'));

        // 3-day trip
        $this->assertEquals(3, $this->calculator->calculateTravelDays('2026-05-01', '2026-05-03'));

        // 10-day trip
        $this->assertEquals(10, $this->calculator->calculateTravelDays('2026-05-01', '2026-05-10'));
    }

    public function test_all_base_rates_and_coverage_multipliers(): void
    {
        // BASIC plan (12), Domestic (1.00), 100k coverage (1.00), 18-60 age (1.00), 1 day
        $resBasic = $this->calculator->calculate('BASIC', 100000, 'DOMESTIC', '2000-01-01', '2026-01-01', '2026-01-01');
        $this->assertEquals('12.00', $resBasic['premium_amount']);

        // PLUS plan (20), Domestic (1.00), 100k coverage (1.00), 18-60 age (1.00), 1 day
        $resPlus = $this->calculator->calculate('PLUS', 100000, 'DOMESTIC', '2000-01-01', '2026-01-01', '2026-01-01');
        $this->assertEquals('20.00', $resPlus['premium_amount']);

        // PREMIUM plan (30), Domestic (1.00), 500k coverage (1.50), age 75 (2.00), 10 days
        // 30 * 10 * 2.00 * 1.00 * 1.50 = 900.00
        $resPrem = $this->calculator->calculate('PREMIUM', 500000, 'DOMESTIC', '1950-01-01', '2026-01-01', '2026-01-10');
        $this->assertEquals('900.00', $resPrem['premium_amount']);
    }

    public function test_rejects_invalid_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('GOLD_TIER', 100000, 'DOMESTIC', '2000-01-01', '2026-01-01', '2026-01-02');
    }

    public function test_rejects_invalid_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('BASIC', 250000, 'DOMESTIC', '2000-01-01', '2026-01-01', '2026-01-02');
    }

    public function test_rejects_invalid_destination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('BASIC', 100000, 'MARS', '2000-01-01', '2026-01-01', '2026-01-02');
    }

    public function test_rejects_trip_end_before_trip_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('BASIC', 100000, 'DOMESTIC', '2000-01-01', '2026-01-10', '2026-01-05');
    }

    public function test_rejects_dob_after_trip_start(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate('BASIC', 100000, 'DOMESTIC', '2026-05-01', '2026-01-01', '2026-01-05');
    }
}
