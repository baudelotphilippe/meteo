<?php

declare(strict_types=1);

namespace App\Dto;

final class HourlyForecastData
{
    public function __construct(
        public string $provider,
        public \DateTimeImmutable $time,
        public float $temperature,
        public string $description,
        public string $emoji,
    ) {
    }
}
