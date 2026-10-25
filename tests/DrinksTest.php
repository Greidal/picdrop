<?php

use PHPUnit\Framework\TestCase;

final class DrinksTest extends TestCase
{
    private const PROFILE = ['weight_kg' => 80.0, 'sex' => 'm'];
    private const T0 = 1_760_000_000;

    private static function drink(int $minutes, int $ml = 500, float $abv = 5.0, array $extra = []): array
    {
        return $extra + [
            'ts' => self::T0 + $minutes * 60,
            'grams' => drinkGrams($ml, $abv),
            'volume_ml' => $ml,
            'abv' => $abv,
            'drink_id' => 1,
            'drink' => 'Bier',
            'score' => 1.0,
            'round_by' => null,
        ];
    }

    public function testNoDrinksMeansSober(): void
    {
        $sim = simulateBac([], self::PROFILE, self::T0);
        $this->assertSame(0.0, $sim['current']);
        $this->assertNull($sim['peak']);
        $this->assertSame([], $sim['series']);
    }

    public function testOneBeerMatchesWidmarkEstimate(): void
    {
        // 0.5 l at 5 %: 19.7 g, minus 10 % resorption deficit, 80 kg * 0.68 => 0.33 ‰,
        // minus 0.15 ‰/h elimination during the 45 min of absorption => ~0.21 ‰
        $sim = simulateBac([self::drink(0)], self::PROFILE, self::T0 + 3600);
        $this->assertEqualsWithDelta(0.21, $sim['peak']['value'], 0.02);
        $this->assertGreaterThan(0.0, $sim['current']);
        $this->assertLessThan($sim['peak']['value'], $sim['current'], 'already past the peak after an hour');
        $this->assertNotNull($sim['sober_at']);
        $this->assertNull($sim['below_limit_at'], 'never above the limit');
    }

    public function testForecastForDrivingLimitAndSobriety(): void
    {
        $drinks = [self::drink(0), self::drink(20), self::drink(40), self::drink(60), self::drink(80)];
        $now = self::T0 + 100 * 60;
        $sim = simulateBac($drinks, self::PROFILE, $now);

        $this->assertGreaterThan(DRIVING_LIMIT, $sim['current']);
        $this->assertGreaterThan($now, $sim['below_limit_at']);
        $this->assertGreaterThan($sim['below_limit_at'], $sim['sober_at']);
        // elimination of 0.15 ‰/h: sober roughly peak / 0.15 hours after the peak
        $hoursAfterPeak = ($sim['sober_at'] - $sim['peak']['ts']) / 3600;
        $this->assertEqualsWithDelta($sim['peak']['value'] / ELIMINATION_PER_HOUR, $hoursAfterPeak, 0.2);
    }

    public function testWomenReachHigherValuesThanMen(): void
    {
        $drinks = [self::drink(0), self::drink(10)];
        $men = simulateBac($drinks, ['weight_kg' => 70.0, 'sex' => 'm'], self::T0 + 7200);
        $women = simulateBac($drinks, ['weight_kg' => 70.0, 'sex' => 'f'], self::T0 + 7200);
        $this->assertGreaterThan($men['peak']['value'], $women['peak']['value']);
    }

    public function testSessionStartsAfterBeingSoberAgain(): void
    {
        $drinks = [self::drink(0), self::drink(24 * 60)];
        $sim = simulateBac($drinks, self::PROFILE, self::T0 + 24 * 3600 + 600);
        $this->assertGreaterThanOrEqual(self::T0 + 23 * 3600, $sim['session_start']);
    }

    public function testBacAtInterpolatesAndIsZeroOutsideTheCurve(): void
    {
        $curve = bacCurve([self::drink(0)], self::PROFILE, self::T0);
        $this->assertSame(0.0, bacAt($curve, self::T0 - 60));
        $this->assertSame(0.0, bacAt($curve, self::T0 + 48 * 3600));
        $mid = bacAt($curve, self::T0 + 30 * 60 + 60);
        $this->assertGreaterThan(min(bacAt($curve, self::T0 + 1800), bacAt($curve, self::T0 + 1920)) - 0.0001, $mid);
        $this->assertLessThan(max(bacAt($curve, self::T0 + 1800), bacAt($curve, self::T0 + 1920)) + 0.0001, $mid);
    }

    public function testStats(): void
    {
        $drinks = [
            self::drink(0),
            self::drink(30, 20, 40.0, ['drink' => 'Shot', 'drink_id' => 2]),
            self::drink(60, 500, 5.0, ['round_by' => 'dev_other']),
        ];
        $stats = drinkStats($drinks, 'dev_me');
        $this->assertSame(3, $stats['count']);
        $this->assertSame(['name' => 'Bier', 'count' => 2], $stats['favorite']);
        $this->assertSame(60, $stats['duration_min']);
        $this->assertSame(3.0, $stats['per_hour']);
        $this->assertSame(1, $stats['received_rounds']);
        $this->assertEqualsWithDelta(1.02, $stats['volume_l'], 0.001);
    }

    public function testBadges(): void
    {
        $drinks = [
            self::drink(0, 20, 40.0, ['drink_id' => 1]),
            self::drink(10, 20, 40.0, ['drink_id' => 2]),
            self::drink(20, 20, 40.0, ['drink_id' => 3]),
            self::drink(400, 1000, 5.5, ['drink_id' => 4]),
        ];
        $sim = simulateBac($drinks, self::PROFILE, self::T0 + 500 * 60);
        $badges = array_column(drinkBadges($drinks, $sim, ['photos' => 5, 'rounds' => 0, 'team' => true, 'rank' => 2]), 'earned', 'key');

        $this->assertTrue($badges['first']);
        $this->assertTrue($badges['shots']);
        $this->assertTrue($badges['sprint']);
        $this->assertTrue($badges['variety']);
        $this->assertTrue($badges['mass']);
        $this->assertTrue($badges['marathon']);
        $this->assertTrue($badges['paparazzo']);
        $this->assertTrue($badges['team']);
        $this->assertFalse($badges['round']);
        $this->assertFalse($badges['top']);
        $this->assertFalse($badges['ten']);
    }

    public function testGuestRefIsStableAndDoesNotRevealTheDevice(): void
    {
        $ref = drinkGuestRef('event-a', 'dev_secret');
        $this->assertSame($ref, drinkGuestRef('event-a', 'dev_secret'));
        $this->assertNotSame($ref, drinkGuestRef('event-b', 'dev_secret'));
        $this->assertStringNotContainsString('secret', $ref);
        $this->assertSame(16, strlen($ref));
    }
}
