<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MeteoTest extends WebTestCase
{
    public function testRootUrl(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Météo à Poitiers');


    }

    public function testLocationUrl(): void
    {
        $client = static::createClient();

        $client->request('GET', '/location/poitiers');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Météo à Poitiers');

        $client->request('GET', '/location/paris');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Météo à Paris');
    }


    public function testClearCacheUrl(): void
    {
        $client = static::createClient();

        $client->request('GET', '/clear-cache');
        $this->assertResponseIsSuccessful();

    }

}
