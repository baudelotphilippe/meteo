<?php

namespace App\Service\Forecast;

use App\Dto\ForecastData;

class ForecastAggregator
{
    /**
     * @param iterable<ForecastProviderInterface> $providers
     */
    public function __construct(private iterable $providers) {}

    /**
     * @return ForecastData[]
     */
    public function getAll(): array
    {
        $all = [];
        foreach ($this->providers as $provider) {
            $all = array_merge($all, $provider->getForecast());
        }
        return $all;
    }
}
