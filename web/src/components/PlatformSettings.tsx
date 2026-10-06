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
  Loading,
  Meter,
  PageShell,
  Table,
  inputClass,
  nairaAmount,
} from '@/components/Page';

/**
 * Platform settings and AI configuration.
 *
 * Every value here used to live only in backend.env on the server, so rotating
 * a leaked key or raising the AI cap in exam week meant an SSH session. Values
 * saved here override the server's; anything left alone keeps the server's.
 *
 * Secrets are write-only. This page can set, replace or remove a key, and shows
 * that one is set and its last four characters — enough to tell which key is
 * live. No response the page receives ever contains a key.
 *
 * One component behind two menu items: "AI service" shows the AI group and
 * today's spend, "Platform settings" shows every other group. Both read and
 * write the same `/platform/settings` registry.
 */

type Source = 'console' | 'server' | 'unset' | 'unreadable';

interface Setting {
  key: string;
  label: string;
  type: 'secret' | 'string' | 'select' | 'bool' | 'number' | 'integer' | 'email' | 'naira' | 'phone' | 'url';
  help: string | null;
  options: string[] | null;
  min: number | null;
  max: number | null;
  multiline: boolean;
  source: Source;
  is_set: boolean;
  value?: string | number | boolean | null;
  server_value?: string | number | boolean | null;
  hint?: string | null;
  updated_at: string | null;
  updated_by: string | null;
}

interface Group {
  key: string;
  label: string;
  settings: Setting[];
}

interface AiUsage {
  date: string;
  cap_naira: number | null;
  schools_with_ai_enabled: number;
  default_provider?: string;
  configured_providers?: string[];
  data: {
    school_id: number;
    school: string | null;
    subdomain: string | null;
    provider?: string;
    own_key?: boolean;
    calls: number;
    spent_naira: number;
    cap_used_percentage: number | null;
    at_cap: boolean;
  }[];
}

const PROVIDER_LABEL: Record<string, string> = { anthropic: 'Claude', openai: 'ChatGPT', gemini: 'Gemini' };

const SOURCE_BADGE: Record<Source, { tone: 'green' | 'blue' | 'slate' | 'red'; label: string }> = {
  console: { tone: 'green', label: 'Set here' },
  server: { tone: 'blue', label: 'From server config' },
  unset: { tone: 'slate', label: 'Not set' },
  unreadable: { tone: 'red', label: 'Unreadable — re-enter' },
};

