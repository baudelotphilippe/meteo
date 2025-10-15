<?php

namespace App\Tests\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinates;
use App\Dto\WeatherData;
use App\Service\ApiSources\OpenWeatherService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OpenWeatherServiceTest extends KernelTestCase
{
    public function testGetWeather(): void
    {
        /** @var OpenWeatherService $openWeatherService */
        $openWeatherService = static::getContainer()->get(OpenWeatherService::class);
        $this->assertTrue($openWeatherService instanceof OpenWeatherService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');

        // OpenWeatherService nécessite une clé API
        try {
            $weatherData = $openWeatherService->getWeather($locationCoordinates);
            $this->assertTrue($weatherData instanceof WeatherData);
            $this->assertTrue($weatherData->provider === 'OpenWeather');
        } catch (\RuntimeException $e) {
            // Si la clé API n'est pas configurée, on accepte l'exception
            $this->assertStringContainsString('Clé API', $e->getMessage());
        }
    }

    public function testGetForecast(): void
    {
        /** @var OpenWeatherService $openWeatherService */
        $openWeatherService = static::getContainer()->get(OpenWeatherService::class);
        $this->assertTrue($openWeatherService instanceof OpenWeatherService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');

        try {
            $array = $openWeatherService->getForecast($locationCoordinates);
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
        /** @var OpenWeatherService $openWeatherService */
        $openWeatherService = static::getContainer()->get(OpenWeatherService::class);
        $this->assertTrue($openWeatherService instanceof OpenWeatherService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');

        try {
            $array = $openWeatherService->getTodayHourly($locationCoordinates);
            $this->assertIsArray($array);
        } catch (\RuntimeException $e) {
            // Si la clé API n'est pas configurée, on accepte l'exception
            $this->assertStringContainsString('Clé API', $e->getMessage());
        }
    }
}
