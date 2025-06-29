<?php

namespace AddTriplestore\Controller\Site;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Http\Client;
use Laminas\Router\RouteStackInterface; 

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $httpClient = new Client();
        $router = $container->get(RouteStackInterface::class);
        return new IndexController($router, $httpClient); 
    }
}