<?php

namespace App\Service\Weather;

use App\Dto\WeatherData;

class WeatherAggregator
{
    /**
     * @param iterable<WeatherProviderInterface> $providers
     */
    public function __construct(private iterable $providers) {}

    /**
     * @return WeatherData[]
     */
    public function getAll(): array
    {
        $results = [];

        foreach ($this->providers as $provider) {
            $results[] = $provider->getWeather();
        }

        return $results;
    }
}
