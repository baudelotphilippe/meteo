<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.hourly_forecast')]
interface HourlyForecastProviderInterface
{
    public function getTodayHourly(): array;
}
