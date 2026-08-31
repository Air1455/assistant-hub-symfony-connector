<?php

namespace AssistantHub\SymfonyConnector\Tests\Fixtures;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** API officielle locale et déterministe, réservée à la recette E2E du dépôt. */
final class LocalSiteApiHttpClient implements HttpClientInterface
{
    private HttpClientInterface $client;
    public int $writeCalls = 0;
    /** @var list<array{method: string, url: string, idempotencyKey: ?string}> */
    public array $requests = [];

    public function __construct()
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $idempotencyKey = $this->header($options, 'idempotency-key');
            $this->requests[] = ['method' => $method, 'url' => $url, 'idempotencyKey' => $idempotencyKey];

            if ('POST' === $method && '/api/login_check' === $path) {
                $input = $this->jsonBody($options);
                if ('tester@example.test' !== ($input['email'] ?? null) || 'test-only-password' !== ($input['password'] ?? null)) {
                    return $this->json(['error' => 'invalid credentials'], 401);
                }

                return $this->json([
                    'token' => $this->jwt(time() + 3600),
                    'refresh_token' => 'local-refresh-token',
                    'user' => [
                        'id' => 'local-user-1',
                        'email' => 'tester@example.test',
                        'roles' => ['ROLE_HUB_USER', 'ROLE_HUB_EDITOR'],
                    ],
                ]);
            }

            if ('GET' === $method && '/api/records' === $path) {
                return $this->json(['records' => [
                    ['id' => 'record-alpha', 'title' => 'Alpha local record', 'private' => 'not exposed'],
                    ['id' => 'record-beta', 'title' => 'Beta local record', 'private' => 'not exposed'],
                ]]);
            }

            if ('POST' === $method && '/api/records' === $path) {
                if (null === $idempotencyKey || !str_starts_with($idempotencyKey, 'write_')) {
                    return $this->json(['error' => 'idempotency required'], 409);
                }
                ++$this->writeCalls;
                $input = $this->jsonBody($options);

                return $this->json([
                    'record' => ['id' => 'record-created-1', 'title' => (string) ($input['title'] ?? '')],
                    'idempotencyKey' => $idempotencyKey,
                ], 201);
            }

            return $this->json(['error' => 'unknown local fixture endpoint'], 404);
        }, 'http://site-api.test');
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    /** @return array<string, mixed> */
    private function jsonBody(array $options): array
    {
        $body = $options['body'] ?? '';
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function header(array $options, string $expected): ?string
    {
        foreach (($options['normalized_headers'][$expected] ?? []) as $line) {
            if (is_string($line) && str_contains($line, ':')) {
                return trim(substr($line, strpos($line, ':') + 1));
            }
        }
        foreach (($options['headers'] ?? []) as $name => $value) {
            if (is_string($name) && strtolower($name) === $expected && is_string($value)) {
                return $value;
            }
            if (is_string($value) && str_starts_with(strtolower($value), $expected.':')) {
                return trim(substr($value, strlen($expected) + 1));
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type: application/json'],
        ]);
    }

    private function jwt(int $expiresAt): string
    {
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode(['exp' => $expiresAt]).'.local';
    }
}
