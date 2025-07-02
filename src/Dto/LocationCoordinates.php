<?php

declare(strict_types=1);

namespace App\Dto;

class LocationCoordinates implements LocationCoordinatesInterface
{
    public function __construct(
        private readonly string $name,
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly string $timezone ="Europe/Paris",
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }
}
