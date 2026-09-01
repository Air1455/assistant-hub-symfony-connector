<?php

namespace AssistantHub\SymfonyConnector\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('assistant_hub_connector');
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('connector_id')->defaultValue('symfony-site')->cannotBeEmpty()->end()
                ->scalarNode('connector_name')->defaultValue('Site Symfony')->cannotBeEmpty()->end()
                ->scalarNode('storage_path')->defaultValue('%kernel.project_dir%/var/assistant-hub/connector.sqlite')->cannotBeEmpty()->end()
                ->scalarNode('encryption_key')->isRequired()->cannotBeEmpty()->end()
                ->enumNode('pairing_identity_provider')
                    ->values(['api_token', 'symfony_session'])
                    ->defaultValue('api_token')
                ->end()
                ->scalarNode('api_base_url')->defaultValue('')->end()
                ->arrayNode('allowed_hub_redirect_uris')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('authentication')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('login_path')->defaultValue('/api/login_check')->cannotBeEmpty()->end()
                        ->scalarNode('refresh_path')->defaultValue('/api/token/refresh')->cannotBeEmpty()->end()
                        ->scalarNode('revoke_path')->defaultNull()->end()
                        ->scalarNode('username_field')->defaultValue('email')->cannotBeEmpty()->end()
                        ->scalarNode('password_field')->defaultValue('password')->cannotBeEmpty()->end()
                        ->scalarNode('access_token_field')->defaultValue('token')->cannotBeEmpty()->end()
                        ->scalarNode('refresh_token_field')->defaultValue('refresh_token')->cannotBeEmpty()->end()
                        ->scalarNode('identity_field')->defaultValue('user')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->variableNode('capabilities')->defaultValue([])->end()
                ->arrayNode('pairing_modes')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Modes réellement disponibles, par exemple demo ou one_time_code.')
                ->end()
                ->booleanNode('demo_mode')
                    ->defaultFalse()
                    ->info('Active les implémentations fictives. Interdit en production.')
                ->end()
                ->scalarNode('demo_pair_key')->defaultValue('')->end()
                ->booleanNode('demo_example_capabilities')
                    ->defaultTrue()
                    ->info('Charge les capacités fictives lorsque le mode démonstration est actif.')
                ->end()
                ->integerNode('proposal_ttl_seconds')->defaultValue(600)->min(60)->max(3600)->end()
            ->end();

        return $treeBuilder;
    }
}
