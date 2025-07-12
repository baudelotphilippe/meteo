<?php

declare(strict_types=1);

namespace App\Dto;

use App\ValueObject\Time;

final class HourlyForecastData
{
    public function __construct(
        public string $provider,
        public Time $time,       // format "HHhmm"
        public float $temperature,
        public string $description,
        public string $emoji,
    ) {
    }
}
