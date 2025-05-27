<?php

namespace App\Service\HourlyForecast;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.hourly_forecast')]
interface HourlyForecastProviderInterface
{
    public function getTodayHourly(): array;
}
