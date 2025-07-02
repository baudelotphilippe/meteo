<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\LocationCoordinates;
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
    public function __construct(private WeatherAggregator $weather_aggregator, private ForecastAggregator $forecast_aggregator, private HourlyForecastAggregator $hourly_forecast_aggregator, private InfosOfTheDayService $infos_of_the_day_service)
    {       
    }
    
    #[Route('/', name: 'weather')]
    public function meteo(): Response
    {
        $locationCoordinates = new LocationCoordinates(
            $this->getParameter('meteo_name'),
            $this->getParameter('meteo_latitude'),
            $this->getParameter('meteo_longitude'),
            $this->getParameter('meteo_timezone')
        );

        $forecastRows = [];

        foreach ($this->forecast_aggregator->getAll($locationCoordinates) as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }

        $chartsData = [];
        foreach ($this->hourly_forecast_aggregator->getAll() as $provider => $hourlyData) {
            $chartsData[$provider] = [
                'labels' => array_map(fn ($h) => $h->time->format(), $hourlyData),
                'temperatures' => array_map(fn ($h) => $h->temperature, $hourlyData),
                'emoji' => array_map(fn ($h) => $h->emoji, $hourlyData),
            ];
        }
        dump($chartsData);

        return $this->render(
            'meteo.html.twig',
            [
                'location' => $locationCoordinates,
                'infosDay' => $this->infos_of_the_day_service->getInfosOfTheDay($locationCoordinates),
                'sources' => $this->weather_aggregator->getAll($locationCoordinates),
                'forecastRows' => $forecastRows,
                'todayHourly' => $chartsData]
        );
    }
}
