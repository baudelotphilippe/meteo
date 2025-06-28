<?php

declare(strict_types=1);

namespace App\Service\HourlyForecast;
use App\Dto\HourlyForecastData;
class HourlyForecastAggregator
{
    /**
     * @param iterable<HourlyForecastProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return HourlyForecastData[]
     */
    public function getAll(): array
    {
        $result = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->getTodayHourly() as $item) {
                $result[$item->provider][] = $item;
            }
        }

        return $result;
    }
}
