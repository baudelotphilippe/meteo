<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\CityCoordinates;
use App\Service\Forecast\ForecastAggregator;
use App\Service\HourlyForecast\HourlyForecastAggregator;
use App\Service\InfosOfTheDayService;
use App\Service\Weather\WeatherAggregator;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WeatherController extends AbstractController
{
    #[Route('/', name: 'weather')]
    public function meteo(WeatherAggregator $weather_aggregator, ForecastAggregator $forecast_aggregator, HourlyForecastAggregator $hourly_forecast_aggregator, InfosOfTheDayService $infos_of_the_day_service): Response
    {
        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }

        $chartsData = [];
        foreach ($hourly_forecast_aggregator->getAll() as $provider => $hourlyData) {
            $chartsData[$provider] = [
                'labels' => array_map(fn ($h) => $h->time, $hourlyData),
                'temperatures' => array_map(fn ($h) => $h->temperature, $hourlyData),
                'emoji' => array_map(fn ($h) => $h->emoji, $hourlyData),
            ];
        }

        return $this->render('meteo.html.twig', ['ville' => CityCoordinates::CITY, 'infosDay' => $infos_of_the_day_service->getInfosOfTheDay(), 'sources' => $weather_aggregator->getAll(), 'forecastRows' => $forecastRows, 'todayHourly' => $chartsData]);
    }

    #[Route('/clear-cache', name: 'clear-cache')]
    public function clearstatcache(CacheItemPoolInterface $cache): Response
    {
        $cache->clear();

        return new Response('ok');
    }
}