export default function PlatformSettings({
  section,
  title,
  subtitle,
  children,
}: {
  /**
   * `ai` is the AI group alone, `website` the marketing site's group alone,
   * `integrations` every other group.
   */
  section: 'ai' | 'integrations' | 'website';
  title: string;
  subtitle: string;
  /** Extra cards under the settings, e.g. the website's screenshots. */
  children?: React.ReactNode;
}) {
  const [allGroups, setGroups] = useState<Group[]>([]);
  const groups = allGroups.filter((group) =>
    section === 'integrations' ? group.key !== 'ai' && group.key !== 'website' : group.key === section,
  );
  const [usage, setUsage] = useState<AiUsage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [savingGroup, setSavingGroup] = useState<string | null>(null);

  // Edits held per key until saved; untouched keys are never sent.
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const fetchAll = useCallback(async () => {
    const [settings, ai] = await Promise.all([
      fetchApi<{ groups: Group[] }>('/platform/settings'),
      section === 'ai' ? fetchApi<AiUsage>('/platform/ai-usage').catch(() => null) : null,
    ]);

    return { groups: settings.groups, usage: ai };
  }, [section]);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const result = await fetchAll();

        if (!cancelled) {
          setGroups(result.groups);
          setUsage(result.usage);
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof Error ? err.message : 'Could not load platform settings.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [fetchAll]);

  function edit(key: string, value: string) {
    setDrafts((d) => ({ ...d, [key]: value }));
    setFieldErrors((e) => {
      const next = { ...e };
      delete next[key];
      return next;
    });
  }

  async function save(group: Group, clear: string[] = []) {
    const values: Record<string, string> = {};

    for (const setting of group.settings) {
      if (setting.key in drafts) values[setting.key] = drafts[setting.key];
    }

    if (Object.keys(values).length === 0 && clear.length === 0) {
      setNotice('Nothing has changed.');
      return;
    }

    setSavingGroup(group.key);
    setError(null);
    setNotice(null);
    setFieldErrors({});

    try {
      const res = await fetchApi<{ groups: Group[] }>('/platform/settings', {
        method: 'PUT',
        body: JSON.stringify({ values, clear }),
      });

      setGroups(res.groups);
      setDrafts((d) => {
        const next = { ...d };
        for (const setting of group.settings) delete next[setting.key];
        return next;
      });
      setNotice(
        group.key === 'website'
          ? 'Website saved. It shows on the public site after the next site rebuild.'
          : `${group.label} saved. It takes effect immediately.`,
      );

      if (section === 'ai') {
        const ai = await fetchApi<AiUsage>('/platform/ai-usage').catch(() => null);
        setUsage(ai);
      }
    } catch (err) {
      const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;

      if (payload?.errors) {
        const mapped: Record<string, string> = {};

        for (const [field, messages] of Object.entries(payload.errors)) {
          mapped[field.replace(/^values\./, '')] = messages[0];
        }

        setFieldErrors(mapped);
        setError('Some settings were not valid.');
      } else {
        setError(err instanceof Error ? err.message : 'Those settings could not be saved.');
      }
    } finally {
      setSavingGroup(null);
    }
  }

  return (
    <PageShell title={title} subtitle={subtitle}>
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
          {groups.map((group) => (
            <Card key={group.key} title={group.label}>
              <div className="divide-y divide-line-soft">
                {group.settings.map((setting) => (
                  <SettingRow
                    key={setting.key}
                    setting={setting}
                    draft={drafts[setting.key]}
                    error={fieldErrors[setting.key]}
                    onEdit={(value) => edit(setting.key, value)}
                    onRemove={() => save(group, [setting.key])}
                    busy={savingGroup === group.key}
                  />
                ))}
              </div>

              <div className="mt-4 flex justify-end">
                <Button onClick={() => save(group)} disabled={savingGroup === group.key}>
                  {savingGroup === group.key ? 'Saving…' : `Save ${group.label.toLowerCase()}`}
                </Button>
              </div>

              {group.key === 'ai' && usage && <AiUsagePanel usage={usage} />}
            </Card>
          ))}

          {children}

          <p className="text-xs text-ink-muted">
            Keys are encrypted with this server&apos;s application key before they are stored. If that
            key is ever rotated, values saved here become unreadable and the server&apos;s own
            configuration takes over until they are entered again.
          </p>
        </div>
      )}
    </PageShell>
  );
}

