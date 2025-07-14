<?php

declare(strict_types=1);

namespace App\Service\HourlyForecast;

use App\Dto\LocationCoordinatesInterface;

class HourlyForecastAggregator
{
    /**
     * @param iterable<HourlyForecastProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return array<int|string, list>
     */
    public function getAll(LocationCoordinatesInterface $locationCoordinates): array
    {
        $result = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->getTodayHourly($locationCoordinates) as $item) {
                $result[$item->provider][] = $item;
            }
        }

        return $result;
    }
}
