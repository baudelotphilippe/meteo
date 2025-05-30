<?php

namespace App\Controller;


use App\Config\CityCoordinates;
use App\Service\InfosOfTheDayService;
use Psr\Cache\CacheItemPoolInterface;
use App\Service\Weather\WeatherAggregator;
use App\Service\Forecast\ForecastAggregator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\HourlyForecast\HourlyForecastAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class WeatherController extends AbstractController
{

    #[Route('/', name: 'weather')]
    function meteo(WeatherAggregator $weather_aggregator, ForecastAggregator $forecast_aggregator, HourlyForecastAggregator $hourly_forecast_aggregator, InfosOfTheDayService $infos_of_the_day_service, HttpClientInterface $client): Response
    {
        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }

        // $apiKey = 'IaHAXHJcS0h2znhK5Wr405dMmaMkHA82';
        // $stationId = 'FR09403';
        // $date = date('Y-m-d'); // Date du jour

        // $response = $client->request('GET', 'https://www.geodair.fr/api-ext/MoyJ/export', [
        //     'query' => [
        //         'date' => $date,
        //         'polluant' => '24',
        //     ],
        //     'headers' => [
        //         'apikey' => $apiKey,
        //     ],
        // ]);

        // $downloadId = $response->getContent();

        // if ($downloadId) {
        //     $response = $client->request('GET', "https://www.geodair.fr/api-ext/download", [
        //         'query' => ['id' => $downloadId],
        //         'headers' => [
        //             'apikey' => $apiKey,
        //         ],
        //     ]);

        //     file_put_contents('donnees_pollution.csv', $response->getContent());
        // }


        return $this->render('meteo.html.twig', ['ville' => CityCoordinates::CITY, 'infosDay' => $infos_of_the_day_service->getInfosOfTheDay(), 'sources' => $weather_aggregator->getAll(), 'forecastRows' => $forecastRows, 'todayHourly' => $hourly_forecast_aggregator->getAll()]);
    }

    #[Route('/clear-cache', name: "clear-cache")]
    function clearstatcache(CacheItemPoolInterface $cache): Response
    {
        $cache->clear();
        return new Response("ok");
    }

    #[Route('/chart', name: 'chart')]
    public function index(ForecastAggregator $forecast_aggregator, HourlyForecastAggregator $hourlyAggregator): Response
    {
        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }
        $allHourlyData = $hourlyAggregator->getAll(); // tableau ['Fournisseur' => [HourlyForecastData,...], ...]

        $chartsData = [];

        foreach ($allHourlyData as $provider => $hourlyData) {
            $chartsData[$provider] = [
                'labels' => array_map(fn($h) => $h->time, $hourlyData),
                'temperatures' => array_map(fn($h) => $h->temperature, $hourlyData),
            ];
        }

        return $this->render('chart.html.twig', [
            'chartsData' => $chartsData,
        ]);
    }
}
