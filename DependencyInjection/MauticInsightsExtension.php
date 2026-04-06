<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Registers Resources/views with Twig namespace "MauticInsightsBundle" so @MauticInsightsBundle/... resolves.
 */
class MauticInsightsExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $views = dirname(__DIR__).'/Resources/views';
        if (!is_dir($views)) {
            return;
        }

        $container->prependExtensionConfig('twig', [
            'paths' => [
                $views => 'MauticInsightsBundle',
            ],
        ]);
    }

    /**
     * @param mixed[] $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Config'));
        $loader->load('services.php');
    }
}
