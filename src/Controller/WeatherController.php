<?php

namespace App\Controller;

use DateTime;
use DateTimeZone;
use DateTimeImmutable;
use App\Config\CityCoordinates;
use App\Service\WeatherAggregator;
use App\Service\WeatherApiService;
use App\Service\ForecastAggregator;
use App\Service\OpenWeatherService;
use App\Service\HourlyForecastAggregator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class WeatherController extends AbstractController
{

    #[Route('/', name: 'weather')]
    function meteo(WeatherAggregator $weather_aggregator, ForecastAggregator $forecast_aggregator, HourlyForecastAggregator $hourly_forecast_aggregator): Response
    {

        $tz = new DateTimeZone('Europe/Paris');
        $date = new DateTimeImmutable('today', $tz);
        $formatter = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'Europe/Paris',
            \IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM y'
        );

        $sun = date_sun_info($date->getTimestamp(), CityCoordinates::LAT, CityCoordinates::LON);

        $ephemeride = [
            'sunrise' => (new DateTime("@{$sun['sunrise']}"))->setTimezone($tz)->format('G\hi'),
            'sunset'  => (new DateTime("@{$sun['sunset']}"))->setTimezone($tz)->format('G\hi'),
        ];



        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }


        return $this->render('meteo.html.twig', ['ville' => [
            'name' => CityCoordinates::CITY,
            'date'    => $formatter->format($date),
            'ephemeride' => $ephemeride
        ], 'sources' => $weather_aggregator->getAll(), 'forecastRows' => $forecastRows, 'todayHourly' => $hourly_forecast_aggregator->getAll()]);
    }
}
