<?php

declare(strict_types=1);

namespace viesrood\mybooks;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use viesrood\mybooks\fields\BooksField;
use viesrood\mybooks\models\Settings;
use viesrood\mybooks\services\AccountsService;
use viesrood\mybooks\services\BooksService;
use viesrood\mybooks\services\CoversService;
use viesrood\mybooks\services\SyncService;
use viesrood\mybooks\utilities\MyBooksUtility;
use viesrood\mybooks\variables\MyBooksVariable;
use viesrood\mybooks\widgets\ReadingWidget;
use yii\base\Event;

/**
 * My Books plugin.
 *
 * A Books field that shows what someone is reading:
 * - pick books by hand (search Open Library by title or ISBN), or link an
 *   Open Library account whose shelves are synced by cron or queue;
 * - a page never waits on, or breaks because of, Open Library;
 * - covers are copied into a Craft volume, so visitors never contact a
 *   third party;
 * - a utility, a dashboard widget and dependency-free Twig markup.
 *
 * @property-read AccountsService $accounts
 * @property-read BooksService $books
 * @property-read CoversService $covers
 * @property-read SyncService $sync
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    /**
     * @return array{components: array<string, mixed>}
     */
    public static function config(): array
    {
        return [
            'components' => [
                'accounts' => AccountsService::class,
                'books' => BooksService::class,
                'covers' => CoversService::class,
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

        $this->registerComponentTypes();
        $this->registerVariable();
        $this->registerSiteTemplateRoot();
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

    public function getAccounts(): AccountsService
    {
        /** @var AccountsService $service */
        $service = $this->get('accounts');

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

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        /** @var View $view */
        $view = Craft::$app->getView();

        $volumeOptions = [['label' => Craft::t('mybooks', 'None: link covers from Open Library'), 'value' => '']];

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

    private function registerComponentTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = BooksField::class;
            }
        );

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = MyBooksUtility::class;
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
}
