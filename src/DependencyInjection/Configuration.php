<?php

declare(strict_types=1);

namespace MakinaCorpus\FilechunkBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @codeCoverageIgnore
 * @todo This should be tested.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('filechunk');
        $rootNode = $treeBuilder->getRootNode();

        // Site-wide specific custom fields.
        $rootNode
            ->children()
                ->arrayNode('fields')
                    ->info('Global site-wide fields')
                    ->normalizeKeys(true)
                    ->prototype('array')
                    ->children()
                        ->scalarNode('maxsize')
                            ->info('Maximum file size')
                            ->isRequired()
                        ->end()
                        ->variableNode('mimetypes')
                            ->info('Allowed mime types')
                            ->isRequired()
                        ->end()
                        ->scalarNode('naming_strategy')
                            ->info("File naming strategy, 'date' and 'datetime' are valid values")
                            ->defaultNull()
                        ->end()
                        ->scalarNode('target_directory')
                            ->info("If set, files will be automatically moved to this folder after upload")
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
