/* global Craft, Garnish, $ */
/**
 * Books field input.
 *
 * The field value is one JSON object in a hidden input. This script is the
 * only thing that writes it: every change in the list, the search results or
 * the account settings updates `state` and serialises it again. The server
 * sanitises everything once more on save.
 */
(function () {
  'use strict';

  const uuid = () =>
    (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
          const r = (Math.random() * 16) | 0;
          return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });

  const el = (tag, attrs = {}, children = []) => {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs)) {
      if (value === null || value === undefined || value === false) continue;
      if (key === 'text') node.textContent = value;
      else if (key === 'className') node.className = value;
      else node.setAttribute(key, value === true ? '' : value);
    }
    for (const child of [].concat(children)) {
      if (child) node.appendChild(child);
    }
    return node;
  };

  Craft.MyBooksField = function (selector, config) {
    const root = document.querySelector(selector);
    if (!root || root.dataset.mybooksReady) return;
    root.dataset.mybooksReady = '1';

    const t = config.text;
    const stateInput = root.querySelector('.mybooks-field__state');
    let state;
    try {
      state = JSON.parse(stateInput.value || '{}');
    } catch (e) {
      state = {};
    }
    state.mode = state.mode === 'openlibrary' ? 'openlibrary' : 'manual';
    state.books = Array.isArray(state.books) ? state.books : [];
    state.shelves = Array.isArray(state.shelves) ? state.shelves : ['want', 'reading', 'read'];
    state.account = state.account || '';
    const thumbs = Object.assign({}, config.thumbs || {});

    const list = root.querySelector('.mybooks-field__list');
    const empty = root.querySelector('.mybooks-field__empty');
    const results = root.querySelector('.mybooks-field__results');
    const query = root.querySelector('.mybooks-field__query');
    const searchStatus = root.querySelector('.mybooks-field__search-status');
    const accountStatus = root.querySelector('.mybooks-field__account-status');

    const save = () => {
      stateInput.value = JSON.stringify(state);
      // Lets Craft notice the change (unsaved-changes warning, drafts).
      $(stateInput).trigger('change');
    };

    // --- Mode ---------------------------------------------------------------
    const panels = root.querySelectorAll('.mybooks-field__panel');
    const showMode = () => {
      panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.mode !== state.mode));
    };
    root.querySelectorAll('.mybooks-field__modes input[type=radio]').forEach((radio) => {
      radio.addEventListener('change', () => {
        state.mode = radio.value;
        showMode();
        save();
      });
    });

    // --- Hand-picked books ---------------------------------------------------
    const renderList = () => {
      list.replaceChildren();
      empty.classList.toggle('hidden', state.books.length > 0);

      state.books.forEach((book) => {
        const row = el('li', { className: 'mybooks-field__book', 'data-id': book.id });

        const handle = el('a', { className: 'move icon', role: 'button', 'aria-label': t.reorder, title: t.reorder, tabindex: '0' });
        // A stored cover when there is one; otherwise Open Library's small size.
        const src = thumbs[book.id] || (book.coverUrl ? book.coverUrl.replace(/-L\.jpg$/, '-S.jpg') : null);
        const cover = src
          ? el('img', { className: 'mybooks-field__thumb', src, alt: '', width: '40', height: '60', loading: 'lazy' })
          : el('span', { className: 'mybooks-field__thumb mybooks-field__thumb--empty', 'aria-hidden': 'true' });

        const title = el('input', { type: 'text', className: 'text fullwidth', value: book.title || '', 'aria-label': t.title, placeholder: t.title });
        title.addEventListener('input', () => { book.title = title.value; save(); });

        const authors = el('input', { type: 'text', className: 'text fullwidth', value: (book.authors || []).join(', '), 'aria-label': t.authors, placeholder: t.authors });
        authors.addEventListener('input', () => {
          book.authors = authors.value.split(',').map((a) => a.trim()).filter(Boolean);
          save();
        });

        const shelf = el('select', { 'aria-label': t.shelf });
        config.shelves.forEach((option) => {
          shelf.appendChild(el('option', { value: option.value, text: option.label, selected: option.value === book.shelf }));
        });
        const shelfWrap = el('div', { className: 'select' }, shelf);

        const progress = el('input', { type: 'number', min: '0', max: '100', className: 'text mybooks-field__progress', value: book.progress ?? '', 'aria-label': t.progress, placeholder: '%' });
        progress.addEventListener('input', () => {
          const value = parseInt(progress.value, 10);
          book.progress = Number.isNaN(value) ? null : Math.max(0, Math.min(100, value));
          save();
        });
        const toggleProgress = () => progress.classList.toggle('hidden', book.shelf !== 'reading');
        shelf.addEventListener('change', () => { book.shelf = shelf.value; toggleProgress(); save(); });
        toggleProgress();

        const remove = el('button', { type: 'button', className: 'delete icon', 'aria-label': t.remove + ': ' + (book.title || ''), title: t.remove });
        remove.addEventListener('click', () => {
          state.books = state.books.filter((b) => b !== book);
          save();
          renderList();
          renderResults(lastResults);
        });

        row.append(
          handle,
          cover,
          el('div', { className: 'mybooks-field__fields' }, [title, authors]),
          el('div', { className: 'mybooks-field__meta' }, [shelfWrap, progress]),
          remove
        );
        list.appendChild(row);
      });

      sorter.removeAllItems();
      sorter.addItems(list.children);
    };

    const sorter = new Garnish.DragSort({
      container: list,
      handle: '.move',
      axis: 'y',
      collapseDraggees: true,
      magnetStrength: 4,
      helperLagBase: 1.5,
      onSortChange: () => {
        const order = Array.from(list.children).map((row) => row.dataset.id);
        state.books.sort((a, b) => order.indexOf(a.id) - order.indexOf(b.id));
        save();
      },
    });

    const addBook = (data) => {
      const book = Object.assign({
        id: uuid(),
        shelf: 'reading',
        title: '',
        subtitle: null,
        authors: [],
        isbn: null,
        workId: null,
        url: null,
        coverUrl: null,
        progress: null,
        rating: null,
        startedAt: null,
        finishedAt: null,
      }, data);
      state.books.push(book);
      save();
      renderList();
      return book;
    };

    root.querySelector('.mybooks-field__add-manual').addEventListener('click', () => {
      addBook({ title: query.value.trim() });
      const inputs = list.querySelectorAll('.mybooks-field__book:last-child input[type=text]');
      if (inputs[0]) inputs[0].focus();
    });

    // --- Search ----------------------------------------------------------------
    let lastResults = [];
    const renderResults = (books) => {
      lastResults = books;
      results.replaceChildren();

      books.forEach((result) => {
        const already = state.books.some((b) => result.workId && b.workId === result.workId);
        const button = el('button', { type: 'button', className: 'btn small', disabled: already, text: already ? t.added : t.add });
        button.addEventListener('click', () => {
          addBook({
            title: result.title,
            subtitle: result.subtitle,
            authors: result.authors,
            isbn: result.isbn,
            workId: result.workId,
            url: result.url,
            coverUrl: result.coverUrl,
          });
          renderResults(lastResults);
        });

        results.appendChild(el('li', { className: 'mybooks-field__result' }, [
          result.coverUrl
            ? el('img', { className: 'mybooks-field__thumb', src: result.coverUrl.replace(/-L\.jpg$/, '-S.jpg'), alt: '', width: '40', height: '60', loading: 'lazy' })
            : el('span', { className: 'mybooks-field__thumb mybooks-field__thumb--empty', 'aria-hidden': 'true' }),
          el('div', {}, [
            el('div', { className: 'mybooks-field__result-title', text: result.title }),
            el('div', { className: 'light smalltext', text: (result.authors || []).join(', ') }),
          ]),
          button,
        ]));
      });
    };

    let searchRequest = 0;
    const search = () => {
      const q = query.value.trim();
      if (q.length < 2) return;
      const requestId = ++searchRequest;
      searchStatus.textContent = t.searching;
      searchStatus.classList.remove('error');

      Craft.sendActionRequest('POST', 'mybooks/field/search', { data: { q } })
        .then(({ data }) => {
          if (requestId !== searchRequest) return;
          const books = data.books || [];
          searchStatus.textContent = books.length ? Craft.t('mybooks', t.results, { count: books.length }) : t.noResults;
          renderResults(books);
        })
        .catch(({ response }) => {
          if (requestId !== searchRequest) return;
          searchStatus.textContent = (response && response.data && response.data.message) || t.error;
          searchStatus.classList.add('error');
        });
    };
    root.querySelector('.mybooks-field__search-btn').addEventListener('click', search);
    query.addEventListener('keydown', (event) => {
      // Enter must search, not submit the whole entry form.
      if (event.key === 'Enter') {
        event.preventDefault();
        search();
      }
    });

    // --- Linked account -----------------------------------------------------
    const account = root.querySelector('.mybooks-field__account');
    account.addEventListener('input', () => { state.account = account.value.trim(); save(); });
    root.querySelectorAll('.mybooks-field__shelf').forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        state.shelves = Array.from(root.querySelectorAll('.mybooks-field__shelf:checked')).map((c) => c.value);
        save();
      });
    });

    const accountAction = (action, button) => {
      button.classList.add('loading');
      accountStatus.textContent = '';
      Craft.sendActionRequest('POST', action, { data: { account: state.account } })
        .then(({ data }) => {
          accountStatus.textContent = data.message || '';
          accountStatus.classList.remove('error');
        })
        .catch(({ response }) => {
          accountStatus.textContent = (response && response.data && response.data.message) || t.error;
          accountStatus.classList.add('error');
        })
        .finally(() => button.classList.remove('loading'));
    };
    const testButton = root.querySelector('.mybooks-field__test');
    testButton.addEventListener('click', () => accountAction('mybooks/field/test-account', testButton));
    const syncButton = root.querySelector('.mybooks-field__sync');
    if (syncButton) {
      syncButton.addEventListener('click', () => accountAction('mybooks/field/sync-account', syncButton));
    }

    // No save() here: loading the field must not mark the entry as changed.
    showMode();
    renderList();
  };
})();
