<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\Volume;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Throwable;
use viesrood\mybooks\models\Book;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\BookRecord;
use yii\base\Component;

/**
 * Keeps a local copy of every cover in a Craft volume.
 *
 * Why: a cover linked straight from the book service makes every visitor's
 * browser contact that service (their IP address included) and breaks when
 * the service moves a file. A local asset also works with image transforms.
 *
 * A cover is downloaded once. It is fetched again only when the book service
 * reports a different cover URL, which is what coverSourceUrl tracks.
 */
class CoversService extends Component
{
    /** Covers above this size are refused; a book cover is never this big. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private ?ClientInterface $client = null;

    public function getVolume(): ?Volume
    {
        $uid = Plugin::getInstance()?->getSettings()->coverVolume;

        if ($uid === null || $uid === '') {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByUid($uid);
    }

    public function isEnabled(): bool
    {
        return $this->getVolume() !== null;
    }

    /**
     * Downloads the cover of a book when it has none yet or the source URL
     * changed. Returns true when the stored cover changed.
     */
    public function downloadForBook(Book $book): bool
    {
        $volume = $this->getVolume();

        if ($volume === null || $book->id === null) {
            return false;
        }

        if ($book->coverUrl === null) {
            // The service dropped the cover: remove our copy too.
            if ($book->coverAssetId !== null && $book->coverSourceUrl !== null) {
                $this->deleteCover($book);

                return true;
            }

            return false;
        }

        // Already fetched, or already found unusable.
        if ($book->coverSourceUrl === $book->coverUrl) {
            return false;
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . '/mybooks-' . StringHelper::randomString(12);

        try {
            $extension = $this->fetch($book->coverUrl, $tempPath);
            $asset = $this->saveAsset($volume, $book, $tempPath, $extension);
        } catch (CoverRejectedException|ClientException $e) {
            // This URL will never give a usable cover (a placeholder, a 404,
            // not an image). Remember that, so the next sync does not fetch
            // it again; a different URL from the service is tried as usual.
            Db::update(BookRecord::TABLE, ['coverSourceUrl' => $book->coverUrl], ['id' => $book->id]);
            $book->coverSourceUrl = $book->coverUrl;
            Craft::info(sprintf('No usable cover for “%s”: %s', $book->title, $e->getMessage()), 'mybooks');

            return false;
        } catch (Throwable $e) {
            // A missing cover is cosmetic; the next sync tries again.
            Craft::warning(sprintf('Could not store the cover of “%s”: %s', $book->title, $e->getMessage()), 'mybooks');

            return false;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        $previous = $book->coverSourceUrl !== null ? $book->coverAssetId : null;

        Db::update(BookRecord::TABLE, [
            'coverAssetId' => $asset->id,
            'coverSourceUrl' => $book->coverUrl,
        ], ['id' => $book->id]);

        $book->coverAssetId = (int)$asset->id;
        $book->coverSourceUrl = $book->coverUrl;
        $book->setCover($asset);

        if ($previous !== null && $previous !== (int)$asset->id) {
            $this->deleteAsset($previous);
        }

        return true;
    }

    /**
     * Removes a cover this plugin downloaded. Covers an editor picked from
     * the asset library are never touched (coverSourceUrl is null for those).
     */
    public function deleteCover(Book $book): void
    {
        if ($book->coverAssetId === null || $book->coverSourceUrl === null) {
            return;
        }

        $this->deleteAsset($book->coverAssetId);

        if ($book->id !== null) {
            Db::update(BookRecord::TABLE, ['coverAssetId' => null, 'coverSourceUrl' => null], ['id' => $book->id]);
        }

        $book->coverAssetId = null;
        $book->coverSourceUrl = null;
        $book->setCover(null);
    }

    public function deleteCoversOfReader(int $readerId): void
    {
        $ids = BookRecord::find()
            ->select(['coverAssetId'])
            ->where(['readerId' => $readerId])
            ->andWhere(['not', ['coverAssetId' => null]])
            ->andWhere(['not', ['coverSourceUrl' => null]])
            ->column();

        foreach ($ids as $id) {
            $this->deleteAsset((int)$id);
        }
    }

    /**
     * Downloads a cover to $tempPath and returns its file extension.
     *
     * @throws \RuntimeException
     */
    private function fetch(string $url, string $tempPath): string
    {
        self::assertPublicUrl($url);

        $response = $this->client()->request('GET', $url, [
            'sink' => $tempPath,
            // Open Library covers redirect to archive.org; every hop is
            // checked, so a redirect can never point the server inwards.
            'allow_redirects' => [
                'max' => 3,
                'protocols' => ['https', 'http'],
                'on_redirect' => static function ($request, $response, $uri): void {
                    self::assertPublicUrl((string)$uri);
                },
            ],
            // Refuse oversized bodies before they are written in full.
            'on_headers' => static function ($response): void {
                $length = (int)$response->getHeaderLine('Content-Length');

                if ($length > self::MAX_BYTES) {
                    throw new CoverRejectedException('Cover is too large.');
                }
            },
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new CoverRejectedException('HTTP ' . $response->getStatusCode());
        }

        $size = filesize($tempPath);

        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new CoverRejectedException('Cover is empty or too large.');
        }

        // Trust the bytes, not the Content-Type header or the URL.
        $mimeType = FileHelper::getMimeType($tempPath, null, false);

        if (!is_string($mimeType) || !isset(self::ALLOWED_TYPES[$mimeType])) {
            throw new CoverRejectedException('Not an image: ' . (is_string($mimeType) ? $mimeType : 'unknown'));
        }

        // Open Library answers a missing cover with a 1x1 pixel GIF.
        $dimensions = @getimagesize($tempPath);

        if ($dimensions === false || $dimensions[0] < 10 || $dimensions[1] < 10) {
            throw new CoverRejectedException('Placeholder image, not a cover.');
        }

        return self::ALLOWED_TYPES[$mimeType];
    }

    /**
     * Refuses URLs that resolve to a private, loopback or reserved address.
     * Cover URLs come from remote services (and, for hand-entered books, from
     * a form), so the server must never be tricked into fetching an internal
     * resource.
     *
     * @throws \RuntimeException
     */
    public static function assertPublicUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new CoverRejectedException('Not a web URL.');
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            throw new \RuntimeException('Host does not resolve.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new CoverRejectedException('Host resolves to a private address.');
            }
        }
    }

