<?php

namespace AssistantHub\SymfonyConnector\Service;

use AssistantHub\SymfonyConnector\Protocol\ProtocolException;
use AssistantHub\SymfonyConnector\Registry\AdapterRegistry;
use AssistantHub\SymfonyConnector\Store\ConnectorStore;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SiteApiClient
{
    /** @param array<string, mixed> $authentication */
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConnectorStore $store,
        private AdapterRegistry $adapters,
        private string $baseUrl,
        private array $authentication,
    ) {
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The site API base URL must be absolute HTTP(S).');
        }
    }

    public function login(string $username, string $password): string
    {
        if ('' === trim($username) || '' === $password) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'Identifiants incomplets.', 401);
        }
        $payload = $this->request('POST', $this->authentication['login_path'], [
            'json' => [
                $this->authentication['username_field'] => $username,
                $this->authentication['password_field'] => $password,
            ],
        ], false);
        $accessToken = $payload[$this->authentication['access_token_field']] ?? null;
        $identity = $payload[$this->authentication['identity_field']] ?? null;
        if (!is_string($accessToken) || '' === $accessToken || !is_array($identity)) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'La réponse de connexion du site est incomplète.', 502);
        }
        $refreshField = $this->authentication['refresh_token_field'];
        $refreshToken = $payload[$refreshField] ?? null;
        $tokens = ['access_token' => $accessToken, 'refresh_token' => is_string($refreshToken) ? $refreshToken : null, 'expires_at' => $this->jwtExpiration($accessToken)];

        return $this->store->createVault($tokens, $identity);
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $input @return array<string, mixed> */
    public function execute(string $pairId, array $config, array $input, ?string $idempotencyKey = null): array
    {
        $pair = $this->store->pair($pairId);
        $vault = $this->freshVault($pair['vaultId']);
        $adapter = $this->adapters->for((string) $config['id']);
        $request = $adapter?->buildRequest($config, $input) ?? $this->genericRequest($config, $input);
        $method = strtoupper((string) $config['method']);
        $path = $this->resolvePath((string) $config['path'], $input);
        if (($request['path'] ?? $path) !== $path) {
            throw new \LogicException('A site adapter cannot change the resolved declared API path.');
        }
        $options = [
            'headers' => ['Accept' => $this->accept($config), 'Authorization' => 'Bearer '.$vault['tokens']['access_token']],
            'timeout' => (float) ($config['timeout_seconds'] ?? 8),
            'max_duration' => (float) ($config['max_duration_seconds'] ?? 10),
            'max_redirects' => 0,
        ];
        if ('GET' !== $method && isset($config['content_type'])) {
            $options['headers']['Content-Type'] = $this->jsonContentType($config['content_type']);
        }
        if (null !== $idempotencyKey) {
            $declaredKind = (string) ($config['kind'] ?? ('GET' === strtoupper((string) $config['method']) ? 'read' : 'write'));
            if ('write' !== $declaredKind) {
                throw new \LogicException('An idempotency key is only valid for a declared write capability.');
            }
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        foreach (['query', 'json'] as $option) {
            if (isset($request[$option]) && is_array($request[$option])) {
                $options[$option] = $request[$option];
            }
        }
        $payload = $this->request($method, $path, $options, true);

        return $adapter?->normalizeResponse($config, $payload) ?? $this->genericResponse($config, $input, $payload);
    }

    private function accept(array $config): string
    {
        return $this->mediaType($config['accept'] ?? 'application/json', 'Accept');
    }

    private function mediaType(mixed $value, string $header): string
    {
        if (!is_string($value) || 1 !== preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~iD', $value)) {
            throw new \LogicException(sprintf('A capability %s media type must be a single valid MIME type.', $header));
        }

        return $value;
    }

    private function jsonContentType(mixed $value): string
    {
        $mediaType = $this->mediaType($value, 'Content-Type');
        if ('application/json' !== strtolower($mediaType) && !str_ends_with(strtolower($mediaType), '+json')) {
            throw new \LogicException('A capability Content-Type must describe a JSON representation.');
        }

        return $mediaType;
    }

    private function freshVault(string $vaultId): array
    {
        $vault = $this->store->vault($vaultId);
        $expiresAt = (int) ($vault['tokens']['expires_at'] ?? 0);
        if ($expiresAt > time() + 30) {
            return $vault;
        }
        $refreshToken = $vault['tokens']['refresh_token'] ?? null;
        if (!is_string($refreshToken) || '' === $refreshToken) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'La connexion au site a expiré.', 401);
        }
        $payload = $this->request('POST', $this->authentication['refresh_path'], ['json' => [$this->authentication['refresh_token_field'] => $refreshToken]], false);
        $accessToken = $payload[$this->authentication['access_token_field']] ?? null;
        if (!is_string($accessToken) || '' === $accessToken) {
            throw new ProtocolException('AUTHENTICATION_FAILED', 'Le site a refusé le renouvellement de la connexion.', 401);
        }
        $newRefresh = $payload[$this->authentication['refresh_token_field']] ?? $refreshToken;
        $tokens = ['access_token' => $accessToken, 'refresh_token' => is_string($newRefresh) ? $newRefresh : $refreshToken, 'expires_at' => $this->jwtExpiration($accessToken)];
        $this->store->updateVault($vaultId, $tokens, $vault['identity']);

        return ['id' => $vaultId, 'actorId' => $vault['actorId'], 'tokens' => $tokens, 'identity' => $vault['identity']];
    }

    private function request(string $method, string $path, array $options, bool $mapProtocolErrors): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, '://') || str_contains($path, '..')) {
            throw new \LogicException('The configured API path is unsafe.');
        }
        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $payload = '' === $content ? [] : json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (TransportExceptionInterface|\JsonException) {
            throw new ProtocolException('SITE_API_UNAVAILABLE', 'L’API du site ne répond pas correctement.', 502, true);
        }
        if ($status >= 400) {
            $code = match ($status) { 401 => 'AUTHENTICATION_FAILED', 403 => 'LOCAL_POLICY_DENIED', 404 => 'SITE_RESOURCE_NOT_FOUND', 409 => 'CONFLICT', default => 'SITE_API_ERROR' };
            $message = $mapProtocolErrors ? 'L’API du site a refusé ou échoué à traiter la demande.' : 'Les identifiants ont été refusés par le site.';
            throw new ProtocolException($code, $message, $status >= 500 ? 502 : $status, $status >= 500);
        }
        if (!is_array($payload)) {
            throw new ProtocolException('SITE_API_ERROR', 'L’API du site a renvoyé un format inattendu.', 502);
        }

        return $payload;
    }

    /** @param array<string, mixed> $input */
    private function resolvePath(string $template, array $input): string
    {
        $path = preg_replace_callback('/\{([A-Za-z][A-Za-z0-9_]*)\}/', static function (array $matches) use ($input): string {
            $name = $matches[1];
            $value = $input[$name] ?? null;
            if ((!is_string($value) && !is_int($value)) || '' === trim((string) $value)) {
                throw new ProtocolException('INVALID_INPUT', sprintf('Path parameter "%s" is missing or invalid.', $name), 422);
            }

            return rawurlencode((string) $value);
        }, $template);
        if (!is_string($path) || str_contains($path, '{') || str_contains($path, '}')) {
            throw new \LogicException('The configured API path template is invalid.');
        }

        return $path;
    }

    private function genericRequest(array $config, array $input): array
    {
        $target = 'GET' === strtoupper((string) $config['method']) ? 'query' : 'json';
        $mapped = [];
        foreach (($config['input_mapping'] ?? []) as $inputName => $apiName) {
            if (is_string($apiName) && array_key_exists($inputName, $input)) {
                $mapped[$apiName] = $input[$inputName];
            }
        }

        return [$target => $mapped];
    }

    private function genericResponse(array $config, array $input, array $payload): array
    {
        $response = is_array($config['response'] ?? null) ? $config['response'] : [];
        $items = $payload;
        foreach (($response['collection_paths'] ?? []) as $path) {
            if (is_string($path) && isset($payload[$path]) && is_array($payload[$path])) {
                $items = $payload[$path];
                break;
            }
        }
        if (!array_is_list($items)) {
            return $payload;
        }
        $queryName = (string) ($response['search_input'] ?? '');
        $query = $queryName && is_string($input[$queryName] ?? null) ? mb_strtolower(trim($input[$queryName])) : '';
        $searchFields = array_values(array_filter($response['search_fields'] ?? [], 'is_string'));
        $fields = array_values(array_filter($response['fields'] ?? [], 'is_string'));
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ('' !== $query && [] !== $searchFields) {
                $haystack = implode(' ', array_map(static fn (string $field): string => (string) ($item[$field] ?? ''), $searchFields));
                if (!str_contains(mb_strtolower($haystack), $query)) {
                    continue;
                }
            }
            $normalized[] = [] === $fields ? $item : array_intersect_key($item, array_flip($fields));
        }
        $limitName = (string) ($response['limit_input'] ?? '');
        $limit = $limitName && is_int($input[$limitName] ?? null) ? $input[$limitName] : (int) ($response['max_items'] ?? 50);
        $normalized = array_slice($normalized, 0, max(1, min($limit, (int) ($response['max_items'] ?? 50))));

        return ['items' => $normalized, 'count' => count($normalized)];
    }

    private function jwtExpiration(string $token): int
    {
        $parts = explode('.', $token);
        if (3 === count($parts)) {
            $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (is_array($payload) && is_int($payload['exp'] ?? null)) {
                return $payload['exp'];
            }
        }
        return time() + 300;
    }
}
