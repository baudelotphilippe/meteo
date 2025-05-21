<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use App\Dto\WeatherData;

#[AutoconfigureTag('app.weather_provider')]
interface WeatherProviderInterface
{
    public function getWeather(): WeatherData;
}
