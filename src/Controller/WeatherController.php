<?php

namespace App\Controller;

use App\Config\CityCoordinates;
use App\Service\ForecastAggregator;
use App\Service\WeatherAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WeatherController extends AbstractController
{

    #[Route('/meteo', name: 'weather')]
    function meteo(WeatherAggregator $weather_aggregator, ForecastAggregator $forecast_aggregator): Response
    {
        $forecastRows = [];

        foreach ($forecast_aggregator->getAll() as $forecast) {
            $provider = $forecast->provider;
            $forecastRows[$provider][] = $forecast;
        }


        return $this->render('meteo.html.twig', ['ville' => CityCoordinates::CITY, 'sources' => $weather_aggregator->getAll(), 'forecastRows' => $forecastRows]);
    }
}
