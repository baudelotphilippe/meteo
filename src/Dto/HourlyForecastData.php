<?php

declare(strict_types=1);

namespace App\Dto;

final class HourlyForecastData
{
    public function __construct(
        public string $provider,
        public string $time,       // format "HHhmm"
        public float $temperature,
        public string $description,
        public string $emoji,
    ) {
    }
}
