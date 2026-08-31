<?php

namespace AssistantHub\SymfonyConnector\Tests\Fixtures;

use AssistantHub\SymfonyConnector\AssistantHubConnectorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LocalJourneyKernel extends Kernel
{
    use MicroKernelTrait;

    public static function databasePath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'assistant-hub-connector-local-journey.sqlite';
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new AssistantHubConnectorBundle()];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'assistant-hub-connector-local-journey-cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'assistant-hub-connector-local-journey-log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'local-journey-framework-secret',
            'test' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
        ];
        $container->extension('framework', $framework);
        $container->extension('assistant_hub_connector', [
            'connector_id' => 'local-fixture-site',
            'connector_name' => 'Local fixture site',
            'storage_path' => self::databasePath(),
            'encryption_key' => 'local-journey-encryption-key-with-sufficient-entropy',
            'api_base_url' => 'http://site-api.test',
            'allowed_hub_redirect_uris' => ['http://hub.test/sites/callback'],
            'authentication' => [
                'login_path' => '/api/login_check',
                'refresh_path' => '/api/token/refresh',
                'username_field' => 'email',
                'password_field' => 'password',
                'access_token_field' => 'token',
                'refresh_token_field' => 'refresh_token',
                'identity_field' => 'user',
            ],
            'pairing_modes' => ['authorization_code_pkce'],
            'demo_mode' => false,
            'demo_example_capabilities' => false,
            'proposal_ttl_seconds' => 600,
            'capabilities' => [
                'local_record_list' => [
                    'id' => 'local.record.list',
                    'version' => '1.0',
                    'kind' => 'read',
                    'title' => 'List local records',
                    'description' => 'Search local records by title.',
                    'method' => 'GET',
                    'path' => '/api/records',
                    'required_roles' => ['ROLE_HUB_USER'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'maxLength' => 80, 'description' => 'Record title search'],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                        ],
                        'additionalProperties' => false,
                    ],
                    'output_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'id' => ['type' => 'string', 'title' => 'Record identifier'],
                                        'title' => [
                                            'type' => 'string',
                                            'title' => 'Record title',
                                            'description' => 'Human-readable title of the local record.',
                                            'x-assistant-hub-list-label' => 'the record titles',
                                            'x-assistant-hub-primary' => true,
                                        ],
                                    ],
                                ],
                            ],
                            'count' => ['type' => 'integer'],
                        ],
                    ],
                    'input_mapping' => ['query' => 'q', 'limit' => 'limit'],
                    'response' => [
                        'collection_paths' => ['records'],
                        'search_input' => 'query',
                        'search_fields' => ['title'],
                        'fields' => ['id', 'title'],
                        'limit_input' => 'limit',
                        'max_items' => 10,
                    ],
                ],
                'local_record_create' => [
                    'id' => 'local.record.create',
                    'version' => '1.0',
                    'kind' => 'write',
                    'title' => 'Create a local record',
                    'description' => 'Create one local fixture record after exact confirmation.',
                    'method' => 'POST',
                    'path' => '/api/records',
                    'required_roles' => ['ROLE_HUB_EDITOR'],
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => ['title' => ['type' => 'string', 'maxLength' => 120]],
                        'required' => ['title'],
                        'additionalProperties' => false,
                    ],
                    'output_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'record' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                ],
                                'required' => ['id', 'title'],
                            ],
                            'idempotencyKey' => ['type' => 'string'],
                        ],
                        'required' => ['record', 'idempotencyKey'],
                    ],
                    'input_mapping' => ['title' => 'title'],
                    'preview' => 'Create one local fixture record after exact confirmation.',
                ],
            ],
        ]);

        $services = $container->services();
        $services->set(LocalSiteApiHttpClient::class)->public();
        $services->alias(HttpClientInterface::class, LocalSiteApiHttpClient::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__, 2).'/config/routes.yaml');
    }
}