    private function saveAsset(Volume $volume, Book $book, string $tempPath, string $extension): Asset
    {
        $settings = Plugin::getInstance()?->getSettings();
        $folderPath = $settings?->getCoverFolderPath() ?? 'mybooks';
        $assets = Craft::$app->getAssets();

        $folder = $folderPath !== ''
            ? $assets->ensureFolderByFullPathAndVolume($folderPath, $volume)
            : $assets->getRootFolderByVolumeId((int)$volume->id);

        if ($folder === null) {
            throw new \RuntimeException('The cover folder could not be created.');
        }

        $reader = $book->getReader();
        $slug = StringHelper::slugify($book->title) ?: 'book';
        $filename = sprintf('%s-%s-%s.%s', $reader->handle ?? 'reader', $slug, $book->externalId, $extension);

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename(mb_substr(FileHelper::sanitizeFilename($filename, ['asciiOnly' => true]), 0, 200));
        $asset->newFolderId = (int)$folder->id;
        $asset->setVolumeId((int)$volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->title = $book->title;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \RuntimeException(implode(' ', $asset->getErrorSummary(true)));
        }

        return $asset;
    }

    public function deleteAsset(int $assetId): void
    {
        $asset = Asset::find()->id($assetId)->status(null)->one();

        if ($asset !== null) {
            Craft::$app->getElements()->deleteElement($asset, true);
        }
    }

    private function client(): ClientInterface
    {
        if ($this->client === null) {
            $timeout = Plugin::getInstance()?->getSettings()->timeout ?? 10;

            $client = Craft::createGuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => min(5, $timeout),
                'headers' => ['User-Agent' => 'MyBooks for Craft CMS (https://github.com/viesrood/mybooks)'],
            ]);

            $this->client = $client;
        }

        return $this->client;
    }
}
