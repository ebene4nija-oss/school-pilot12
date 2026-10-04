'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Loading, PageShell } from '@/components/Page';
import TutorControls from '@/components/TutorControls';

/**
 * Which AI features this school uses.
 *
 * SchoolPilot decides whether a school has AI at all; within that, the school
 * decides feature by feature — a school may want remarks written by hand, or
 * no tutor chat for its students. Report-card remark drafts, when on, still
 * never reach a report card until a teacher approves or edits them.
 */

type Feature = { key: string; label: string; hint: string; chosen: boolean; active: boolean };
type Module = { key: string; label: string; enabled: boolean };
type Features = { modules: Module[]; ai: { available: boolean; features: Feature[] } };

export default function AiFeaturesPage() {
  const [data, setData] = useState<Features | null>(null);
  const [choices, setChoices] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<Features>('/school/features')
      .then((res) => {
        setData(res);
        setChoices(Object.fromEntries(res.ai.features.map((f) => [f.key, f.chosen])));
      })
      .catch((err) => setError(errorText(err, 'Could not load AI settings.')));
  }, []);

  const save = async () => {
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const res = await fetchApi<Features & { message: string }>('/school/ai-features', {
        method: 'PUT',
        body: JSON.stringify({ features: choices }),
      });
      setData(res);
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'Could not save.'));
    } finally {
      setBusy(false);
    }
  };

  const changed = data?.ai.features.some((f) => f.chosen !== choices[f.key]) ?? false;

  return (
    <PageShell title="AI features" subtitle="Choose which AI tools your teachers and students can use.">
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      {!data ? (
        !error && <Loading />
      ) : (
        <div className="grid gap-6 xl:grid-cols-3">
          <div className="xl:col-span-2">
            {!data.ai.available && (
              <p className="mb-6 rounded-lg bg-caution-soft px-4 py-3 text-sm text-caution">
                SchoolPilot has switched AI off for your school, so none of these is active right now. Your choices are
                kept and take effect if AI is switched back on — contact SchoolPilot about it.
              </p>
            )}

            <Card title="AI tools" description="Switched off, a tool disappears for everyone in the school straight away.">
              <ul className="divide-y divide-line-soft">
                {data.ai.features.map((f) => (
                  <li key={f.key} className="flex items-start justify-between gap-4 py-4">
                    <div>
                      <p className="text-sm font-semibold text-ink">{f.label}</p>
                      <p className="mt-0.5 text-xs text-ink-muted">{f.hint}</p>
                    </div>
                    <label className="flex shrink-0 cursor-pointer items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        role="switch"
                        aria-label={f.label}
                        checked={choices[f.key] ?? true}
                        onChange={(e) => setChoices({ ...choices, [f.key]: e.target.checked })}
                        className="h-4 w-4 accent-brand"
                      />
                      <span className={choices[f.key] ? 'text-positive' : 'text-ink-muted'}>{choices[f.key] ? 'On' : 'Off'}</span>
                    </label>
                  </li>
                ))}
              </ul>
              <Button className="mt-4" disabled={busy || !changed} onClick={save}>
                {busy ? 'Saving…' : 'Save changes'}
              </Button>
            </Card>
          </div>

          <Card title="Modules on your plan" description="Set by SchoolPilot. Ask SchoolPilot to change these." className="h-fit">
            <ul className="space-y-2">
              {data.modules.map((m) => (
                <li key={m.key} className="flex items-center justify-between text-sm">
                  <span className="text-ink">{m.label}</span>
                  <Badge tone={m.enabled ? 'green' : 'slate'}>{m.enabled ? 'On' : 'Off'}</Badge>
                </li>
              ))}
            </ul>
          </Card>
        </div>
      )}
      <TutorControls />
    </PageShell>
  );
}
