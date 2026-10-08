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
type ProviderOption = { key: string; label: string; available: boolean };
type Tier = { key: string; label: string; comment: string | null; document: string | null };
type Provider = {
  chosen: string | null;
  in_use: string;
  default: string;
  own_key: boolean;
  options: ProviderOption[];
  tier?: string | null;
  tiers?: Tier[];
  models?: { comment: string; document: string };
};
type Features = { modules: Module[]; ai: { available: boolean; features: Feature[]; provider?: Provider } };

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

          <div className="space-y-6">
          {data.ai.provider && <ProviderCard provider={data.ai.provider} onSaved={(res) => { setData(res); setNotice(res.message); }} onError={setError} />}
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
        </div>
      )}
      <TutorControls />
    </PageShell>
  );
}

/**
 * Which AI writes for the school: Claude, ChatGPT or Gemini, among those
 * SchoolPilot has set up. The daily spend cap and teacher
 * approval of report-card remarks are the same whichever is chosen.
 */
function ProviderCard({ provider, onSaved, onError }: { provider: Provider; onSaved: (res: Features & { message: string }) => void; onError: (m: string | null) => void }) {
  const [choice, setChoice] = useState<string>(provider.chosen ?? '');
  const [tier, setTier] = useState<string>(provider.tier ?? '');
  const [busy, setBusy] = useState(false);
  const changed = choice !== (provider.chosen ?? '') || tier !== (provider.tier ?? '');
  const label = (key: string) => provider.options.find((o) => o.key === key)?.label ?? key;

  const save = async () => {
    setBusy(true);
    onError(null);
    try {
      onSaved(await fetchApi<Features & { message: string }>('/school/ai-provider', { method: 'PUT', body: JSON.stringify({ provider: choice || null, tier: tier || null }) }));
    } catch (err) {
      onError(errorText(err, 'Could not save the AI choice.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="AI provider and quality" description="Which AI writes drafts and answers the tutor for your school, and how capable a model it uses." className="h-fit">
      {provider.own_key ? (
        <p className="text-sm text-ink-muted">Your school uses its own {label(provider.in_use)} key, so that provider is used.</p>
      ) : (
        <div className="space-y-3">
          <p className="text-sm">In use now: <span className="font-semibold">{label(provider.in_use)}</span></p>
          <fieldset className="space-y-2 text-sm">
            <legend className="sr-only">Provider</legend>
            <label className="flex items-center gap-2">
              <input type="radio" name="ai-provider" className="accent-brand" checked={choice === ''} onChange={() => setChoice('')} />
              SchoolPilot&apos;s default ({label(provider.default)})
            </label>
            {provider.options.map((o) => (
              <label key={o.key} className={`flex items-center gap-2 ${o.available ? '' : 'text-ink-faint'}`}>
                <input type="radio" name="ai-provider" className="accent-brand" disabled={!o.available} checked={choice === o.key} onChange={() => setChoice(o.key)} />
                {o.label}
                {!o.available && <Badge tone="slate">not set up</Badge>}
              </label>
            ))}
          </fieldset>
          {provider.tiers && (
            <fieldset className="space-y-2 border-t border-line-soft pt-3 text-sm">
              <legend className="mb-1 font-semibold text-ink">Quality</legend>
              <label className="flex items-start gap-2">
                <input type="radio" name="ai-tier" className="mt-1 accent-brand" checked={tier === ''} onChange={() => setTier('')} />
                <span>
                  SchoolPilot&apos;s choice
                  {provider.models && !provider.tier && (
                    <span className="block text-xs text-ink-muted">{provider.models.comment} for remarks and the tutor, {provider.models.document} for documents</span>
                  )}
                </span>
              </label>
              {provider.tiers.filter((t) => t.comment).map((t) => (
                <label key={t.key} className="flex items-start gap-2">
                  <input type="radio" name="ai-tier" className="mt-1 accent-brand" checked={tier === t.key} onChange={() => setTier(t.key)} />
                  <span>
                    {t.label}
                    <span className="block text-xs text-ink-muted">
                      {t.comment === t.document ? `${t.comment} for everything` : `${t.comment} for remarks and the tutor, ${t.document} for documents`}
                    </span>
                  </span>
                </label>
              ))}
              {choice !== '' && choice !== provider.in_use && (
                <p className="text-xs text-ink-muted">Models shown are for {label(provider.in_use)}; they update once you save the new provider.</p>
              )}
            </fieldset>
          )}
          <p className="text-xs text-ink-muted">
            Better quality uses more of your school&apos;s daily AI allowance. The same limit applies whichever you choose, and AI remarks
            still wait for a teacher&apos;s approval.
          </p>
          <Button disabled={busy || !changed} onClick={save}>{busy ? 'Saving…' : 'Save AI choice'}</Button>
        </div>
      )}
    </Card>
  );
}
