<?php

namespace AssistantHub\SymfonyConnector\DependencyInjection;

use AssistantHub\SymfonyConnector\Contract\PairingIdentityProviderInterface;
use AssistantHub\SymfonyConnector\Security\ApiTokenPairingIdentityProvider;
use AssistantHub\SymfonyConnector\Security\SymfonySessionPairingIdentityProvider;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class AssistantHubConnectorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        if ($config['demo_mode'] && strlen($config['demo_pair_key']) < 16) {
            throw new \InvalidArgumentException('assistant_hub_connector.demo_pair_key must contain at least 16 characters in demo mode.');
        }
        if (!$config['demo_mode'] && in_array('demo', $config['pairing_modes'], true)) {
            throw new \InvalidArgumentException('The demo pairing mode cannot be enabled when demo_mode is false.');
        }
        if ('api_token' === $config['pairing_identity_provider'] && '' === trim((string) $config['api_base_url'])) {
            throw new \InvalidArgumentException('assistant_hub_connector.api_base_url is required with the api_token identity provider.');
        }

        foreach (['connector_id', 'connector_name', 'storage_path', 'encryption_key', 'pairing_identity_provider', 'api_base_url', 'allowed_hub_redirect_uris', 'authentication', 'pairing_modes', 'demo_mode', 'demo_pair_key', 'demo_example_capabilities', 'proposal_ttl_seconds'] as $name) {
            $container->setParameter('assistant_hub_connector.'.$name, $config[$name]);
        }
        if (!is_array($config['capabilities'])) {
            throw new \InvalidArgumentException('assistant_hub_connector.capabilities must be a map.');
        }
        foreach ($config['capabilities'] as $name => $capability) {
            if (!is_string($name) || !is_array($capability)) {
                throw new \InvalidArgumentException('Every configured capability must be a named map.');
            }
            $definition = (new Definition(\AssistantHub\SymfonyConnector\Capability\ConfiguredApiCapability::class))
                ->setAutowired(true)->setArgument('$config', $capability)
                ->addTag('assistant_hub_connector.capability');
            $container->setDefinition('assistant_hub_connector.capability.'.$name, $definition);
        }

        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2).'/config'));
        $loader->load('services.yaml');
        if ('symfony_session' === $config['pairing_identity_provider']) {
            $container->setDefinition(SymfonySessionPairingIdentityProvider::class, (new Definition(SymfonySessionPairingIdentityProvider::class))->setAutowired(true));
            $container->setAlias(PairingIdentityProviderInterface::class, SymfonySessionPairingIdentityProvider::class);
        } else {
            $container->setAlias(PairingIdentityProviderInterface::class, ApiTokenPairingIdentityProvider::class);
        }
        if ($config['demo_mode']) {
            $container->setAlias(\AssistantHub\SymfonyConnector\Contract\PairAuthenticatorInterface::class, \AssistantHub\SymfonyConnector\Security\DemoPairAuthenticator::class);
            $container->setAlias(\AssistantHub\SymfonyConnector\Contract\LocalAuthorizationInterface::class, \AssistantHub\SymfonyConnector\Security\DemoLocalAuthorization::class);
        }
        if ($config['demo_mode'] && $config['demo_example_capabilities']) {
            $loader->load('examples.yaml');
        }
    }
}
