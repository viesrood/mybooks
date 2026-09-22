<?php

declare(strict_types=1);

namespace viesrood\mybooks\variables;

use Craft;
use craft\web\View;
use Twig\Markup;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Book;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;

/**
 * craft.mybooks
 *
 * Every method reads the local database only; nothing here ever calls a book
 * service, so a template can never be slowed down or broken by one.
 */
class MyBooksVariable
{
    /**
     * @return Reader[]
     */
    public function readers(): array
    {
        return Plugin::getInstance()?->getReaders()->getAllReaders() ?? [];
    }

    /**
     * A reader by handle, uid or id; also passes a Reader through.
     */
    public function reader(mixed $reader): ?Reader
    {
        if ($reader instanceof Reader) {
            return $reader;
        }

        $readers = Plugin::getInstance()?->getReaders();

        if ($readers === null) {
            return null;
        }

        if (is_int($reader) || (is_string($reader) && ctype_digit($reader))) {
            return $readers->getReaderById((int)$reader);
        }

        if (!is_string($reader) || $reader === '') {
            return null;
        }

        return $readers->getReaderByHandle($reader) ?? $readers->getReaderByUid($reader);
    }

    /**
     * craft.mybooks.books({ reader: 'jane', shelf: 'reading', limit: 3 })
     *
     * @param array<string, mixed> $criteria
     * @return Book[]
     */
    public function books(array $criteria = []): array
    {
        $reader = $this->reader($criteria['reader'] ?? null);

        if ($reader === null) {
            return [];
        }

        $shelf = isset($criteria['shelf']) ? Shelf::tryFromAny($criteria['shelf']) : null;

        if (isset($criteria['shelf']) && $shelf === null) {
            return [];
        }

        $limit = isset($criteria['limit']) && is_numeric($criteria['limit']) ? (int)$criteria['limit'] : null;

        return Plugin::getInstance()?->getBooks()->getBooks($reader, $shelf, $limit) ?? [];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function shelves(): array
    {
        return Shelf::options();
    }

    /**
     * Ready-made, dependency-free markup for one shelf: a horizontally
     * scrolling list with CSS scroll-snap, no JavaScript, nothing loaded from
     * a CDN. Override it by copying src/templates/_site/_shelf.twig to
     * templates/mybooks/_shelf.twig in your site.
     *
     * Options: shelf ('reading'), limit (10), heading (none), headingLevel (2),
     * showProgress (true), transform (for Asset::getUrl()), registerCss (true).
     *
     * @param array<string, mixed> $options
     */
    public function render(mixed $reader, array $options = []): Markup
    {
        $reader = $this->reader($reader);
        $shelf = Shelf::tryFromAny($options['shelf'] ?? Shelf::Reading) ?? Shelf::Reading;
        $limit = isset($options['limit']) && is_numeric($options['limit']) ? max(1, (int)$options['limit']) : 10;
        $books = $reader?->shelf($shelf, $limit) ?? [];

        if ($reader === null || $books === []) {
            return new Markup('', 'UTF-8');
        }

        /** @var View $view */
        $view = Craft::$app->getView();

        if (($options['registerCss'] ?? true) !== false) {
            $css = file_get_contents(dirname(__DIR__) . '/resources/shelf.css');

            if (is_string($css)) {
                $view->registerCss($css, [], 'mybooks-shelf');
            }
        }

        $html = $view->renderTemplate('mybooks/_shelf', [
            'reader' => $reader,
            'shelf' => $shelf,
            'books' => $books,
            'heading' => $options['heading'] ?? null,
            'headingLevel' => max(2, min(6, (int)($options['headingLevel'] ?? 2))),
            'showProgress' => ($options['showProgress'] ?? true) !== false,
            'transform' => $options['transform'] ?? null,
        ], View::TEMPLATE_MODE_SITE);

        return new Markup($html, 'UTF-8');
    }
}
