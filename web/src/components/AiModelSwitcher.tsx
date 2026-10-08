'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, ErrorNote, Field, Loading, inputClass } from '@/components/Page';

/**
 * Switching AI provider and model in a click or two.
 *
 * One card per provider (Claude, ChatGPT, Gemini): make it the default, pick
 * a preset (fast / balanced / best), or choose each model from the list with
 * its price beside it. "Test" makes one tiny live call with SchoolPilot's key,
 * so a new key or model is proven before any school relies on it.
 *
 * Changes save through /platform/settings like every other console setting,
 * so they are validated, audited and live immediately. Keys are entered in
 * the AI service card below; this panel never sees one.
 */

type Purpose = 'comment' | 'document';
interface Model { id: string; label: string; tier: string; input: number; output: number; note: string | null }
interface Provider {
  key: string;
  label: string;
  is_default: boolean;
  key_set: boolean;
  comment_model: string;
  document_model: string;
  unpriced: string[];
  models: Model[];
  presets: Record<string, { comment: string; document: string }>;
}
interface Catalogue { default_provider: string; tiers: Record<string, string>; providers: Provider[] }
interface TestResult { ok: boolean; model: string; reply?: string; error?: string | null; ms: number }

const PURPOSE: Record<Purpose, { label: string; hint: string }> = {
  comment: { label: 'Short output', hint: 'Report-card remarks and the student tutor' },
  document: { label: 'Documents', hint: 'Lesson plans, worksheets and exam drafts' },
};

const usd = (n: number) => `$${n < 1 ? n.toFixed(2) : n.toFixed(n % 1 ? 2 : 0)}`;

export default function AiModelSwitcher({ onChanged }: { onChanged?: () => void }) {
  const [data, setData] = useState<Catalogue | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await fetchApi<Catalogue>('/platform/ai/models'));
    } catch (err) {
      setError(errorText(err, 'Could not load the AI models.'));
    }
  }, []);

  useEffect(() => {
    void (async () => {
      await load();
    })();
  }, [load]);

  async function save(values: Record<string, string>, done: string, tag: string) {
    setBusy(tag);
    setError(null);
    setNotice(null);
    try {
      await fetchApi('/platform/settings', { method: 'PUT', body: JSON.stringify({ values }) });
      setNotice(done);
      await load();
      onChanged?.();
      return true;
    } catch (err) {
      setError(errorText(err, 'That change was not saved.'));
      return false;
    } finally {
      setBusy(null);
    }
  }

  if (!data) return error ? <ErrorNote message={error} /> : <Loading />;

  const current = data.providers.find((p) => p.is_default);

  return (
    <Card
      title="AI provider and models"
      description={`Every school uses ${current?.label ?? 'the default'} unless it has chosen another provider you hold a key for. Changes take effect on the next AI request.`}
    >
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-4 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}
      <div className="grid gap-4 xl:grid-cols-3">
        {data.providers.map((p) => (
          <ProviderCard
            key={`${p.key}-${p.comment_model}-${p.document_model}`}
            provider={p}
            tiers={data.tiers}
            busy={busy}
            onSave={save}
          />
        ))}
      </div>
      <p className="mt-4 text-xs text-ink-muted">
        Prices are the providers&apos; list prices in US dollars per million tokens (input / output), checked 8 October 2026. A model
        typed in by id that is not on the list is charged against the daily cap at the highest known rate until its price is added.
      </p>
    </Card>
  );
}

