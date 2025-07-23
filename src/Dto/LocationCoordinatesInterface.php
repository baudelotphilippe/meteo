<?php

declare(strict_types=1);

namespace App\Dto;

interface LocationCoordinatesInterface
{
    public function getName(): string;

    public function getLatitude(): float;

    public function getLongitude(): float;

    public function getTimezone(): string;

    public function toArray(): array;
}
