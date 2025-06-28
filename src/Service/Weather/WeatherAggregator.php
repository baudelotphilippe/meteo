<?php

declare(strict_types=1);

namespace App\Service\Weather;

use App\Config\LocationCoordinatesInterface;
use App\Dto\WeatherData;

class WeatherAggregator
{
    /**
     * @param iterable<WeatherProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return WeatherData[]
     */
    public function getAll(LocationCoordinatesInterface $locationCoordinates): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            $results[] = $provider->getWeather($locationCoordinates);
        }

        return $results;
    }
}
