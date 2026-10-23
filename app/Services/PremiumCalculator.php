<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculator
{
    public const BASE_RATES = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    public const COVERAGE_MULTIPLIERS = [
        '100000' => '1.00',
        '100000.00' => '1.00',
        '300000' => '1.25',
        '300000.00' => '1.25',
        '500000' => '1.50',
        '500000.00' => '1.50',
    ];

    /**
     * Calculate age as of the trip start date.
     */
    public function calculateAge(string|Carbon $dob, string|Carbon $tripStart): int
    {
        $dobDate = $dob instanceof Carbon ? $dob->copy()->startOfDay() : Carbon::parse($dob)->startOfDay();
        $tripStartDate = $tripStart instanceof Carbon ? $tripStart->copy()->startOfDay() : Carbon::parse($tripStart)->startOfDay();

        if ($dobDate->isAfter($tripStartDate)) {
            throw new InvalidArgumentException("Date of birth cannot be after trip start date.");
        }

        return (int) $dobDate->diffInYears($tripStartDate);
    }

    /**
     * Determine age multiplier based on age brackets:
     * - 0–17: 1.10
     * - 18–60: 1.00
     * - 61–70: 1.50
     * - 71+: 2.00
     */
    public function getAgeMultiplier(int $age): string
    {
        if ($age < 0) {
            throw new InvalidArgumentException("Age cannot be negative.");
        }

        if ($age <= 17) {
            return '1.10';
        }

        if ($age <= 60) {
            return '1.00';
        }

        if ($age <= 70) {
            return '1.50';
        }

        return '2.00';
    }

    /**
     * Calculate inclusive travel days: (trip_end - trip_start + 1), minimum 1.
     */
    public function calculateTravelDays(string|Carbon $tripStart, string|Carbon $tripEnd): int
    {
        $startDate = $tripStart instanceof Carbon ? $tripStart->copy()->startOfDay() : Carbon::parse($tripStart)->startOfDay();
        $endDate = $tripEnd instanceof Carbon ? $tripEnd->copy()->startOfDay() : Carbon::parse($tripEnd)->startOfDay();

        if ($endDate->isBefore($startDate)) {
            throw new InvalidArgumentException("Trip end date cannot be before trip start date.");
        }

        $days = (int) $startDate->diffInDays($endDate) + 1;

        return max(1, $days);
    }

    /**
     * Calculate full breakdown and premium using decimal-safe arithmetic.
     *
     * @param string $planCode BASIC, PLUS, PREMIUM
     * @param float|int|string $sumInsured 100000, 300000, 500000
     * @param string $destination DOMESTIC, INTERNATIONAL
     * @param string|Carbon $dob
     * @param string|Carbon $tripStart
     * @param string|Carbon $tripEnd
     * @return array
     */
    public function calculate(
        string $planCode,
        float|int|string $sumInsured,
        string $destination,
        string|Carbon $dob,
        string|Carbon $tripStart,
        string|Carbon $tripEnd
    ): array {
        $planCodeUpper = strtoupper(trim($planCode));
        if (!isset(self::BASE_RATES[$planCodeUpper])) {
            throw new InvalidArgumentException("Unsupported plan code: '{$planCode}'. Permitted plans: " . implode(', ', array_keys(self::BASE_RATES)));
        }
        $baseRate = self::BASE_RATES[$planCodeUpper];

        $destinationUpper = strtoupper(trim($destination));
        if (!isset(self::DESTINATION_MULTIPLIERS[$destinationUpper])) {
            throw new InvalidArgumentException("Unsupported destination: '{$destination}'. Permitted destinations: DOMESTIC, INTERNATIONAL");
        }
        $destinationMultiplier = self::DESTINATION_MULTIPLIERS[$destinationUpper];

        // Format sum insured to match keys
        $sumInsuredFormatted = number_format((float) $sumInsured, 0, '', '');
        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsuredFormatted])) {
            throw new InvalidArgumentException("Unsupported sum insured: '{$sumInsured}'. Permitted coverage amounts: 100000, 300000, 500000");
        }
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsuredFormatted];

        $travelDays = $this->calculateTravelDays($tripStart, $tripEnd);
        $age = $this->calculateAge($dob, $tripStart);
        $ageMultiplier = $this->getAgeMultiplier($age);

        // Decimal-safe multiplication using bcmath (scale = 4 intermediate, rounded to 2 final)
        // formula: base_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
        $subtotal = bcmul($baseRate, (string) $travelDays, 4);
        $subtotal = bcmul($subtotal, $ageMultiplier, 4);
        $subtotal = bcmul($subtotal, $destinationMultiplier, 4);
        $rawPremium = bcmul($subtotal, $coverageMultiplier, 4);

        // Standard half-up 2 decimal place financial rounding
        $premiumAmount = number_format((float) $rawPremium, 2, '.', '');

        return [
            'plan_code' => $planCodeUpper,
            'sum_insured' => number_format((float) $sumInsured, 2, '.', ''),
            'destination' => $destinationUpper,
            'customer_age' => $age,
            'travel_days' => $travelDays,
            'base_daily_rate' => $baseRate,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destinationMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
            'premium_amount' => $premiumAmount,
        ];
    }
}
