<?php

declare(strict_types=1);

namespace viesrood\mybooks\base;

use Craft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\BookData;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;

/**
 * Shared plumbing: an HTTP client with a hard timeout, JSON decoding, and a
 * default fetchShelves() that fetches shelf by shelf.
 */
abstract class Provider implements ProviderInterface
{
    private ?ClientInterface $client;

    /**
     * @param ClientInterface|null $client Injected in tests; otherwise Craft's
     *                                     Guzzle client with the plugin timeout.
     */
    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client;
    }

    public function supportsSync(): bool
    {
        return true;
    }

    public function requiresAccount(): bool
    {
        return false;
    }

    public function requiresToken(): bool
    {
        return false;
    }

    /**
     * Fetches one shelf. Override this, or fetchShelves() when the service can
     * return several shelves in one request.
     *
     * @return BookData[]
     * @throws ProviderException
     */
    protected function fetchShelf(Reader $reader, Shelf $shelf, int $limit): array
    {
        return [];
    }

    public function fetchShelves(Reader $reader, array $shelves, int $limit): FetchResult
    {
        $result = new FetchResult();

        foreach ($shelves as $shelf) {
            try {
                $result->setBooks($shelf, $this->fetchShelf($reader, $shelf, $limit));
            } catch (ProviderException $e) {
                $result->setError($shelf, $e->getMessage());
            }
        }

        return $result;
    }

    protected function client(): ClientInterface
    {
        if ($this->client === null) {
            $timeout = 10;
            $plugin = Plugin::getInstance();

            if ($plugin !== null) {
                $timeout = $plugin->getSettings()->timeout;
            }

            $client = Craft::createGuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => min(5, $timeout),
                'http_errors' => true,
                'headers' => [
                    'Accept' => 'application/json',
                    // Both Open Library and Hardcover ask scripts to identify themselves.
                    'User-Agent' => 'MyBooks for Craft CMS (https://github.com/viesrood/mybooks)',
                ],
            ]);

            $this->client = $client;
        }

        return $this->client;
    }

    /**
     * Sends a request and decodes the JSON body. Every transport or HTTP
     * problem becomes a ProviderException; $statusMessages turns known status
     * codes into a clear message.
     *
     * @param array<string, mixed> $options
     * @param array<int, string> $statusMessages
     * @return array<mixed>
     * @throws ProviderException
     */
    protected function requestJson(string $method, string $url, array $options = [], array $statusMessages = []): array
    {
        try {
            $response = $this->client()->request($method, $url, $options);
        } catch (ConnectException $e) {
            throw new ProviderException(Craft::t('mybooks', '{provider} could not be reached. Try again later.', [
                'provider' => static::displayName(),
            ]), 0, $e);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;

            throw new ProviderException($statusMessages[$status] ?? Craft::t('mybooks', '{provider} answered with HTTP {status}.', [
                'provider' => static::displayName(),
                'status' => $status,
            ]), $status, $e);
        } catch (GuzzleException $e) {
            throw new ProviderException(Craft::t('mybooks', '{provider} could not be reached. Try again later.', [
                'provider' => static::displayName(),
            ]), 0, $e);
        }

        $data = json_decode((string)$response->getBody(), true);

        if (!is_array($data)) {
            throw new ProviderException(Craft::t('mybooks', '{provider} returned something that is not JSON.', [
                'provider' => static::displayName(),
            ]));
        }

        return $data;
    }
}
