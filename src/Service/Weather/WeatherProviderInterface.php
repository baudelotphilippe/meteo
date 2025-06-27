<?php

declare(strict_types=1);

namespace App\Service\Weather;

use App\Dto\WeatherData;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.weather_provider')]
interface WeatherProviderInterface
{
    public function getWeather(): WeatherData;
}
