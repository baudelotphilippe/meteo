<?php

declare(strict_types=1);

namespace App\Dto;

class ForecastData
{
    public function __construct(
        public string $provider,
        public \DateTimeImmutable $date,
        public float $tmin,
        public float $tmax,
        public string $icon,
        public string $emoji,
    ) {
    }
}
