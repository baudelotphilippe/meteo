<?php

declare(strict_types=1);

namespace App\Service\HourlyForecast;

use App\Dto\LocationCoordinatesInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.hourly_forecast')]
interface HourlyForecastProviderInterface
{
    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array;
}
