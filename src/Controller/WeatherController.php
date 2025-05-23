<?php

namespace App\Controller;

use App\Config\CityCoordinates;
use App\Service\ForecastAggregator;
use App\Service\HourlyForecastAggregator;
use App\Service\OpenWeatherService;
use App\Service\WeatherAggregator;
use App\Service\WeatherApiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WeatherController extends AbstractController
{

    #[Route('/', name: 'weather')]
    function meteo(WeatherAggregator $weather_aggregator, ForecastAggregator $forecast_aggregator, HourlyForecastAggregator $hourly_forecast_aggregator): Response
    {
        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }


        return $this->render('meteo.html.twig', ['ville' => CityCoordinates::CITY, 'sources' => $weather_aggregator->getAll(), 'forecastRows' => $forecastRows, 'todayHourly' => $hourly_forecast_aggregator->getAll()]);
    }
}
