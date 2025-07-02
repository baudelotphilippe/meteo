<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ClearCacheController extends AbstractController
{
   
    #[Route('/clear-cache', name: 'clear-cache')]
    public function clearstatcache(CacheItemPoolInterface $cache): Response
    {
        $cache->clear();

        return new Response('ok');
    }
}
