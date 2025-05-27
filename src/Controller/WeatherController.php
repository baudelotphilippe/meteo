<?php

namespace App\Controller;

use App\Config\CityCoordinates;
use App\Service\InfosOfTheDayService;
use App\Service\HourlyForecast\HourlyForecastAggregator;
use App\Service\Weather\WeatherAggregator;
use App\Service\Forecast\ForecastAggregator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class WeatherController extends AbstractController
{

    #[Route('/', name: 'weather')]
    function meteo(WeatherAggregator $weather_aggregator, ForecastAggregator $forecast_aggregator, HourlyForecastAggregator $hourly_forecast_aggregator, InfosOfTheDayService $infos_of_the_day_service): Response
    {
        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }

        return $this->render('meteo.html.twig', ['ville' => CityCoordinates::CITY, 'infosDay'=>$infos_of_the_day_service->getInfosOfTheDay(), 'sources' => $weather_aggregator->getAll(), 'forecastRows' => $forecastRows, 'todayHourly' => $hourly_forecast_aggregator->getAll()]);
    }
}
