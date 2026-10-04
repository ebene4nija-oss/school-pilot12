'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import {
  Badge,
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
} from '@/components/Page';
import LibraryDesk from '@/components/LibraryDesk';

/**
 * The library catalogue.
 *
 * Was a hardcoded shelf of invented titles. The only real endpoint was the
 * barcode lookup — which the page also faked — so a librarian could scan a book
 * and never see the catalogue it came from.
 *
 * Lookup is by phone camera or a typed barcode, per the hardware-free rule
 * (doc §3): no scanner gun, no purchased reader.
 */

interface Book {
  id: number;
  title: string;
  author: string;
  isbn: string | null;
  barcode: string;
  total_copies: number;
  available_copies: number;
  on_loan: number;
  overdue: number;
}

export default function LibraryPage() {
  const [books, setBooks] = useState<Book[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showForm, setShowForm] = useState(false);

  const [title, setTitle] = useState('');
  const [author, setAuthor] = useState('');
  const [isbn, setIsbn] = useState('');
  const [barcode, setBarcode] = useState('');
  const [copies, setCopies] = useState('1');

  const [scan, setScan] = useState('');
  const [scanned, setScanned] = useState<Book | null>(null);

  /** Returns the catalogue; the caller owns the state. */
  const fetchBooks = useCallback(async (term = '') => {
    const query = term.trim() ? `?search=${encodeURIComponent(term.trim())}` : '';
    const res = await fetchApi<{ data: Book[] }>(`/library/books${query}`);

    return res.data ?? [];
  }, []);

  const load = useCallback(
    async (term = '') => {
      setBooks(await fetchBooks(term));
    },
    [fetchBooks],
  );

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const catalogue = await fetchBooks();
        if (!cancelled) setBooks(catalogue);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the catalogue.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [fetchBooks]);

  async function addBook(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/library/books', {
        method: 'POST',
        body: JSON.stringify({
          title: title.trim(),
          author: author.trim(),
          isbn: isbn.trim() || null,
          barcode: barcode.trim(),
          total_copies: Number(copies),
        }),
      });

      setNotice(`"${title.trim()}" added to the catalogue.`);
      setTitle('');
      setAuthor('');
      setIsbn('');
      setBarcode('');
      setCopies('1');
      setShowForm(false);
      await load(search);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That book could not be added.');
    } finally {
      setBusy(false);
    }
  }

  async function lookup(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setScanned(null);

    try {
      const res = await fetchApi<{ data: Book }>('/library/scan-barcode', {
        method: 'POST',
        body: JSON.stringify({ barcode: scan.trim() }),
      });

      setScanned(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No book matched that barcode.');
    } finally {
      setBusy(false);
    }
  }

  const reloadCatalogue = useCallback(() => {
    load(search).catch(() => undefined);
  }, [load, search]);

  const onLoan = books.reduce((sum, b) => sum + b.on_loan, 0);
  const overdue = books.reduce((sum, b) => sum + b.overdue, 0);

  return (
    <PageShell
      title="Library"
      subtitle="Catalogue, loans and barcode lookup."
      actions={
        <Button onClick={() => setShowForm((v) => !v)} variant={showForm ? 'secondary' : 'primary'}>
          {showForm ? 'Cancel' : 'Add a book'}
        </Button>
      }
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}

      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-3">
            <StatTile label="Titles" value={books.length} />
            <StatTile label="On loan" value={onLoan} />
            <StatTile label="Overdue" value={overdue} tone={overdue > 0 ? 'red' : 'green'} />
          </div>

          {showForm && (
            <Card title="Add a book">
              <form onSubmit={addBook} className="grid gap-4 sm:grid-cols-2">
                <Field label="Title">
                  <input required value={title} onChange={(e) => setTitle(e.target.value)} className={inputClass} />
                </Field>
                <Field label="Author">
                  <input required value={author} onChange={(e) => setAuthor(e.target.value)} className={inputClass} />
                </Field>
                <Field label="Barcode">
                  <input
                    required
                    value={barcode}
                    onChange={(e) => setBarcode(e.target.value)}
                    className={inputClass}
                    placeholder="Scanned or typed — must be unique in this school"
                  />
                </Field>
                <Field label="ISBN (optional)">
                  <input value={isbn} onChange={(e) => setIsbn(e.target.value)} className={inputClass} />
                </Field>
                <Field label="Copies">
                  <input
                    required
                    type="number"
                    min={1}
                    value={copies}
                    onChange={(e) => setCopies(e.target.value)}
                    className={inputClass}
                  />
                </Field>
                <div className="sm:col-span-2">
                  <Button type="submit" disabled={busy}>
                    {busy ? 'Saving…' : 'Add to catalogue'}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          <Card
            title="Barcode lookup"
            description="Type or scan a barcode with a phone camera — no reader hardware required."
          >
            <form onSubmit={lookup} className="flex flex-wrap gap-2">
              <input
                value={scan}
                onChange={(e) => setScan(e.target.value)}
                placeholder="Barcode"
                aria-label="Barcode to look up"
                className={`${inputClass} max-w-xs`}
              />
              <Button type="submit" variant="secondary" disabled={busy || !scan.trim()}>
                Look up
              </Button>
            </form>

            {scanned && (
              <div className="mt-4 rounded-lg border border-line-soft bg-surface-sunken p-4">
                <p className="font-semibold text-brand">{scanned.title}</p>
                <p className="text-sm text-ink-muted">{scanned.author}</p>
                <p className="mt-2 text-sm text-ink">
                  {scanned.available_copies} of {scanned.total_copies} copy/copies available
                </p>
              </div>
            )}
          </Card>

          <LibraryDesk onChange={reloadCatalogue} />

          <Card title="Catalogue">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                load(search).catch(() => undefined);
              }}
              className="mb-4 flex flex-wrap gap-2"
            >
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search by title, author, ISBN or barcode"
                aria-label="Search the catalogue"
                className={`${inputClass} max-w-sm`}
              />
              <Button type="submit" variant="secondary">
                Search
              </Button>
              {search && (
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => {
                    setSearch('');
                    load('').catch(() => undefined);
                  }}
                >
                  Clear
                </Button>
              )}
            </form>

            {books.length === 0 ? (
              <Empty>
                {search ? 'No book matches that search.' : 'The catalogue is empty. Add a book to start.'}
              </Empty>
            ) : (
              <Table columns={['Title', 'Author', 'Barcode', 'Available', 'On loan', 'Overdue']}>
                {books.map((book) => (
                  <tr key={book.id}>
                    <Cell className="font-medium text-brand">
                      {book.title}
                      {book.isbn && <span className="block text-xs text-ink-muted">ISBN {book.isbn}</span>}
                    </Cell>
                    <Cell>{book.author}</Cell>
                    <Cell className="font-mono text-xs">{book.barcode}</Cell>
                    <Cell>
                      {book.available_copies} / {book.total_copies}
                    </Cell>
                    <Cell>{book.on_loan}</Cell>
                    <Cell>{book.overdue > 0 ? <Badge tone="red">{book.overdue}</Badge> : '—'}</Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>
      )}
    </PageShell>
  );
}
