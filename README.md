# My Books for Craft CMS

Show what people are reading. Every reader gets an account at Open Library or
Hardcover, or has books entered by hand. Their shelves (want to read, currently
reading, read) are copied into Craft in the background, and templates show them
with a Reader field, Twig helpers or ready-made markup.

Three things shape the design:

1. **Book data is a cache.** Shelves are synced by cron, the queue or a button.
   Rendering a page never calls a book service, so a slow or broken service can
   never slow down or break your site. A failed sync keeps the last good books.
2. **Covers are stored locally.** Covers are downloaded into a Craft volume
   once, so visitors never contact a third party (no IP address leaves your
   site), and covers work with image transforms and ImgixKit.
3. **Readers are configuration.** They live in project config, so they deploy
   like sections do. API tokens are always environment variables and never end
   up in `project.yaml`.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Readers and book services](#readers-and-book-services)
- [Settings](#settings)
- [Syncing](#syncing)
- [Templates](#templates)
- [The Reader field](#the-reader-field)
- [Dashboard widget](#dashboard-widget)
- [Events](#events)
- [Security and privacy](#security-and-privacy)
- [License](#license)

## Requirements

Craft CMS 5.0.0+ and PHP 8.2+.

## Installation

```bash
composer require viesrood/mybooks
php craft plugin/install mybooks
```

Then choose a cover volume under **Settings → Plugins → My Books**, and add
readers under **My Books** in the main navigation.

## Readers and book services

A reader is one person with one account at one book service. Readers are
created by admins, and only where `allowAdminChanges` is on (they are project
config). On production, create them locally and deploy.

### Open Library

No key needed. Fill in the username (as in `openlibrary.org/people/username`;
a pasted profile URL works too). The reader has to make their reading log
public: on Open Library, go to **Settings → Privacy** and turn on the public
reading log. A private log gives a clear error in the control panel.

### Hardcover

1. On hardcover.app, open **Account → Hardcover API** and create a token with
   read access to the library.
2. Put it in `.env`: `HARDCOVER_TOKEN_JANE="..."` (with or without `Bearer `).
3. Fill in `$HARDCOVER_TOKEN_JANE` as the reader's API token. A token pasted
   directly is refused, because reader settings are stored in project config.

All shelves come back in one request per sync, well within Hardcover's rate
limits. Hardcover's terms allow a public website to show a user's data on
behalf of that user, which is what a reader with their own token is. Covers on
Hardcover are uploaded by users, so keep a takedown policy on your site.

### Entered by hand

No service at all. Add books under **My Books → the reader → New book**. Fill in
an ISBN and click **Look up** to take the title, authors and cover from Open
Library. The lookup only prefills the form; nothing is saved until you save.

**Test connection** on the reader screen checks the settings in the form
without saving them.

## Settings

Every setting can also be set per environment in `config/mybooks.php`:

```php
<?php

return [
    'coverVolume' => '4b8c...',        // volume UID; null = link covers remotely
    'coverSubpath' => 'mybooks',       // folder inside the volume
    'maxBooksPerShelf' => 50,
    'maxCoverDownloadsPerSync' => 40,  // per reader per run; the rest follows next run
    'timeout' => 10,                   // seconds per request
];
```

Without a cover volume, covers are linked straight from the book service. It
works, but every visitor's browser then requests images from a third party. The
control panel warns about this.

## Syncing

A sync runs:

- in the queue, right after a reader is saved;
- from **Sync now** / **Sync all** in the control panel (synchronous, so you see
  errors immediately);
- from the console, meant for cron:

```bash
php craft mybooks/sync                  # every reader
php craft mybooks/sync --reader=jane    # one reader
php craft mybooks/sync --verbose        # also report readers without changes
```

```cron
0 */3 * * * php /path/to/craft mybooks/sync >> /path/to/storage/logs/mybooks_cron.log 2>&1
```

The command is silent when nothing changed, so a cron log only grows when
something happened. It exits with a non-zero code when a book service failed.

What a sync does:

- books are only written when something about them changed;
- books that left a shelf are removed, but a shelf that failed to load keeps its
  books;
- a book whose cover changed at the service gets the new cover, and the old
  downloaded cover is deleted;
- errors are kept per reader and shown in the control panel.

## Templates

Everything below reads the local database only.

```twig
{# A reader by handle (or uid or id) #}
{% set reader = craft.mybooks.reader('jane') %}

{% for book in reader.shelf('reading', 3) ?? [] %}
    {% set cover = book.cover %}  {# Asset|null #}
    <img src="{{ cover ? cover.getUrl({ width: 300 }) : book.coverUrl }}" alt="">
    <h3>{{ book.title }}</h3>
    <p>{{ book.getAuthorsString(', ', ' & ') }}</p>
    {% if book.progress is not null %}
        <progress max="100" value="{{ book.progress }}" aria-label="{{ book.progress }}% read"></progress>
    {% endif %}
{% endfor %}

{# Or with criteria #}
{% set books = craft.mybooks.books({ reader: 'jane', shelf: 'read', limit: 5 }) %}
```

Shelves are `want`, `reading` and `read`. The names Open Library uses
(`want-to-read`, `currently-reading`, `already-read`) work too. An unknown shelf
returns an empty list instead of an error.

| Book | |
|---|---|
| `title`, `subtitle` | strings |
| `authors`, `authorsString`, `getAuthorsString(glue, lastGlue)` | author names |
| `cover` | the local `Asset`, or `null` |
| `coverSrc`, `getCoverSrc(transform)` | the local asset URL, otherwise the remote cover |
| `coverUrl` | the cover at the book service |
| `url` | the book's page at the service |
| `progress` | 0 to 100, only on `reading` |
| `rating` | 0.5 to 5, or `null` |
| `startedDate`, `finishedDate`, `addedDate` | `DateTime` or `null` |
| `shelf`, `shelfLabel` | the `Shelf` enum and its translated label |

| Reader | |
|---|---|
| `name`, `handle` | |
| `shelf(shelf, limit)` | books on one shelf |
| `books` | every book, grouped by shelf |
| `count(shelf)`, `hasBooks(shelf)` | |
| `lastSyncedAt`, `lastError` | sync status |

### Ready-made markup

```twig
{{ craft.mybooks.render('jane', {
    shelf: 'reading',
    limit: 5,
    heading: 'What I am reading',
    headingLevel: 2,
    transform: { width: 300 },
    registerCss: true,
}) }}
```

A horizontally scrolling list with CSS scroll-snap: no JavaScript, no jQuery,
nothing from a CDN. The list is keyboard-focusable, covers are lazy-loaded, and
it renders nothing when the shelf is empty. The CSS is scoped to `.mybooks` and
can be restyled through custom properties (`--mybooks-cover-width`,
`--mybooks-gap`, ...), or left out with `registerCss: false`.

To change the markup, copy `src/templates/_site/_shelf.twig` to
`templates/mybooks/_shelf.twig` in your site.

## The Reader field

Add a **Reader (My Books)** field to, for example, a team member's entry type:

```twig
{% set reader = entry.reader %}
{% if reader and reader.hasBooks('reading') %}
    ...
{% endif %}
```

The field stores the reader's UID, so it points at the same reader on every
environment. A deleted reader reads as `null`.

## Dashboard widget

**Reading** shows the covers on one shelf, for one reader or for everyone.

## Events

```php
use viesrood\mybooks\events\SyncEvent;
use viesrood\mybooks\services\SyncService;
use yii\base\Event;

// Clear a static page cache, but only when a sync changed something.
Event::on(SyncService::class, SyncService::EVENT_AFTER_SYNC, function (SyncEvent $event) {
    if ($event->changed) {
        // $event->reader->handle ...
    }
});
```

Register your own book service by implementing
`viesrood\mybooks\base\ProviderInterface` (or extending `base\Provider`):

```php
use craft\events\RegisterComponentTypesEvent;
use viesrood\mybooks\services\ProvidersService;

Event::on(ProvidersService::class, ProvidersService::EVENT_REGISTER_PROVIDERS,
    fn(RegisterComponentTypesEvent $e) => $e->types[] = MyProvider::class);
```

## Security and privacy

- Rendering never contacts a book service; only the sync does, from the server.
- Remote URLs are only stored when they are `http(s)`, so a remote value can
  never become a `javascript:` link.
- Cover downloads refuse URLs that resolve to private or reserved addresses,
  also after redirects, check the actual bytes (JPEG, PNG, WebP or GIF only,
  5 MB max), and skip Open Library's 1-pixel placeholder.
- API tokens are environment variables only.
- Reader settings are admin-only. Hand-entered books and syncs need the
  **Manage hand-entered books and start syncs** permission.

## License

MIT, see [LICENSE.md](LICENSE.md).
