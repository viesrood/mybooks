# My Books for Craft CMS

A **Books** field that shows what someone is reading. Add it to an entry type
(a team member, an author, a user) and editors choose, per entry:

- **Pick books myself**: search Open Library by title, author or ISBN, click a
  result, and title, authors and cover are filled in. Set the shelf (want to
  read, currently reading, read) and progress. Books that Open Library does not
  know can be added without searching.
- **Link an Open Library account**: fill in a username and pick the shelves to
  show. The shelves are synced in the background.

Three things shape the design:

1. **A page never talks to Open Library.** Hand-picked books live in the field
   value (with drafts and revisions like any content); synced books live in the
   plugin's own table, refreshed by cron or the queue. A slow or broken Open
   Library can never slow down or break your site, and a failed sync keeps the
   last good books.
2. **Covers are stored locally.** Each cover is downloaded once into a Craft
   volume, so no visitor's browser contacts a third party, and covers work with
   image transforms and ImgixKit. Until a cover is stored, templates get a
   placeholder, never a remote image.
3. **Content, not configuration.** Everything is edited on the entry, by
   whoever can edit the entry, on any environment. There are no API tokens.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [The field](#the-field)
- [Settings](#settings)
- [Syncing](#syncing)
- [Templates](#templates)
- [Utility and dashboard widget](#utility-and-dashboard-widget)
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

Then choose a cover volume under **Settings → Plugins → My Books**, create a
**Books** field and add it to a field layout.

## The field

**Pick books myself.** Type in the search box and press Enter. Results come
from Open Library's search (up to eight; an ISBN searches on ISBN only). Added
books can be renamed, reordered by dragging, moved to another shelf and given
a progress percentage. Their covers are stored in the background after the
entry is saved.

**Link an Open Library account.** Fill in the username, as in
`openlibrary.org/people/username` (a pasted profile link works too). The
reading log must be public: on Open Library, go to **Settings → Privacy** and
turn on the public reading log. **Test connection** checks it before saving.
The account is synced right after the entry is saved, and then by cron.

The account is remembered in both modes, so switching back and forth loses
nothing; only the chosen mode is shown on the site.

## Settings

Every setting can also be set per environment in `config/mybooks.php`:

```php
<?php

return [
    'coverVolume' => '4b8c...',        // volume UID; null = link covers remotely
    'coverSubpath' => 'mybooks',       // folder inside the volume
    'maxBooksPerShelf' => 50,          // per shelf of a linked account
    'maxCoverDownloadsPerSync' => 40,  // per account per run; the rest follows next run
    'timeout' => 10,                   // seconds per request to Open Library
];
```

Without a cover volume, covers are linked straight from Open Library. It works,
but every visitor's browser then requests images from a third party.

## Syncing

```bash
php craft mybooks/sync                     # everything
php craft mybooks/sync --account=jane_doe  # one account, no clean-up
php craft mybooks/sync --verbose           # also report accounts without changes
```

```cron
0 */3 * * * php /path/to/craft mybooks/sync >> /path/to/storage/logs/mybooks_cron.log 2>&1
```

A full run:

- finds the linked accounts by reading the Books fields themselves (drafts and
  revisions do not count), so there is nothing to register or keep in sync;
- fetches only the shelves some field shows, and writes only what changed;
- removes books that left a shelf, but keeps the books of a shelf that failed
  to load;
- stores missing covers, most visible shelf first;
- removes accounts no field links to any more, and covers nothing uses.

It prints nothing when nothing changed, and exits with code 69 when Open
Library failed for an account.

## Templates

```twig
{% set books = entry.books %}  {# the field handle #}

{% for book in books.shelf('reading', 4) %}
    {% if book.cover %}
        <img src="{{ book.cover.getUrl({ width: 300 }) }}" alt="">
    {% endif %}
    <h3>{{ book.title }}</h3>
    <p>{{ book.getAuthorsString(', ', ' & ') }}</p>
    {% if book.progress is not null %}
        <progress max="100" value="{{ book.progress }}" aria-label="{{ book.progress }}% read"></progress>
    {% endif %}
{% endfor %}
```

Shelves are `want`, `reading` and `read`; the names Open Library uses
(`want-to-read`, `currently-reading`, `already-read`) work too. An unknown shelf
returns an empty list instead of an error.

| Field value | |
|---|---|
| `shelf(shelf, limit)` | books on one shelf |
| `books` | every book shown |
| `count(shelf)`, `hasBooks(shelf)` | |
| `isLinked()`, `account`, `mode` | |
| `accountStatus` | `lastSyncedAt` and `lastError` of the linked account |

| Book | |
|---|---|
| `title`, `subtitle`, `authors`, `authorsString` | |
| `cover` | the local `Asset`, or `null` while it is not stored yet |
| `coverSrc`, `getCoverSrc(transform)` | the local URL; remote only without a cover volume |
| `url` | the book's page at Open Library |
| `progress` | 0 to 100, only on `reading` |
| `rating`, `startedDate`, `finishedDate`, `addedDate` | |
| `shelf`, `shelfLabel`, `isManual()` | |

### Ready-made markup

```twig
{{ craft.mybooks.render(entry.books, {
    shelf: 'reading',
    limit: 5,
    heading: 'What I am reading',
    transform: { width: 300 },
}) }}
```

A horizontally scrolling list with CSS scroll-snap: no JavaScript, no jQuery,
nothing from a CDN. Keyboard-focusable, lazy-loaded covers, and nothing at all
when the shelf is empty. Restyle it through custom properties
(`--mybooks-cover-width`, `--mybooks-gap`, ...), leave the CSS out with
`registerCss: false`, or copy `src/templates/_site/_shelf.twig` to
`templates/mybooks/_shelf.twig` to change the markup.

`craft.mybooks.libraries()` returns every non-empty Books field value on the
site, for a "what the team is reading" overview.

## Utility and dashboard widget

**Utilities → My Books** lists the linked accounts, which entries use them,
the last sync and any error, the number of stored covers, and a **Sync all**
button. The **Reading** dashboard widget shows one shelf for everyone.

## Events

```php
use viesrood\mybooks\events\SyncEvent;
use viesrood\mybooks\services\SyncService;
use yii\base\Event;

// Clear a static page cache, but only when a sync changed something.
Event::on(SyncService::class, SyncService::EVENT_AFTER_SYNC, function (SyncEvent $event) {
    if (!$event->changed) {
        return;
    }

    foreach ($event->elements as $element) {
        // $element['id'], $element['siteId'], $element['uri']
    }
});
```

Hand-picked books change through an entry save, which your cache already
handles.

## Security and privacy

- Rendering never contacts Open Library; only the sync and the field's search
  do, from the server.
- Everything posted by the field is sanitised again on the server: only
  `http(s)` URLs survive, ids and work ids are checked, text is trimmed.
- Cover downloads refuse URLs that resolve to private or reserved addresses,
  also after redirects, check the actual bytes (JPEG, PNG, WebP or GIF, 5 MB
  max), and skip Open Library's one-pixel placeholder.
- The field's actions need a logged-in control panel user; "Sync now" only
  works for accounts a field already links to.

## License

MIT, see [LICENSE.md](LICENSE.md).
