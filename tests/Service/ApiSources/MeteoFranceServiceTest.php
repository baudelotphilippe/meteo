<?php

namespace App\Tests\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinates;
use App\Dto\WeatherData;
use App\Service\ApiSources\MeteoFranceService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MeteoFranceServiceTest extends KernelTestCase
{
    public function testGetWeather(): void
    {
        /** @var MeteoFranceService $meteoFranceService */
        $meteoFranceService = static::getContainer()->get(MeteoFranceService::class);
        $this->assertTrue($meteoFranceService instanceof MeteoFranceService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $weatherData = $meteoFranceService->getWeather($locationCoordinates);
        $this->assertTrue($weatherData instanceof WeatherData);
        $this->assertTrue($weatherData->provider === 'Météo-France');
    }

    public function testGetForecast(): void
    {
        /** @var MeteoFranceService $meteoFranceService */
        $meteoFranceService = static::getContainer()->get(MeteoFranceService::class);
        $this->assertTrue($meteoFranceService instanceof MeteoFranceService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array = $meteoFranceService->getForecast($locationCoordinates);
        $this->assertIsArray($array);
        $this->assertTrue(count($array) > 0);

        /** @var ForecastData $forecastData */
        $forecastData = $array[0];
        $this->assertTrue($forecastData instanceof ForecastData);
    }

    public function testGetTodayHourly(): void
    {
        /** @var MeteoFranceService $meteoFranceService */
        $meteoFranceService = static::getContainer()->get(MeteoFranceService::class);
        $this->assertTrue($meteoFranceService instanceof MeteoFranceService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array = $meteoFranceService->getTodayHourly($locationCoordinates);
        $this->assertIsArray($array);
    }
}
