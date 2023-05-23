<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\DependencyInjection;

use MakinaCorpus\FilechunkBundle\FieldConfig;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @codeCoverageIgnore
 * @todo This should be tested.
 */
final class FilechunkExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.yml');

        $this->configureGlobalFields($config, $container);
    }

    /**
     * Configure global fields
     */
    private function configureGlobalFields(array $config, ContainerBuilder $container): void
    {
        if (isset($config['fields'])) {
            $sessionHandlerDef = $container->getDefinition('filechunk.session_handler');
            foreach ($config['fields'] as $name => $options) {
                $serviceId = \sprintf('filechunk.field.%s', $name);

                $definition = new Definition();
                $definition->setClass(FieldConfig::class);
                $definition->setFactory(\sprintf("%s::fromArray", FieldConfig::class));
                $definition->setArguments([$name, $options]);

                $container->setDefinition($serviceId, $definition);
                $sessionHandlerDef->addMethodCall('addGlobalFieldConfig', [new Reference($serviceId)]);
            }
        }
    }
}