function ProviderCard({
  provider: p,
  tiers,
  busy,
  onSave,
}: {
  provider: Provider;
  tiers: Record<string, string>;
  busy: string | null;
  onSave: (values: Record<string, string>, done: string, tag: string) => Promise<boolean>;
}) {
  const [models, setModels] = useState<Record<Purpose, string>>({ comment: p.comment_model, document: p.document_model });
  const [test, setTest] = useState<TestResult | null>(null);
  const [testing, setTesting] = useState(false);
  const changed = models.comment !== p.comment_model || models.document !== p.document_model;
  const name = (id: string) => p.models.find((m) => m.id === id)?.label ?? id;
  const activePreset = Object.entries(p.presets).find(([, v]) => v.comment === p.comment_model && v.document === p.document_model)?.[0];

  async function runTest() {
    setTesting(true);
    setTest(null);
    try {
      setTest(await fetchApi<TestResult>('/platform/ai/test', { method: 'POST', body: JSON.stringify({ provider: p.key, model: p.comment_model }) }));
    } catch (err) {
      setTest({ ok: false, model: p.comment_model, error: errorText(err, 'The test could not run.'), ms: 0 });
    } finally {
      setTesting(false);
    }
  }

  const keyName = (purpose: Purpose) => `services.${p.key}.${purpose}_model`;

  return (
    <div className={`rounded-xl border p-4 ${p.is_default ? 'border-brand bg-brand/5' : 'border-line-soft'}`}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-headline text-base font-semibold text-ink">{p.label}</h3>
        <div className="flex gap-1">
          {p.is_default && <Badge tone="blue">Default</Badge>}
          <Badge tone={p.key_set ? 'green' : 'slate'}>{p.key_set ? 'Key set' : 'No key'}</Badge>
        </div>
      </div>

      {!p.key_set && <p className="mt-2 text-xs text-ink-muted">Add the {p.label} API key in the AI service card below to use it.</p>}

      <div className="mt-3 flex flex-wrap gap-2">
        {!p.is_default && (
          <Button
            variant="secondary"
            disabled={!p.key_set || busy !== null}
            onClick={() => void onSave({ 'services.ai.provider': p.key }, `${p.label} is now the default for every school that has not chosen its own.`, `${p.key}-default`)}
          >
            Make default
          </Button>
        )}
        <Button variant="secondary" disabled={!p.key_set || testing} onClick={() => void runTest()}>
          {testing ? 'Testing…' : 'Test'}
        </Button>
      </div>

      {test && (
        <p role="status" className={`mt-2 rounded-lg px-3 py-2 text-xs ${test.ok ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative'}`}>
          {test.ok ? `Working: ${name(test.model)} replied “${test.reply}” in ${(test.ms / 1000).toFixed(1)}s.` : `${name(test.model)} failed: ${test.error}`}
        </p>
      )}

      <div className="mt-4">
        <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-ink-muted">Quick choice</p>
        <div className="flex flex-wrap gap-2">
          {Object.entries(p.presets).map(([tier, preset]) => (
            <Button
              key={tier}
              variant={activePreset === tier ? 'primary' : 'secondary'}
              disabled={busy !== null}
              title={`${name(preset.comment)} for short output, ${name(preset.document)} for documents`}
              onClick={() =>
                void onSave(
                  { [keyName('comment')]: preset.comment, [keyName('document')]: preset.document },
                  `${p.label}: ${tiers[tier] ?? tier} — ${name(preset.comment)} and ${name(preset.document)}.`,
                  `${p.key}-${tier}`,
                )
              }
            >
              {tiers[tier] ?? tier}
            </Button>
          ))}
        </div>
      </div>

      <div className="mt-4 space-y-3">
        {(Object.keys(PURPOSE) as Purpose[]).map((purpose) => (
          <ModelPicker
            key={purpose}
            id={`${p.key}-${purpose}`}
            label={PURPOSE[purpose].label}
            hint={PURPOSE[purpose].hint}
            models={p.models}
            tiers={tiers}
            value={models[purpose]}
            onChange={(v) => setModels({ ...models, [purpose]: v })}
          />
        ))}
        {p.unpriced.length > 0 && (
          <p className="text-xs text-caution">No list price for {p.unpriced.join(', ')}: charged at the highest known rate.</p>
        )}
        <Button
          disabled={!changed || busy !== null || !models.comment.trim() || !models.document.trim()}
          onClick={() =>
            void onSave(
              { [keyName('comment')]: models.comment.trim(), [keyName('document')]: models.document.trim() },
              `${p.label} models saved.`,
              `${p.key}-models`,
            )
          }
        >
          {busy === `${p.key}-models` ? 'Saving…' : 'Save models'}
        </Button>
      </div>
    </div>
  );
}

function ModelPicker({
  id,
  label,
  hint,
  models,
  tiers,
  value,
  onChange,
}: {
  id: string;
  label: string;
  hint: string;
  models: Model[];
  tiers: Record<string, string>;
  value: string;
  onChange: (v: string) => void;
}) {
  const listed = models.some((m) => m.id === value);
  const [other, setOther] = useState(!listed);
  const note = models.find((m) => m.id === value)?.note;

  return (
    <Field label={label}>
      <select
        id={id}
        className={inputClass}
        value={other ? '__other' : value}
        onChange={(e) => {
          if (e.target.value === '__other') {
            setOther(true);
            onChange('');
          } else {
            setOther(false);
            onChange(e.target.value);
          }
        }}
      >
        {Object.entries(tiers).map(([tier, tierLabel]) => (
          <optgroup key={tier} label={tierLabel}>
            {models.filter((m) => m.tier === tier).map((m) => (
              <option key={m.id} value={m.id}>
                {m.label} · {usd(m.input)} / {usd(m.output)}
              </option>
            ))}
          </optgroup>
        ))}
        <option value="__other">Other model id…</option>
      </select>
      {other && (
        <input
          className={`${inputClass} mt-2`}
          placeholder="Exact model id from the provider's documentation"
          value={value}
          maxLength={80}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
      <span className="mt-1 block text-xs text-ink-muted">{note ?? hint}</span>
    </Field>
  );
}
