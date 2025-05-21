<?php

namespace App\Dto;

class WeatherData
{
    public function __construct(
        public string $provider,
        public float $temperature,
        public ?string $description,
        public ?float $humidity,
        public float $wind,
        public string $sourceName,
        public string $logoUrl,
        public string $sourceUrl,
    ) {}
}
