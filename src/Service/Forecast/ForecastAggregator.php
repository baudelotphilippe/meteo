<?php

declare(strict_types=1);

namespace App\Service\Forecast;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinatesInterface;

class ForecastAggregator
{
    /**
     * @param iterable<ForecastProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return ForecastData[]
     */
    public function getAll(LocationCoordinatesInterface $locationCoordinates): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            $all = array_merge($all, $provider->getForecast($locationCoordinates));
        }

        return $all;
    }
}
