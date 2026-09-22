<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A Symfony HttpClient that sends its requests through Laravel's HTTP client.
 *
 * Symfony's own client uses curl_multi_exec(), which some shared hosts
 * (InfinityFree) disable. Laravel's client is Guzzle, which checks for it and
 * falls back to plain curl_exec() (or PHP streams as a last resort), so this
 * keeps Symfony-based packages such as the Brevo mail transport working there.
 *
 * Implements only what such API transports need: JSON or raw string bodies,
 * headers and blocking requests. It exists to be swapped in, not to be a
 * general-purpose replacement.
 */
class LaravelBackedHttpClient implements HttpClientInterface
{
    private const TIMEOUT_SECONDS = 20;

    private const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @param  array<string, mixed>  $defaultOptions
     */
    public function __construct(private array $defaultOptions = []) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = array_replace_recursive($this->defaultOptions, $options);

        $request = Http::withHeaders($this->normalizeHeaders($options['headers'] ?? []))
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);

        try {
            if (array_key_exists('json', $options)) {
                $response = $request->asJson()->send($method, $url, ['json' => $options['json']]);
            } elseif (is_string($options['body'] ?? null)) {
                $response = $request->withBody($options['body'], $this->normalizeHeaders($options['headers'] ?? [])['Content-Type'] ?? 'application/octet-stream')->send($method, $url);
            } else {
                $response = $request->send($method, $url);
            }
        } catch (ConnectionException $exception) {
            return new LaravelBackedResponse(null, $url, new TransportException($exception->getMessage(), 0, $exception));
        }

        return new LaravelBackedResponse($response, $url);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException(sprintf('"%s" does not support streaming responses.', static::class));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->defaultOptions = array_replace_recursive($this->defaultOptions, $options);

        return $clone;
    }

    /**
     * Symfony accepts headers as ["Name" => "value"] or ["Name: value"].
     *
     * @param  array<int|string, string|list<string>>  $headers
     * @return array<string, string|list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = array_map('trim', explode(':', $value, 2));
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }
}
