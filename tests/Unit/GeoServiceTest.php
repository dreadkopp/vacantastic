<?php

namespace Tests\Unit;

use App\Services\GeoService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeoServiceTest extends TestCase
{
    private GeoService $geo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->geo = new GeoService;
    }

    #[DataProvider('scoreRampProvider')]
    public function test_score_ramp(float $meters, int $expected): void
    {
        $this->assertSame($expected, $this->geo->scoreForDistance($meters));
    }

    public static function scoreRampProvider(): array
    {
        return [
            'on the target' => [0.0, 5000],
            'within 1 km' => [500.0, 5000],
            'at 1 km' => [1000.0, 5000],
            'midway 1-10 km' => [5500.0, 3000],
            'at 10 km' => [10000.0, 1000],
            'midway 10-100 km' => [55000.0, 550],
            'at 100 km' => [100000.0, 100],
            'at 350 km' => [350000.0, 74],
            'just over 1000 km' => [1000001.0, 0],
            'far away' => [5000000.0, 0],
        ];
    }

    public function test_score_never_negative(): void
    {
        $this->assertGreaterThanOrEqual(0, $this->geo->scoreForDistance(-1000.0));
    }
}
