<?php

namespace App\Tests\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinates;
use App\Dto\WeatherData;
use App\Service\ApiSources\MetNoService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MetNoServiceTest extends KernelTestCase
{
    public function testGetWeather(): void
    {
        /** @var MetNoService $metNoService */
        $metNoService= static::getContainer()->get(MetNoService::class);
        $this->assertTrue($metNoService instanceof MetNoService);
        $locationCoordinates=new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $weatherData=$metNoService->getWeather($locationCoordinates);
        $this->assertTrue($weatherData instanceof WeatherData);
        $this->assertTrue($weatherData->provider==="Met.no");
    }

    public function testGetForecast(): void
    {
        /** @var MetNoService $metNoService */
        $metNoService= static::getContainer()->get(MetNoService::class);
        $this->assertTrue($metNoService instanceof MetNoService);
        $locationCoordinates=new LocationCoordinates('Poitiers', 46.58, 0.34, 'Europe/Paris');
        $array=$metNoService->getForecast($locationCoordinates);
        $this->assertIsArray($array);
        $this->assertTrue(count($array) > 0);

        /** @var ForecastData $forecastData */
        $forecastData=$array[0];
        $this->assertTrue($forecastData instanceof ForecastData);




    }
}
