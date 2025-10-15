<?php

namespace App\Tests\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinates;
use App\Dto\WeatherData;
use App\Service\ApiSources\WttrinService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class WttrinServiceTest extends KernelTestCase
{
    public function testGetWeather(): void
    {
        /** @var WttrinService $wttrinService */
        $wttrinService = static::getContainer()->get(WttrinService::class);
        $this->assertTrue($wttrinService instanceof WttrinService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $weatherData = $wttrinService->getWeather($locationCoordinates);
        $this->assertTrue($weatherData instanceof WeatherData);
        $this->assertTrue($weatherData->provider === 'wttr.in');
    }

    public function testGetForecast(): void
    {
        /** @var WttrinService $wttrinService */
        $wttrinService = static::getContainer()->get(WttrinService::class);
        $this->assertTrue($wttrinService instanceof WttrinService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array = $wttrinService->getForecast($locationCoordinates);
        $this->assertIsArray($array);
        $this->assertTrue(count($array) > 0);

        /** @var ForecastData $forecastData */
        $forecastData = $array[0];
        $this->assertTrue($forecastData instanceof ForecastData);
    }

    public function testGetTodayHourly(): void
    {
        /** @var WttrinService $wttrinService */
        $wttrinService = static::getContainer()->get(WttrinService::class);
        $this->assertTrue($wttrinService instanceof WttrinService);
        $locationCoordinates = new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array = $wttrinService->getTodayHourly($locationCoordinates);
        $this->assertIsArray($array);
    }
}
