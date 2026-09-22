<?php

declare(strict_types=1);

namespace viesrood\mybooks;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use viesrood\mybooks\fields\ReaderField;
use viesrood\mybooks\models\Settings;
use viesrood\mybooks\services\BooksService;
use viesrood\mybooks\services\CoversService;
use viesrood\mybooks\services\ProvidersService;
use viesrood\mybooks\services\ReadersService;
use viesrood\mybooks\services\SyncService;
use viesrood\mybooks\variables\MyBooksVariable;
use viesrood\mybooks\widgets\ReadingWidget;
use yii\base\Event;

/**
 * My Books plugin.
 *
 * Shows what people are reading:
 * - readers (one account each) at Open Library, Hardcover, or entered by hand;
 * - their shelves are synced into a local table by cron or queue, so a page
 *   never waits on, or breaks because of, a book service;
 * - covers are copied into a Craft volume, so visitors never contact a
 *   third party;
 * - a Reader field, a dashboard widget and dependency-free Twig markup.
 *
 * @property-read BooksService $books
 * @property-read CoversService $covers
 * @property-read ProvidersService $providers
 * @property-read ReadersService $readers
 * @property-read SyncService $sync
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MANAGE_BOOKS = 'mybooks-manageBooks';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    /**
     * @return array{components: array<string, mixed>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'books' => BooksService::class,
                'covers' => CoversService::class,
                'providers' => ProvidersService::class,
                'readers' => ReadersService::class,
                'sync' => SyncService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'viesrood\\mybooks\\console\\controllers';
        }

        $this->registerProjectConfigHandlers();
        $this->registerComponentTypes();
        $this->registerVariable();
        $this->registerSiteTemplateRoot();
        $this->registerCpRoutes();
        $this->registerPermissions();
    }

    public function getBooks(): BooksService
    {
        /** @var BooksService $service */
        $service = $this->get('books');

        return $service;
    }

    public function getCovers(): CoversService
    {
        /** @var CoversService $service */
        $service = $this->get('covers');

        return $service;
    }

    public function getProviders(): ProvidersService
    {
        /** @var ProvidersService $service */
        $service = $this->get('providers');

        return $service;
    }

    public function getReaders(): ReadersService
    {
        /** @var ReadersService $service */
        $service = $this->get('readers');

        return $service;
    }

    public function getSync(): SyncService
    {
        /** @var SyncService $service */
        $service = $this->get('sync');

        return $service;
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();

        if (!$user->getIsAdmin() && !$user->checkPermission(self::PERMISSION_MANAGE_BOOKS)) {
            return null;
        }

        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('mybooks', 'My Books');
        $item['url'] = 'mybooks';

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        /** @var View $view */
        $view = Craft::$app->getView();

        $volumeOptions = [['label' => Craft::t('mybooks', 'None: link covers from the book service'), 'value' => '']];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumeOptions[] = ['label' => $volume->name, 'value' => (string)$volume->uid];
        }

        return $view->renderTemplate('mybooks/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'volumeOptions' => $volumeOptions,
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($this->handle)),
        ], View::TEMPLATE_MODE_CP);
    }

    private function registerProjectConfigHandlers(): void
    {
        $readers = $this->getReaders();

        Craft::$app->getProjectConfig()
            ->onAdd(ReadersService::CONFIG_KEY . '.{uid}', [$readers, 'handleChangedReader'])
            ->onUpdate(ReadersService::CONFIG_KEY . '.{uid}', [$readers, 'handleChangedReader'])
            ->onRemove(ReadersService::CONFIG_KEY . '.{uid}', [$readers, 'handleDeletedReader']);

        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            static function (RebuildConfigEvent $event) use ($readers): void {
                $readers->handleRebuild($event);
            }
        );
    }

    private function registerComponentTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ReaderField::class;
            }
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = ReadingWidget::class;
            }
        );
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('mybooks', MyBooksVariable::class);
            }
        );
    }

    /**
     * Front-end templates live in their own folder, so no control panel
     * template can ever be reached through a site URL.
     */
    private function registerSiteTemplateRoot(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function (RegisterTemplateRootsEvent $event): void {
                $event->roots['mybooks'] = __DIR__ . '/templates/_site';
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['mybooks'] = 'mybooks/readers/index';
                $event->rules['mybooks/readers/new'] = 'mybooks/readers/edit';
                $event->rules['mybooks/readers/<readerId:\d+>'] = 'mybooks/readers/edit';
                $event->rules['mybooks/readers/<readerId:\d+>/books'] = 'mybooks/books/index';
                $event->rules['mybooks/readers/<readerId:\d+>/books/new'] = 'mybooks/books/edit';
                $event->rules['mybooks/readers/<readerId:\d+>/books/<bookId:\d+>'] = 'mybooks/books/edit';
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('mybooks', 'My Books'),
                    'permissions' => [
                        self::PERMISSION_MANAGE_BOOKS => [
                            'label' => Craft::t('mybooks', 'Manage hand-entered books and start syncs'),
                        ],
                    ],
                ];
            }
        );
    }
}
