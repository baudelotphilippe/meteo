<?php
namespace App\Dto;

class HourlyForecastData
{
    public function __construct(
        public string $provider,
        public string $time,       // format "HHhmm"
        public float $temperature,
        public string $description,
        public string $emoji
    ) {}
}
