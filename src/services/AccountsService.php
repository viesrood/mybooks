<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Db;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\fields\BooksField;
use viesrood\mybooks\models\Account;
use viesrood\mybooks\models\Library;
use viesrood\mybooks\records\AccountRecord;
use yii\base\Component;

/**
 * Knows which Open Library accounts are linked, and by which elements.
 *
 * There is no registry to keep in sync: discover() reads the Books field
 * values themselves, so an account is linked exactly as long as some
 * element's field says so.
 */
class AccountsService extends Component
{
    /** @var array<string, Account>|null keyed by lower-case account */
    private ?array $accounts = null;

    /** @var array{accounts: array<string, array<int, array{id: int, siteId: int, uri: string|null, title: string, account: string}>>, coverUrls: string[], libraries: array<int, array{title: string, library: Library}>}|null */
    private ?array $discovered = null;

    public function getAccount(string $account): ?Account
    {
        return $this->getAllAccounts()[strtolower($account)] ?? null;
    }

    /**
     * @return array<string, Account>
     */
    public function getAllAccounts(): array
    {
        if ($this->accounts === null) {
            $this->accounts = [];

            if (!Craft::$app->getDb()->tableExists(AccountRecord::TABLE)) {
                return $this->accounts;
            }

            /** @var AccountRecord[] $records */
            $records = AccountRecord::find()->orderBy(['account' => SORT_ASC])->all();

            foreach ($records as $record) {
                $this->accounts[strtolower($record->account)] = Account::fromRecord($record);
            }
        }

        return $this->accounts;
    }

    /**
     * The row for an account, created when it does not exist yet.
     */
    public function ensureAccount(string $account): Account
    {
        $existing = $this->getAccount($account);

        if ($existing !== null) {
            return $existing;
        }

        $record = new AccountRecord();
        $record->account = $account;
        $record->save(false);
        $this->accounts = null;

        return $this->getAccount($account) ?? Account::fromRecord($record);
    }

    /**
     * Stores the outcome of a sync: runtime state, straight to the database.
     */
    public function recordSync(Account $account, ?string $error): void
    {
        $now = new \DateTime();

        Db::update(AccountRecord::TABLE, [
            'lastSyncedAt' => Db::prepareDateForDb($now),
            'lastError' => $error !== null ? mb_substr($error, 0, 2000) : null,
        ], ['id' => $account->id]);

        $account->lastSyncedAt = $now;
        $account->lastError = $error;
    }

    /**
     * Removes accounts that no field links to any more; their books follow
     * through the foreign key.
     *
     * @param string[] $inUse
     * @return string[] The removed accounts.
     */
    public function removeUnused(array $inUse): array
    {
        $keep = array_map('strtolower', $inUse);
        $removed = [];

        foreach ($this->getAllAccounts() as $key => $account) {
            if (!in_array($key, $keep, true)) {
                Db::delete(AccountRecord::TABLE, ['id' => $account->id]);
                $removed[] = $account->account;
            }
        }

        $this->accounts = null;

        return $removed;
    }

    /**
     * Elements that link to an account.
     *
     * @return array<int, array{id: int, siteId: int, uri: string|null, title: string, account: string}>
     */
    public function getElementsForAccount(string $account): array
    {
        return $this->discover()['accounts'][strtolower($account)] ?? [];
    }

    /**
     * @return string[] Every linked account, as typed in the fields.
     */
    public function getLinkedAccounts(): array
    {
        $accounts = [];

        foreach ($this->discover()['accounts'] as $elements) {
            // The key is lower case; keep the spelling of the field value.
            $accounts[] = $elements[0]['account'] ?? '';
        }

        return array_values(array_filter($accounts));
    }

    /**
     * The shelves any field linking to this account shows. Only those are
     * fetched and only their covers stored.
     *
     * @return Shelf[] Canonical order; every shelf when nothing links to it.
     */
    public function getShelvesForAccount(string $account): array
    {
        $values = [];

        foreach ($this->getLibraries() as $item) {
            $library = $item['library'];

            if ($library->isLinked() && strcasecmp($library->account, $account) === 0) {
                array_push($values, ...$library->getShelfValues());
            }
        }

        return $values === [] ? Shelf::cases() : Shelf::listFrom($values);
    }

    /**
     * Every non-empty Books field value, one per element.
     *
     * @return array<int, array{title: string, library: Library}>
     */
    public function getLibraries(): array
    {
        return $this->discover()['libraries'];
    }

    /**
     * @return string[] Cover URLs of every hand-picked book in every current
     *                  field value (for cover garbage collection).
     */
    public function getManualCoverUrls(): array
    {
        return $this->discover()['coverUrls'];
    }

    /**
     * Walks every element that has a Books field in its layout. Only current,
     * canonical elements count: drafts and revisions do not keep an account
     * linked or a cover alive.
     *
     * @return array{accounts: array<string, array<int, array{id: int, siteId: int, uri: string|null, title: string, account: string}>>, coverUrls: string[], libraries: array<int, array{title: string, library: Library}>}
     */
    public function discover(bool $refresh = false): array
    {
        if ($this->discovered !== null && !$refresh) {
            return $this->discovered;
        }

        $accounts = [];
        $coverUrls = [];
        $libraries = [];
        $fieldsService = Craft::$app->getFields();

        foreach ($fieldsService->getAllFields() as $field) {
            if (!$field instanceof BooksField) {
                continue;
            }

            foreach ($fieldsService->findFieldUsages($field) as $layout) {
                /** @var class-string<ElementInterface>|null $type */
                $type = $layout->type;

                if ($type === null || !class_exists($type)) {
                    continue;
                }

                $query = $type::find()
                    ->status(null)
                    // Every site: each one has its own URI to refresh.
                    ->site('*')
                    ->drafts(false)
                    ->revisions(false);

                if ($layout->id !== null) {
                    $query->andWhere(['elements.fieldLayoutId' => $layout->id]);
                }

                foreach ($query->each() as $element) {
                    /** @var ElementInterface $element */
                    $value = $element->getFieldValue((string)$field->handle);

                    if (!$value instanceof Library) {
                        continue;
                    }

                    if ($value->isLinked()) {
                        $accounts[strtolower($value->account)][] = [
                            'id' => (int)$element->id,
                            'siteId' => (int)$element->siteId,
                            'uri' => $element->uri,
                            'title' => (string)$element,
                            'account' => $value->account,
                        ];
                    }

                    array_push($coverUrls, ...$value->getManualCoverUrls());

                    if (!$value->isEmpty()) {
                        // One per element, whatever the number of sites.
                        $libraries[(int)$element->id] ??= ['title' => (string)$element, 'library' => $value];
                    }
                }
            }
        }

        return $this->discovered = [
            'accounts' => $accounts,
            'coverUrls' => array_values(array_unique($coverUrls)),
            'libraries' => array_values($libraries),
        ];
    }
}
