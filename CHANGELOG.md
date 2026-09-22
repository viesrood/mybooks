# Release Notes for My Books

## 1.0.0 - 2026-09-22

- Initial release.
- A Books field: pick books by searching Open Library (title, author or ISBN), or link an Open Library account whose shelves are synced.
- Want to read, currently reading and read shelves, with progress.
- Linked accounts are synced by cron (`mybooks/sync`) and the queue; a page never contacts Open Library.
- Covers are stored once in a Craft volume, shared between books, and garbage-collected.
- Utilities → My Books, a Reading dashboard widget, `craft.mybooks` and dependency-free default markup.
- English and Dutch translations.
