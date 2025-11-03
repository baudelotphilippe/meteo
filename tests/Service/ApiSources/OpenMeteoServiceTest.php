<?php

namespace App\Tests\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinates;
use App\Dto\WeatherData;
use App\Service\ApiSources\OpenMeteoService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OpenMeteoServiceTest extends KernelTestCase
{
    public function testGetWeather(): void
    {
        /** @var OpenMeteoService $openMeteoService */
        $openMeteoService = static::getContainer()->get(OpenMeteoService::class);
        $this->assertTrue($openMeteoService instanceof OpenMeteoService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $weatherData = $openMeteoService->getWeather($locationCoordinates);
        $this->assertTrue($weatherData instanceof WeatherData);
        $this->assertTrue($weatherData->provider === 'Open-Meteo');
    }

    public function testGetForecast(): void
    {
        /** @var OpenMeteoService $openMeteoService */
        $openMeteoService = static::getContainer()->get(OpenMeteoService::class);
        $this->assertTrue($openMeteoService instanceof OpenMeteoService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array = $openMeteoService->getForecast($locationCoordinates);
        $this->assertIsArray($array);
        $this->assertTrue(count($array) > 0);

        /** @var ForecastData $forecastData */
        $forecastData = $array[0];
        $this->assertTrue($forecastData instanceof ForecastData);
    }

    public function testGetTodayHourly(): void
    {
        /** @var OpenMeteoService $openMeteoService */
        $openMeteoService = static::getContainer()->get(OpenMeteoService::class);
        $this->assertTrue($openMeteoService instanceof OpenMeteoService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array = $openMeteoService->getTodayHourly($locationCoordinates);
        $this->assertIsArray($array);
    }
}
