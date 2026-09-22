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
use viesrood\mybooks\Plugin;

/**
 * Shared plumbing for a book service: an HTTP client with a hard timeout,
 * JSON decoding, and fetchShelves() that fetches shelf by shelf and reports
 * failures per shelf.
 *
 * Only ever called from the sync, the field's search and the connection
 * test, never while rendering a page.
 */
abstract class Provider
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

    abstract public static function displayName(): string;

    /**
     * Fetches one shelf of an account.
     *
     * @return BookData[]
     * @throws ProviderException
     */
    abstract protected function fetchShelf(string $account, Shelf $shelf, int $limit): array;

    /**
     * Checks an account and returns a short success message.
     *
     * @throws ProviderException
     */
    abstract public function testConnection(string $account): string;

    /**
     * Fetches the given shelves. A failing shelf ends up in the result's
     * errors instead of throwing, so the other shelves still load.
     *
     * @param Shelf[] $shelves
     */
    public function fetchShelves(string $account, array $shelves, int $limit): FetchResult
    {
        $result = new FetchResult();

        foreach ($shelves as $shelf) {
            try {
                $result->setBooks($shelf, $this->fetchShelf($account, $shelf, $limit));
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
