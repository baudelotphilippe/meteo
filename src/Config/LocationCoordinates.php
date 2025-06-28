<?php

declare(strict_types=1);

namespace App\Config;

class LocationCoordinates implements LocationCoordinatesInterface
{
    public function __construct(
        private readonly string $name,
        private readonly float $latitude,
        private readonly float $longitude,
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
}
