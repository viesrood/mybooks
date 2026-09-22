# Release Notes for My Books

## 1.0.0 - Unreleased

- Initial release.
- Readers at Open Library (public reading log), Hardcover (API token) or entered by hand, stored in project config.
- Want to read, currently reading and read shelves, synced by cron (`mybooks/sync`), the queue or the control panel.
- Covers are downloaded once into a Craft volume, so visitors never contact a book service.
- Reader field, dashboard widget, `craft.mybooks` Twig API and dependency-free default markup.
- ISBN lookup for hand-entered books.
- English and Dutch translations.
