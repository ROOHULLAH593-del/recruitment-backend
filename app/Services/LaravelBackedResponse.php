<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A Symfony HttpClient response wrapping a Laravel HTTP client response.
 *
 * Mirrors Symfony's lazy error semantics: a network failure is stored and only
 * thrown once the response is inspected, which is where consumers such as the
 * Brevo mail transport catch it.
 */
class LaravelBackedResponse implements ResponseInterface
{
    public function __construct(
        private readonly ?Response $response,
        private readonly string $url,
        private readonly ?TransportException $failure = null,
    ) {}

    public function getStatusCode(): int
    {
        return $this->resolved()->status();
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(bool $throw = true): array
    {
        $response = $this->resolved();
        $this->throwOnHttpError($throw);

        return array_change_key_case($response->headers(), CASE_LOWER);
    }

    public function getContent(bool $throw = true): string
    {
        $response = $this->resolved();
        $this->throwOnHttpError($throw);

        return $response->body();
    }

    /**
     * @return array<mixed>
     */
    public function toArray(bool $throw = true): array
    {
        $content = $this->getContent($throw);

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new JsonException($exception->getMessage().sprintf(' for "%s".', $this->url), $exception->getCode());
        }

        if (! is_array($decoded)) {
            throw new JsonException(sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', get_debug_type($decoded), $this->url));
        }

        return $decoded;
    }

    public function cancel(): void {}

    public function getInfo(?string $type = null): mixed
    {
        $status = $this->response?->status() ?? 0;

        $headerLines = [sprintf('HTTP/1.1 %d', $status)];

        foreach ($this->response?->headers() ?? [] as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = $name.': '.$value;
            }
        }

        $info = [
            'http_code' => $status,
            'url' => $this->url,
            'response_headers' => $headerLines,
            'canceled' => false,
            'error' => $this->failure?->getMessage(),
        ];

        return $type === null ? $info : ($info[$type] ?? null);
    }

    private function resolved(): Response
    {
        if ($this->failure !== null || $this->response === null) {
            throw $this->failure ?? new TransportException(sprintf('No response was received for "%s".', $this->url));
        }

        return $this->response;
    }

    private function throwOnHttpError(bool $throw): void
    {
        if (! $throw) {
            return;
        }

        $status = $this->getStatusCode();

        if ($status >= 500) {
            throw new ServerException($this);
        }

        if ($status >= 400) {
            throw new ClientException($this);
        }

        if ($status >= 300) {
            throw new RedirectionException($this);
        }
    }
}
