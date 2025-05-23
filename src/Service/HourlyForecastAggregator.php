<?php

namespace App\Service;

class HourlyForecastAggregator
{
    /**
     * @param iterable<HourlyForecastProviderInterface> $providers
     */
    public function __construct(private iterable $providers) {}

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
