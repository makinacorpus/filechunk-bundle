<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Twig\Environment;
use MakinaCorpus\FilechunkBundle\FileManager;

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

        $fileManagerDef = $container->getDefinition('filechunk.file_manager');
        $projectDir = $container->getParameter("kernel.project_dir");
        $knownSchemes = [];

        // Determine default paths, that should be defined as
        // environment variables. Parameters can be null if the
        // environment variables are not set in the .env file.
        $knownSchemes[FileManager::SCHEME_TEMPORARY] = \sys_get_temp_dir();

        if (!$workingDirectory = $container->getParameter('filechunk.private_directory')) {
            $workingDirectory = $projectDir.'/var/private';
        }
        $knownSchemes[FileManager::SCHEME_PRIVATE] = $workingDirectory;

        if (!$workingDirectory = $container->getParameter('filechunk.public_directory')) {
            $workingDirectory = $projectDir.'/public/files';
        }
        $knownSchemes[FileManager::SCHEME_PUBLIC] = $workingDirectory;

        if (!$workingDirectory = $container->getParameter('filechunk.upload_directory')) {
            $workingDirectory = \sys_get_temp_dir().'/filechunk';
        }
        $knownSchemes[FileManager::SCHEME_UPLOAD] = $workingDirectory;

        // @todo user driven schemes (should be from configuration)
        $fileManagerDef->setArguments([$knownSchemes]);
    }
}
