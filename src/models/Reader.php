<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\validators\HandleValidator;
use DateTime;
use viesrood\mybooks\base\ProviderInterface;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\ReaderRecord;

/**
 * A person whose books are shown: one account at one provider.
 *
 * Readers live in project config; the id and the sync status come from the
 * database.
 */
class Reader extends Model
{
    public ?int $id = null;

    public ?string $uid = null;

    public string $name = '';

    public string $handle = '';

    public string $provider = 'openlibrary';

    /**
     * Username at the provider. May be an environment variable reference.
     */
    public ?string $account = null;

    /**
     * API token, always an environment variable reference ("$HARDCOVER_TOKEN").
     */
    public ?string $token = null;

    public int $sortOrder = 0;

    public ?DateTime $lastSyncedAt = null;

    public ?string $lastError = null;

    /** @var Shelf[] */
    private array $shelves = [Shelf::Want, Shelf::Reading, Shelf::Read];

    public function __toString(): string
    {
        return $this->name;
    }

    public function attributes(): array
    {
        $names = parent::attributes();
        $names[] = 'shelves';

        return $names;
    }

    /**
     * @return Shelf[]
     */
    public function getShelves(): array
    {
        return $this->shelves;
    }

    /**
     * @param mixed $shelves Shelf cases, values, or a comma-separated string.
     */
    public function setShelves(mixed $shelves): void
    {
        $this->shelves = Shelf::listFrom($shelves);
    }

    /**
     * @return string[]
     */
    public function getShelfValues(): array
    {
        return array_map(static fn(Shelf $shelf): string => $shelf->value, $this->shelves);
    }

    public function hasShelf(Shelf $shelf): bool
    {
        return in_array($shelf, $this->shelves, true);
    }

    public function getParsedAccount(): string
    {
        return trim((string)App::parseEnv($this->account ?? ''));
    }

    public function getParsedToken(): string
    {
        $token = (string)App::parseEnv($this->token ?? '');

        // An unset variable comes back as the reference itself.
        return str_starts_with($token, '$') ? '' : trim($token);
    }

    public function getProviderInstance(): ?ProviderInterface
    {
        return Plugin::getInstance()?->getProviders()->getProvider($this->provider);
    }

    public function isManual(): bool
    {
        $provider = $this->getProviderInstance();

        return $provider !== null && !$provider->supportsSync();
    }

    /**
     * Books on one shelf, in display order.
     *
     * {% for book in reader.shelf('reading', 3) %}
     *
     * @return Book[]
     */
    public function shelf(mixed $shelf, ?int $limit = null): array
    {
        $shelf = Shelf::tryFromAny($shelf);

        if ($shelf === null || $this->id === null) {
            return [];
        }

        return Plugin::getInstance()?->getBooks()->getBooks($this, $shelf, $limit) ?? [];
    }

    /**
     * Every book of this reader, grouped per shelf in canonical order.
     *
     * @return Book[]
     */
    public function getBooks(): array
    {
        if ($this->id === null) {
            return [];
        }

        return Plugin::getInstance()?->getBooks()->getBooks($this) ?? [];
    }

    public function count(mixed $shelf = null): int
    {
        if ($shelf === null) {
            return count($this->getBooks());
        }

        return count($this->shelf($shelf));
    }

    public function hasBooks(mixed $shelf = null): bool
    {
        return $this->count($shelf) > 0;
    }

    public function getCpEditUrl(): ?string
    {
        return $this->id !== null ? \craft\helpers\UrlHelper::cpUrl('mybooks/readers/' . $this->id) : null;
    }

    public function getCpBooksUrl(): ?string
    {
        return $this->id !== null ? \craft\helpers\UrlHelper::cpUrl('mybooks/readers/' . $this->id . '/books') : null;
    }

    /**
     * What goes into project config. No id, no status.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'provider' => $this->provider,
            'account' => $this->account !== null && $this->account !== '' ? $this->account : null,
            'token' => $this->token !== null && $this->token !== '' ? $this->token : null,
            'shelves' => $this->getShelfValues(),
            'sortOrder' => $this->sortOrder,
        ];
    }

    public static function fromRecord(ReaderRecord $record): self
    {
        $reader = new self([
            'id' => (int)$record->id,
            'uid' => $record->uid,
            'name' => $record->name,
            'handle' => $record->handle,
            'provider' => $record->provider,
            'account' => $record->account,
            'token' => $record->token,
            'sortOrder' => (int)$record->sortOrder,
            'lastError' => $record->lastError,
        ]);

        $reader->setShelves($record->shelves ?? '');
        $lastSynced = $record->lastSyncedAt !== null ? DateTimeHelper::toDateTime($record->lastSyncedAt) : false;
        $reader->lastSyncedAt = $lastSynced instanceof DateTime ? $lastSynced : null;

        return $reader;
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'provider'], 'trim'],
            [['name', 'handle', 'provider'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 64],
            [['handle'], HandleValidator::class],
            [['handle'], 'validateUniqueHandle'],
            [['provider'], 'validateProvider'],
            [['account'], 'validateAccount', 'skipOnEmpty' => false],
            [['token'], 'validateToken', 'skipOnEmpty' => false],
            [['shelves'], 'validateShelves', 'skipOnEmpty' => false],
        ];
    }

    public function validateUniqueHandle(string $attribute): void
    {
        $existing = Plugin::getInstance()?->getReaders()->getReaderByHandle($this->handle);

        if ($existing !== null && $existing->uid !== $this->uid) {
            $this->addError($attribute, Craft::t('mybooks', 'Another reader already uses this handle.'));
        }
    }

    public function validateProvider(string $attribute): void
    {
        if ($this->getProviderInstance() === null) {
            $this->addError($attribute, Craft::t('mybooks', 'Choose a book service.'));
        }
    }

    public function validateAccount(string $attribute): void
    {
        $provider = $this->getProviderInstance();

        if ($provider !== null && $provider->requiresAccount() && trim((string)$this->account) === '') {
            $this->addError($attribute, Craft::t('mybooks', 'Enter the username at {provider}.', [
                'provider' => $provider::displayName(),
            ]));
        }
    }

    public function validateToken(string $attribute): void
    {
        $provider = $this->getProviderInstance();

        if ($provider === null || !$provider->requiresToken()) {
            return;
        }

        $token = trim((string)$this->token);

        if ($token === '') {
            $this->addError($attribute, Craft::t('mybooks', 'Enter the environment variable that holds the API token.'));

            return;
        }

        // Project config ends up in git; a token must never be written there.
        if (preg_match('/^\$[A-Z0-9_]+$/i', $token) !== 1) {
            $this->addError($attribute, Craft::t('mybooks', 'Use an environment variable such as $HARDCOVER_TOKEN, never the token itself: reader settings are stored in project config.'));
        }
    }

    public function validateShelves(string $attribute): void
    {
        if (!$this->isManual() && $this->shelves === []) {
            $this->addError($attribute, Craft::t('mybooks', 'Choose at least one shelf.'));
        }
    }
}
