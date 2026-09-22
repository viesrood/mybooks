<?php

declare(strict_types=1);

namespace viesrood\mybooks\variables;

use Craft;
use craft\web\View;
use Twig\Markup;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Library;
use viesrood\mybooks\Plugin;

/**
 * craft.mybooks
 *
 * Nothing here ever calls Open Library, so a template can never be slowed
 * down or broken by it.
 */
class MyBooksVariable
{
    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function shelves(): array
    {
        return Shelf::options();
    }

    /**
     * Every non-empty Books field value on the site, one per element (for an
     * overview such as "what the team is reading").
     *
     * @return array<int, array{title: string, library: Library}>
     */
    public function libraries(): array
    {
        return Plugin::getInstance()?->getAccounts()->getLibraries() ?? [];
    }

    /**
     * Ready-made, dependency-free markup for one shelf: a horizontally
     * scrolling list with CSS scroll-snap, no JavaScript, nothing loaded from
     * a CDN. Override it by copying src/templates/_site/_shelf.twig to
     * templates/mybooks/_shelf.twig in your site.
     *
     * {{ craft.mybooks.render(entry.books, { shelf: 'reading' }) }}
     *
     * Options: shelf ('reading'), limit (10), heading (none), headingLevel (2),
     * label (for the list; defaults to the heading or the shelf name),
     * showProgress (true), transform (for Asset::getUrl()), registerCss (true).
     *
     * @param array<string, mixed> $options
     */
    public function render(mixed $library, array $options = []): Markup
    {
        if (!$library instanceof Library) {
            return new Markup('', 'UTF-8');
        }

        $shelf = Shelf::tryFromAny($options['shelf'] ?? Shelf::Reading) ?? Shelf::Reading;
        $limit = isset($options['limit']) && is_numeric($options['limit']) ? max(1, (int)$options['limit']) : 10;
        $books = $library->shelf($shelf, $limit);

        if ($books === []) {
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

        $heading = isset($options['heading']) && is_string($options['heading']) ? $options['heading'] : null;

        $html = $view->renderTemplate('mybooks/_shelf', [
            'shelf' => $shelf,
            'books' => $books,
            'heading' => $heading,
            'headingLevel' => max(2, min(6, (int)($options['headingLevel'] ?? 2))),
            'label' => is_string($options['label'] ?? null) ? $options['label'] : ($heading ?? $shelf->label()),
            'showProgress' => ($options['showProgress'] ?? true) !== false,
            'transform' => $options['transform'] ?? null,
            'listId' => 'mybooks-' . substr(md5(json_encode(array_map(static fn($b) => $b->id, $books)) ?: ''), 0, 8),
        ], View::TEMPLATE_MODE_SITE);

        return new Markup($html, 'UTF-8');
    }
}
