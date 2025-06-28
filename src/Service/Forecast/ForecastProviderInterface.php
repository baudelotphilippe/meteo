<?php

declare(strict_types=1);

namespace App\Service\Forecast;

use App\Config\LocationCoordinatesInterface;
use App\Dto\ForecastData;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.forecast_provider')]
interface ForecastProviderInterface
{
    /**
     * @return ForecastData[]
     */
    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array;
}
