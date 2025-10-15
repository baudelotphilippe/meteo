<?php

namespace App\Tests\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinates;
use App\Dto\WeatherData;
use App\Service\ApiSources\WeatherApiService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class WeatherApiServiceTest extends KernelTestCase
{
    public function testGetWeather(): void
    {
        /** @var WeatherApiService $weatherApiService */
        $weatherApiService = static::getContainer()->get(WeatherApiService::class);
        $this->assertTrue($weatherApiService instanceof WeatherApiService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');

        // WeatherApiService nécessite une clé API
        try {
            $weatherData = $weatherApiService->getWeather($locationCoordinates);
            $this->assertTrue($weatherData instanceof WeatherData);
            $this->assertTrue($weatherData->provider === 'WeatherAPI');
        } catch (\RuntimeException $e) {
            // Si la clé API n'est pas configurée, on accepte l'exception
            $this->assertStringContainsString('Clé API', $e->getMessage());
        }
    }

    public function testGetForecast(): void
    {
        /** @var WeatherApiService $weatherApiService */
        $weatherApiService = static::getContainer()->get(WeatherApiService::class);
        $this->assertTrue($weatherApiService instanceof WeatherApiService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');

        try {
            $array = $weatherApiService->getForecast($locationCoordinates);
            $this->assertIsArray($array);

            if (count($array) > 0) {
                /** @var ForecastData $forecastData */
                $forecastData = $array[0];
                $this->assertTrue($forecastData instanceof ForecastData);
            }
        } catch (\RuntimeException $e) {
            // Si la clé API n'est pas configurée, on accepte l'exception
            $this->assertStringContainsString('Clé API', $e->getMessage());
        }
    }

    public function testGetTodayHourly(): void
    {
        /** @var WeatherApiService $weatherApiService */
        $weatherApiService = static::getContainer()->get(WeatherApiService::class);
        $this->assertTrue($weatherApiService instanceof WeatherApiService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');

        try {
            $array = $weatherApiService->getTodayHourly($locationCoordinates);
            $this->assertIsArray($array);
        } catch (\RuntimeException $e) {
            // Si la clé API n'est pas configurée, on accepte l'exception
            $this->assertStringContainsString('Clé API', $e->getMessage());
        }
    }
}
