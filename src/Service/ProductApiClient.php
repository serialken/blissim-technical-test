<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ProductApiException;
use App\Model\Product;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the product catalog from the external API.
 * Responses are cached for a few minutes to avoid calling the API on every page.
 */
final class ProductApiClient
{
    private const CACHE_TTL = 300;      // seconds
    private const TIMEOUT = 5;          // max idle time while waiting for the API, in seconds
    private const MAX_DURATION = 10;    // max total duration of a request, in seconds

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @return list<Product>
     *
     * @throws ProductApiException
     */
    public function findAll(): array
    {
        return $this->cache->get('products.all', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            $data = $this->request('/products');
            if (!is_array($data) || !array_is_list($data)) {
                throw ProductApiException::invalidPayload($this->url('/products'), 'a list of products was expected');
            }

            return array_map(fn (mixed $row): Product => $this->hydrate($row, '/products'), $data);
        });
    }

    /**
     * Returns null when the product does not exist.
     *
     * @throws ProductApiException
     */
    public function find(int $id): ?Product
    {
        $path = sprintf('/products/%d', $id);

        return $this->cache->get(sprintf('products.%d', $id), function (ItemInterface $item) use ($path): ?Product {
            $item->expiresAfter(self::CACHE_TTL);

            $data = $this->request($path);

            return null === $data ? null : $this->hydrate($data, $path);
        });
    }

    /**
     * Calls the API and returns the decoded JSON, or null when the resource does not exist.
     *
     * @throws ProductApiException
     */
    private function request(string $path): mixed
    {
        $url = $this->url($path);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $status = $response->getStatusCode();
            // false: do not throw on 3xx/4xx/5xx, the status is checked below
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw ProductApiException::unreachable($url, $e);
        }

        if (404 === $status) {
            return null;
        }
        if (200 !== $status) {
            throw ProductApiException::unexpectedStatus($url, $status);
        }
        // FakeStoreAPI answers 200 with an empty body for an unknown product id
        if ('' === trim($content)) {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ProductApiException::invalidPayload($url, 'invalid JSON', $e);
        }
    }

    /**
     * @throws ProductApiException
     */
    private function hydrate(mixed $row, string $path): Product
    {
        if (!is_array($row)) {
            throw ProductApiException::invalidPayload($this->url($path), 'a product object was expected');
        }

        try {
            return Product::fromArray($row);
        } catch (\InvalidArgumentException $e) {
            throw ProductApiException::invalidPayload($this->url($path), $e->getMessage(), $e);
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}