<?php

namespace Koboldsoft\PdfFillerBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Koboldsoft\PdfFillerBundle\PdfFillerBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser)
    {
        return [
            BundleConfig::create(PdfFillerBundle::class)
            ->setLoadAfter([ContaoCoreBundle::class]),
        ];
        
    }
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        return $resolver
        ->resolve(__DIR__ . '/../Resources/config/routes.yaml')
        ->load(__DIR__ . '/../Resources/config/routes.yaml');
    }
}
