<?php
namespace App\Service\Forecast;

use App\Dto\ForecastData;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.forecast_provider')]
interface ForecastProviderInterface
{
    /**
     * @return ForecastData[]
     */
    public function getForecast(): array;
}
