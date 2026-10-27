<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ProductApiException;
use App\Model\Product;
use App\Service\ProductApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProductApiClientTest extends TestCase
{
    private const BASE_URL = 'https://api.test';

    private MockHttpClient $httpClient;

    public function testFindAllReturnsProducts(): void
    {
        $client = $this->createClient(
            new MockResponse(json_encode([$this->productPayload(1), $this->productPayload(2)])),
        );

        $products = $client->findAll();

        self::assertCount(2, $products);
        self::assertContainsOnlyInstancesOf(Product::class, $products);
        self::assertSame(1, $products[0]->id);
        self::assertSame('Product 1', $products[0]->title);
        self::assertSame(19.9, $products[0]->price);
    }

    public function testFindAllIsCached(): void
    {
        $client = $this->createClient(
            new MockResponse(json_encode([$this->productPayload(1)])),
        );

        $client->findAll();
        $client->findAll();

        self::assertSame(1, $this->httpClient->getRequestsCount());
    }

    public function testRequestUsesBaseUrlAndTimeouts(): void
    {
        $response = new MockResponse(json_encode($this->productPayload(3)));
        $client = $this->createClient($response, self::BASE_URL.'/');

        $client->find(3);

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::BASE_URL.'/products/3', $response->getRequestUrl());
        self::assertEquals(5, $response->getRequestOptions()['timeout']);
        self::assertEquals(10, $response->getRequestOptions()['max_duration']);
    }

    public function testFindReturnsProduct(): void
    {
        $client = $this->createClient(new MockResponse(json_encode($this->productPayload(3))));

        $product = $client->find(3);

        self::assertInstanceOf(Product::class, $product);
        self::assertSame(3, $product->id);
    }

    public function testFindReturnsNullOn404(): void
    {
        $client = $this->createClient(new MockResponse('', ['http_code' => 404]));

        self::assertNull($client->find(9999));
    }

    public function testFindReturnsNullOnEmptyBody(): void
    {
        $client = $this->createClient(new MockResponse(''));

        self::assertNull($client->find(9999));
    }

    public function testServerErrorThrowsException(): void
    {
        $client = $this->createClient(new MockResponse('Internal error', ['http_code' => 500]));

        $this->expectException(ProductApiException::class);
        $this->expectExceptionMessage('HTTP 500');

        $client->findAll();
    }

    public function testInvalidJsonThrowsException(): void
    {
        $client = $this->createClient(new MockResponse('{not valid json'));

        $this->expectException(ProductApiException::class);
        $this->expectExceptionMessage('invalid JSON');

        $client->findAll();
    }

    public function testUnexpectedPayloadThrowsException(): void
    {
        // An object instead of a list of products
        $client = $this->createClient(new MockResponse(json_encode(['message' => 'ok'])));

        $this->expectException(ProductApiException::class);
        $this->expectExceptionMessage('a list of products was expected');

        $client->findAll();
    }

    public function testMissingRequiredFieldThrowsException(): void
    {
        $payload = $this->productPayload(1);
        unset($payload['title']);
        $client = $this->createClient(new MockResponse(json_encode($payload)));

        $this->expectException(ProductApiException::class);
        $this->expectExceptionMessage('"title"');

        $client->find(1);
    }

    public function testTransportErrorThrowsException(): void
    {
        // Simulates a network failure (timeout, DNS error, connection refused...)
        $client = $this->createClient(new MockResponse('', ['error' => 'Idle timeout reached']));

        $this->expectException(ProductApiException::class);
        $this->expectExceptionMessage('unreachable');

        $client->findAll();
    }

    public function testErrorIsNotCached(): void
    {
        $client = $this->createClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse(json_encode([$this->productPayload(1)])),
        ]);

        try {
            $client->findAll();
            self::fail('A ProductApiException was expected on the first call.');
        } catch (ProductApiException) {
        }

        self::assertCount(1, $client->findAll());
        self::assertSame(2, $this->httpClient->getRequestsCount());
    }

    /**
     * @param MockResponse|list<MockResponse> $responses served in order, one per request
     */
    private function createClient(MockResponse|array $responses, string $baseUrl = self::BASE_URL): ProductApiClient
    {
        $this->httpClient = new MockHttpClient($responses, $baseUrl);

        return new ProductApiClient($this->httpClient, new ArrayAdapter(), $baseUrl);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(int $id): array
    {
        return [
            'id' => $id,
            'title' => sprintf('Product %d', $id),
            'price' => 19.9,
            'description' => 'Description',
            'category' => 'beauty',
            'image' => 'https://api.test/img.png',
        ];
    }
}