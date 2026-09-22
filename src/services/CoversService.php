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
use viesrood\mybooks\records\CoverRecord;
use yii\base\Component;

/**
 * Keeps a local copy of every cover in a Craft volume, keyed by the cover's
 * source URL.
 *
 * Why: a cover linked straight from Open Library makes every visitor's
 * browser contact Open Library (their IP address included). A local asset
 * also works with image transforms and ImgixKit.
 *
 * The cache is independent of books: a hand-picked and a synced book with the
 * same cover share one asset, and a URL that never gives a usable cover is
 * remembered as rejected, so it is not fetched again.
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

    /** @var array<string, Asset|null> keyed by URL */
    private array $assets = [];

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

    public function assetFor(string $url): ?Asset
    {
        if (!array_key_exists($url, $this->assets)) {
            $this->loadAssets([$url]);
        }

        return $this->assets[$url] ?? null;
    }

    /**
     * Loads the covers of a list of books in two queries.
     *
     * @param Book[] $books
     */
    public function eagerLoad(array $books): void
    {
        $urls = array_values(array_unique(array_filter(array_map(
            static fn(Book $book): ?string => $book->coverUrl,
            $books,
        ))));

        $this->loadAssets(array_values(array_filter(
            $urls,
            fn(string $url): bool => !array_key_exists($url, $this->assets),
        )));

        foreach ($books as $book) {
            $book->setCover($book->coverUrl !== null ? ($this->assets[$book->coverUrl] ?? null) : null);
        }
    }

    /**
     * Downloads covers that are not stored yet, at most $max per call.
     *
     * @param array<string, string|null> $urls Cover URL => book title (for a
     *                                         readable filename).
     * @return int The number of covers stored.
     */
    public function store(array $urls, int $max): int
    {
        $volume = $this->getVolume();

        if ($volume === null || $max <= 0 || $urls === []) {
            return 0;
        }

        $known = CoverRecord::find()
            ->select(['urlHash'])
            ->where(['urlHash' => array_map([CoverRecord::class, 'hash'], array_keys($urls))])
            ->andWhere(['or', ['status' => CoverRecord::STATUS_REJECTED], ['not', ['assetId' => null]]])
            ->column();
        $known = array_flip($known);

        $stored = 0;
        $attempts = 0;

        foreach ($urls as $url => $title) {
            if (isset($known[CoverRecord::hash($url)])) {
                continue;
            }

            if ($attempts++ >= $max) {
                break;
            }

            if ($this->download($volume, $url, $title)) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * Deletes stored covers (and their assets) that no book uses any more.
     *
     * @param string[] $inUse Every cover URL still referenced.
     * @return int The number of covers removed.
     */
    public function collectGarbage(array $inUse): int
    {
        $keep = array_flip(array_map([CoverRecord::class, 'hash'], $inUse));
        $removed = 0;

        /** @var CoverRecord[] $records */
        $records = CoverRecord::find()->all();

        foreach ($records as $record) {
            if (isset($keep[$record->urlHash])) {
                continue;
            }

            if ($record->assetId !== null) {
                $this->deleteAsset((int)$record->assetId);
                $removed++;
            }

            Db::delete(CoverRecord::TABLE, ['id' => $record->id]);
        }

        $this->assets = [];

        return $removed;
    }

    public function countStored(): int
    {
        return (int)CoverRecord::find()->where(['not', ['assetId' => null]])->count();
    }

    /**
     * @param string[] $urls
     */
    private function loadAssets(array $urls): void
    {
        if ($urls === []) {
            return;
        }

        foreach ($urls as $url) {
            $this->assets[$url] = null;
        }

        if (!Craft::$app->getDb()->tableExists(CoverRecord::TABLE)) {
            return;
        }

        $rows = CoverRecord::find()
            ->select(['url', 'assetId'])
            ->where(['urlHash' => array_map([CoverRecord::class, 'hash'], $urls)])
            ->andWhere(['not', ['assetId' => null]])
            ->asArray()
            ->all();

        $assetIds = array_map(static fn(array $row): int => (int)$row['assetId'], $rows);

        if ($assetIds === []) {
            return;
        }

        $assets = [];

        foreach (Asset::find()->id($assetIds)->status(null)->all() as $asset) {
            $assets[(int)$asset->id] = $asset;
        }

        foreach ($rows as $row) {
            $this->assets[(string)$row['url']] = $assets[(int)$row['assetId']] ?? null;
        }
    }

    private function download(Volume $volume, string $url, ?string $title): bool
    {
        $tempPath = Craft::$app->getPath()->getTempPath() . '/mybooks-' . StringHelper::randomString(12);

        try {
            $extension = $this->fetch($url, $tempPath);
            $asset = $this->saveAsset($volume, $url, $title, $tempPath, $extension);
        } catch (CoverRejectedException|ClientException $e) {
            // This URL will never give a usable cover (a placeholder, a 404,
            // not an image). Remember that, so it is not fetched again.
            $this->saveRecord($url, null, CoverRecord::STATUS_REJECTED);
            Craft::info(sprintf('No usable cover at %s: %s', $url, $e->getMessage()), 'mybooks');

            return false;
        } catch (Throwable $e) {
            // A timeout or a server error: the next run tries again.
            Craft::warning(sprintf('Could not store the cover at %s: %s', $url, $e->getMessage()), 'mybooks');

            return false;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        $this->saveRecord($url, (int)$asset->id, CoverRecord::STATUS_STORED);
        $this->assets[$url] = $asset;

        return true;
    }

    private function saveRecord(string $url, ?int $assetId, string $status): void
    {
        $record = CoverRecord::findOne(['urlHash' => CoverRecord::hash($url)]) ?? new CoverRecord();
        $record->urlHash = CoverRecord::hash($url);
        $record->url = $url;
        $record->assetId = $assetId;
        $record->status = $status;
        $record->save(false);
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

    private function saveAsset(Volume $volume, string $url, ?string $title, string $tempPath, string $extension): Asset
    {
        $folderPath = Plugin::getInstance()?->getSettings()->getCoverFolderPath() ?? 'mybooks';
        $assets = Craft::$app->getAssets();

        $folder = $folderPath !== ''
            ? $assets->ensureFolderByFullPathAndVolume($folderPath, $volume)
            : $assets->getRootFolderByVolumeId((int)$volume->id);

        if ($folder === null) {
            throw new \RuntimeException('The cover folder could not be created.');
        }

        $slug = StringHelper::slugify((string)$title) ?: 'cover';
        $filename = sprintf('%s-%s.%s', mb_substr($slug, 0, 80), substr(CoverRecord::hash($url), 0, 8), $extension);

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename(FileHelper::sanitizeFilename($filename, ['asciiOnly' => true]));
        $asset->newFolderId = (int)$folder->id;
        $asset->setVolumeId((int)$volume->id);
        $asset->avoidFilenameConflicts = true;
        $asset->title = $title ?: null;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new \RuntimeException(implode(' ', $asset->getErrorSummary(true)));
        }

        return $asset;
    }

    private function deleteAsset(int $assetId): void
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
