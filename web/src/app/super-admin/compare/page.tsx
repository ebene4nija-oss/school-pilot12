'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * The public "SchoolPilot compared" table (operator side).
 *
 * Every cell is editable: the rows being compared, SchoolPilot's value for
 * each, and each competitor's. A competitor's values are claims about someone
 * else's product, so a competitor can only be published with the date you last
 * checked them and, for your own records, where you checked. The site prints
 * "checked <date>" under the name. Nothing here is pre-filled: the page stays
 * hidden from the site and from search until a competitor is published.
 */

interface RowDoc {
  label: string;
  schoolpilot: string;
}
interface CompetitorDoc {
  name: string;
  website: string;
  source_url: string;
  verified_on: string;
  published: boolean;
  values: string[];
}
interface Doc {
  rows: RowDoc[];
  competitors: CompetitorDoc[];
}

const today = () => new Date().toISOString().slice(0, 10);

export default function CompareAdminPage() {
  const [doc, setDoc] = useState<Doc>({ rows: [], competitors: [] });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    void (async () => {
      try {
        const res = await fetchApi<{ data: { rows: RowDoc[]; competitors: Partial<CompetitorDoc>[] } }>('/platform/comparison');
        setDoc({
          rows: res.data.rows,
          competitors: res.data.competitors.map((c) => ({
            name: c.name ?? '',
            website: c.website ?? '',
            source_url: c.source_url ?? '',
            verified_on: c.verified_on ?? '',
            published: !!c.published,
            values: c.values ?? [],
          })),
        });
      } catch (err) {
        setError(errorText(err, 'Could not load the comparison.'));
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  const edit = (fn: (d: Doc) => Doc) => {
    setNotice(null);
    setDoc((d) => fn(structuredClone(d)));
  };

  const addRow = () =>
    edit((d) => {
      d.rows.push({ label: '', schoolpilot: '' });
      d.competitors.forEach((c) => c.values.push(''));
      return d;
    });

  const removeRow = (i: number) =>
    edit((d) => {
      d.rows.splice(i, 1);
      d.competitors.forEach((c) => c.values.splice(i, 1));
      return d;
    });

  const addCompetitor = () =>
    edit((d) => {
      d.competitors.push({ name: '', website: '', source_url: '', verified_on: '', published: false, values: d.rows.map(() => '') });
      return d;
    });

  async function save() {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const body = {
        rows: doc.rows,
        competitors: doc.competitors.map((c) => ({
          ...c,
          website: c.website || null,
          source_url: c.source_url || null,
          verified_on: c.verified_on || null,
        })),
      };
      await fetchApi('/platform/comparison', { method: 'PUT', body: JSON.stringify(body) });
      setNotice('Saved. Published competitors show on the site after the next rebuild.');
    } catch (err) {
      setError(errorText(err, 'The comparison could not be saved.'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <PageShell
      title="Compare"
      subtitle="The public comparison table. Only publish values you have checked against the vendor's own published terms; the site shows the date you checked."
      actions={
        <Button disabled={busy || loading} onClick={() => void save()}>
          {busy ? 'Saving…' : 'Save'}
        </Button>
      }
    >
      <ErrorNote message={error} />
      {notice && (
        <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">
          {notice}
        </p>
      )}
      {loading ? (
        <Loading />
      ) : (
        <div className="space-y-6">
          <Card title="Rows and SchoolPilot's values" description="What is compared, and what SchoolPilot offers for each. Keep prices in step with Billing.">
            <div className="space-y-3">
              {doc.rows.map((r, i) => (
                <div key={i} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                  <Field label={i === 0 ? 'Row' : ''}>
                    <input
                      className={inputClass}
                      placeholder="e.g. Result computation"
                      value={r.label}
                      maxLength={120}
                      onChange={(e) => edit((d) => ((d.rows[i].label = e.target.value), d))}
                    />
                  </Field>
                  <Field label={i === 0 ? 'SchoolPilot' : ''}>
                    <input
                      className={inputClass}
                      placeholder="e.g. ₦500 per student per term"
                      value={r.schoolpilot}
                      maxLength={200}
                      onChange={(e) => edit((d) => ((d.rows[i].schoolpilot = e.target.value), d))}
                    />
                  </Field>
                  <Button variant="secondary" onClick={() => removeRow(i)} aria-label={`Remove row ${r.label || i + 1}`}>
                    Remove
                  </Button>
                </div>
              ))}
              <Button variant="secondary" onClick={addRow}>
                Add row
              </Button>
            </div>
          </Card>

          {doc.competitors.map((c, ci) => (
            <Card key={ci} title={c.name || `Competitor ${ci + 1}`}>
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Name">
                  <input className={inputClass} value={c.name} maxLength={80} onChange={(e) => edit((d) => ((d.competitors[ci].name = e.target.value), d))} />
                </Field>
                <Field label="Website (shown, https only)">
                  <input className={inputClass} inputMode="url" placeholder="https://" value={c.website} onChange={(e) => edit((d) => ((d.competitors[ci].website = e.target.value), d))} />
                </Field>
                <Field label="Where you checked (private, for your records)">
                  <input className={inputClass} inputMode="url" placeholder="https://…/pricing" value={c.source_url} onChange={(e) => edit((d) => ((d.competitors[ci].source_url = e.target.value), d))} />
                </Field>
                <Field label="Last checked on">
                  <div className="flex gap-2">
                    <input
                      type="date"
                      max={today()}
                      className={inputClass}
                      value={c.verified_on}
                      onChange={(e) => edit((d) => ((d.competitors[ci].verified_on = e.target.value), d))}
                    />
                    <Button variant="secondary" onClick={() => edit((d) => ((d.competitors[ci].verified_on = today()), d))}>
                      Today
                    </Button>
                  </div>
                </Field>
              </div>

              {doc.rows.length > 0 && (
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                  {doc.rows.map((r, i) => (
                    <Field key={i} label={r.label || `Row ${i + 1}`}>
                      <input
                        className={inputClass}
                        value={c.values[i] ?? ''}
                        maxLength={200}
                        placeholder="Leave empty if not confirmed"
                        onChange={(e) => edit((d) => ((d.competitors[ci].values[i] = e.target.value), d))}
                      />
                    </Field>
                  ))}
                </div>
              )}

              <div className="mt-4 flex flex-wrap items-center gap-4">
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={c.published}
                    onChange={(e) => edit((d) => ((d.competitors[ci].published = e.target.checked), d))}
                  />
                  Published on the site (needs a “last checked” date)
                </label>
                <Button
                  variant="danger"
                  className="ml-auto"
                  onClick={() => {
                    if (window.confirm(`Remove ${c.name || 'this competitor'}?`)) edit((d) => (d.competitors.splice(ci, 1), d));
                  }}
                >
                  Remove competitor
                </Button>
              </div>
            </Card>
          ))}

          <Button variant="secondary" onClick={addCompetitor} disabled={doc.competitors.length >= 8}>
            Add competitor
          </Button>
        </div>
      )}
    </PageShell>
  );
}
