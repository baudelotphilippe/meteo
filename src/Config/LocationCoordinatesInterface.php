<?php

declare(strict_types=1);

namespace App\Config;

interface LocationCoordinatesInterface
{
    public function getName(): string;

    public function getLatitude(): float;

    public function getLongitude(): float;
}