function SettingRow({
  setting,
  draft,
  error,
  onEdit,
  onRemove,
  busy,
}: {
  setting: Setting;
  draft: string | undefined;
  error: string | undefined;
  onEdit: (value: string) => void;
  onRemove: () => void;
  busy: boolean;
}) {
  const badge = SOURCE_BADGE[setting.source];
  const id = `setting-${setting.key}`;

  return (
    <div className="grid gap-3 py-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <label htmlFor={id} className="text-sm font-semibold text-ink">
            {setting.label}
          </label>
          <Badge tone={badge.tone}>{badge.label}</Badge>
        </div>
        {setting.help && <p className="mt-1 text-xs text-ink-muted">{setting.help}</p>}
        {setting.updated_by && setting.updated_at && (
          <p className="mt-1 text-xs text-ink-faint">
            Changed by {setting.updated_by},{' '}
            {new Date(setting.updated_at).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' })}
          </p>
        )}
      </div>

      <div>
        <Control setting={setting} id={id} draft={draft} onEdit={onEdit} />

        {error && <p className="mt-1 text-xs text-negative">{error}</p>}

        {setting.type === 'secret' && setting.source === 'console' && (
          <button
            onClick={onRemove}
            disabled={busy}
            className="mt-2 text-xs font-medium text-negative underline hover:opacity-80 disabled:opacity-60"
          >
            Remove the key set here — the server&apos;s own key, if any, takes over
          </button>
        )}
      </div>
    </div>
  );
}

function Control({
  setting,
  id,
  draft,
  onEdit,
}: {
  setting: Setting;
  id: string;
  draft: string | undefined;
  onEdit: (value: string) => void;
}) {
  const serverNote =
    setting.server_value !== null && setting.server_value !== undefined
      ? `Server default: ${formatValue(setting, setting.server_value)}`
      : 'Server default: not set';

  switch (setting.type) {
    case 'secret':
      return (
        <div>
          {setting.is_set && setting.hint && (
            <p className="mb-1 font-mono text-xs text-ink-muted">Current: {setting.hint}</p>
          )}
          {setting.multiline ? (
            <textarea
              id={id}
              rows={4}
              value={draft ?? ''}
              onChange={(e) => onEdit(e.target.value)}
              placeholder={setting.is_set ? 'Paste a new file to replace it' : 'Paste the file contents'}
              className={`${inputClass} font-mono text-xs`}
              autoComplete="off"
              spellCheck={false}
            />
          ) : (
            <input
              id={id}
              type="password"
              value={draft ?? ''}
              onChange={(e) => onEdit(e.target.value)}
              placeholder={setting.is_set ? 'Enter a new key to replace it' : 'Enter the key'}
              className={`${inputClass} font-mono`}
              autoComplete="new-password"
              spellCheck={false}
            />
          )}
        </div>
      );

    case 'select':
      return (
        <div>
          <select
            id={id}
            value={draft ?? (setting.source === 'console' ? String(setting.value ?? '') : '')}
            onChange={(e) => onEdit(e.target.value)}
            className={inputClass}
          >
            <option value="">Use server default</option>
            {setting.options?.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
          <p className="mt-1 text-xs text-ink-faint">
            In use: {formatValue(setting, setting.value)} · {serverNote}
          </p>
        </div>
      );

    case 'bool':
      return (
        <div>
          <select
            id={id}
            value={draft ?? (setting.source === 'console' ? (setting.value ? '1' : '0') : '')}
            onChange={(e) => onEdit(e.target.value)}
            className={inputClass}
          >
            <option value="">Use server default</option>
            <option value="1">On</option>
            <option value="0">Off</option>
          </select>
          <p className="mt-1 text-xs text-ink-faint">
            In use: {setting.value ? 'On' : 'Off'} · {serverNote}
          </p>
        </div>
      );

    case 'naira':
      return (
        <div>
          <div className="flex items-center">
            <span className="rounded-l-lg border border-r-0 border-line bg-surface-sunken px-3 py-2 text-sm text-ink-muted">
              ₦
            </span>
            <input
              id={id}
              type="number"
              min={0}
              step="1"
              value={draft ?? (setting.source === 'console' ? String(setting.value ?? '') : '')}
              onChange={(e) => onEdit(e.target.value)}
              placeholder="No cap"
              className={`${inputClass} rounded-l-none`}
            />
          </div>
          <p className="mt-1 text-xs text-ink-faint">
            In use: {setting.value ? nairaAmount(Number(setting.value)) : 'no cap'} · {serverNote}
          </p>
        </div>
      );

    default: {
      const numeric = setting.type === 'number' || setting.type === 'integer';

      return (
        <div>
          <input
            id={id}
            type={numeric ? 'number' : setting.type === 'email' ? 'email' : setting.type === 'phone' ? 'tel' : setting.type === 'url' ? 'url' : 'text'}
            step={setting.type === 'integer' ? '1' : setting.type === 'number' ? 'any' : undefined}
            min={numeric ? (setting.min ?? undefined) : undefined}
            max={numeric ? (setting.max ?? undefined) : undefined}
            maxLength={setting.type === 'string' ? (setting.max ?? undefined) : undefined}
            value={draft ?? (setting.source === 'console' ? String(setting.value ?? '') : '')}
            onChange={(e) => onEdit(e.target.value)}
            placeholder="Use server default"
            className={inputClass}
          />
          <p className="mt-1 text-xs text-ink-faint">
            In use: {formatValue(setting, setting.value)} · {serverNote}
          </p>
        </div>
      );
    }
  }
}

function formatValue(setting: Setting, value: Setting['value']): string {
  if (value === null || value === undefined || value === '') return 'not set';
  if (setting.type === 'naira') return nairaAmount(Number(value));
  if (setting.type === 'bool') return value ? 'On' : 'Off';
  return String(value);
}

/**
 * Today's spend by school against the cap.
 *
 * The call that comes in is "the AI stopped working for us" — nearly always a
 * school that has hit today's cap. This answers it without a database session.
 */
function AiUsagePanel({ usage }: { usage: AiUsage }) {
  return (
    <div className="mt-6 border-t border-line-soft pt-5">
      <h3 className="font-headline text-sm font-semibold text-brand">Today&apos;s AI spend</h3>
      <p className="mt-1 text-xs text-ink-muted">
        {usage.cap_naira === null
          ? 'No daily cap is set, so no school is ever refused.'
          : `Cap: ${nairaAmount(usage.cap_naira)} per school per day.`}{' '}
        {usage.schools_with_ai_enabled} school(s) have AI switched on.
        {usage.default_provider && (
          <>
            {' '}Default provider: {PROVIDER_LABEL[usage.default_provider] ?? usage.default_provider}.{' '}
            {usage.configured_providers && usage.configured_providers.length > 0
              ? `Keys set for ${usage.configured_providers.map((p) => PROVIDER_LABEL[p] ?? p).join(', ')} — schools can choose among these.`
              : 'No provider key is set yet.'}
          </>
        )}
      </p>

      {usage.data.length === 0 ? (
        <div className="mt-4">
          <Empty>No AI use recorded today.</Empty>
        </div>
      ) : (
        <div className="mt-4">
          <Table columns={['School', 'Provider', 'Calls', 'Spent', 'Of cap']}>
            {usage.data.map((row) => (
              <tr key={row.school_id}>
                <Cell className="font-medium text-brand">
                  {row.school ?? `School #${row.school_id}`}
                  {row.subdomain && <span className="block text-xs text-ink-faint">{row.subdomain}</span>}
                </Cell>
                <Cell>
                  {row.provider ? PROVIDER_LABEL[row.provider] ?? row.provider : '—'}
                  {row.own_key && <span className="block text-xs text-ink-faint">school&apos;s own key</span>}
                </Cell>
                <Cell>{row.calls.toLocaleString('en-NG')}</Cell>
                <Cell>{nairaAmount(row.spent_naira)}</Cell>
                <Cell className="min-w-[10rem]">
                  {row.cap_used_percentage === null ? (
                    <span className="text-ink-faint">No cap</span>
                  ) : (
                    <div className="space-y-1">
                      <Meter
                        value={row.cap_used_percentage}
                        tone={row.at_cap ? 'red' : row.cap_used_percentage >= 80 ? 'amber' : 'green'}
                      />
                      <span className={`text-xs ${row.at_cap ? 'font-semibold text-negative' : 'text-ink-muted'}`}>
                        {row.at_cap ? 'At cap — refusing requests' : `${row.cap_used_percentage}%`}
                      </span>
                    </div>
                  )}
                </Cell>
              </tr>
            ))}
          </Table>
        </div>
      )}
    </div>
  );
}
