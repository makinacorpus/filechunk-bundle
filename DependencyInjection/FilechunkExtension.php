<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\DependencyInjection;

use MakinaCorpus\FilechunkBundle\FileManager;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Twig\Environment;

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

        if (\class_exists(Environment::class)) {
            $loader->load('twig.yml');
        }

        $this->configureFileManagerSchemes($config, $container);
        $this->configureGlobalFields($config, $container);
    }

    /**
     * Configure file manager schemes
     */
    private function configureFileManagerSchemes(array $config, ContainerBuilder $container): void
    {
        $fileManagerDef = $container->getDefinition('filechunk.file_manager');
        $knownSchemes = [];

        // Determine default paths, that should be defined as
        // environment variables. Parameters can be null if the
        // environment variables are not set in the .env file.
        $knownSchemes[FileManager::SCHEME_TEMPORARY] = \sys_get_temp_dir();
        $knownSchemes[FileManager::SCHEME_PRIVATE] = $container->getParameter('filechunk.private_directory');
        $knownSchemes[FileManager::SCHEME_PUBLIC] = $container->getParameter('filechunk.public_directory');
        $knownSchemes[FileManager::SCHEME_UPLOAD] = $container->getParameter('filechunk.upload_directory');

        // @todo user driven schemes (should be from configuration)
        $fileManagerDef->setArgument(0, $knownSchemes);
    }

    /**
     * Configure global fields
     */
    private function configureGlobalFields(array $config, ContainerBuilder $container): void
    {
        if (isset($config['fields'])) {
            $sessionHandlerDef = $container->getDefinition('filechunk.session_handler');
            foreach ($config['fields'] as $name => $options) {
                $sessionHandlerDef->addMethodCall('addGlobalFieldConfig', [$name, $options]);
            }
        }
    }
}
