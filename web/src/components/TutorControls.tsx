'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, ErrorNote, Field, StatTile, inputClass } from '@/components/Page';

/**
 * Where the student tutor is on, for which subjects, how much, and how it
 * is being used (TASK 15). Usage shows counts only: no conversation content
 * ever reaches this screen.
 */

interface Settings { sections: string[] | null; subject_ids: number[] | null; daily_message_limit: number | null; exam_lock: boolean }
interface Usage { days: number; messages: number; active_students: number; redirected: number; by_section: Record<string, number>; by_subject: Record<string, number> }

const SECTIONS = ['nursery', 'primary', 'secondary'];

export default function TutorControls() {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [usage, setUsage] = useState<Usage | null>(null);
  const [subjects, setSubjects] = useState<{ id: number; name: string }[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const [s, u, subj] = await Promise.all([
          fetchApi<Settings>('/ai/tutor/settings'),
          fetchApi<Usage>('/ai/tutor/usage'),
          fetchApi<{ data?: { id: number; name: string }[] }>('/subjects').catch(() => null),
        ]);
        setSettings(s);
        setUsage(u);
        setSubjects(subj?.data ?? []);
      } catch (err) {
        setError(errorText(err, 'Could not load tutor controls.'));
      }
    })();
  }, []);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!settings) return;
    try {
      setSettings(await fetchApi<Settings>('/ai/tutor/settings', { method: 'PUT', body: JSON.stringify(settings) }));
      setNotice('Saved.');
      setError(null);
    } catch (err) {
      setError(errorText(err, 'Could not save.'));
    }
  }

  if (!settings) return error ? <ErrorNote message={error} /> : null;

  const toggle = <T,>(list: T[] | null, all: T[], item: T, on: boolean): T[] | null => {
    const current = list ?? all;
    const next = on ? [...current, item] : current.filter((x) => x !== item);
    return next.length === all.length ? null : next;
  };

  return (
    <Card title="Student tutor controls" description="The tutor helps students learn; it does not mark work or replace teachers. Counts below never include what students wrote." className="mt-6">
      <ErrorNote message={error} />
      {notice && <p className="mb-3 text-sm text-positive">{notice}</p>}
      {usage && (
        <div className="mb-5 grid gap-3 sm:grid-cols-3">
          <StatTile label={`Messages, last ${usage.days} days`} value={usage.messages} />
          <StatTile label="Students using it" value={usage.active_students} tone="blue" />
          <StatTile label="Steered back on topic" value={usage.redirected} tone="amber" hint={Object.entries(usage.by_subject).slice(0, 3).map(([k, v]) => `${k} ${v}`).join(' · ')} />
        </div>
      )}
      <form className="space-y-4" onSubmit={save}>
        <div>
          <span className="mb-1 block text-sm font-medium">On for these sections</span>
          {SECTIONS.map((s) => (
            <label key={s} className="mr-4 inline-flex items-center gap-1 text-sm capitalize">
              <input type="checkbox" checked={(settings.sections ?? SECTIONS).includes(s)} onChange={(e) => setSettings({ ...settings, sections: toggle(settings.sections, SECTIONS, s, e.target.checked) })} /> {s}
            </label>
          ))}
        </div>
        <div>
          <span className="mb-1 block text-sm font-medium">Subjects ({settings.subject_ids === null ? 'all' : `${settings.subject_ids.length} chosen`})</span>
          <div className="flex flex-wrap gap-x-4 gap-y-1">
            {subjects.map((s) => (
              <label key={s.id} className="inline-flex items-center gap-1 text-sm">
                <input type="checkbox" checked={settings.subject_ids === null || settings.subject_ids.includes(s.id)} onChange={(e) => setSettings({ ...settings, subject_ids: toggle(settings.subject_ids, subjects.map((x) => x.id), s.id, e.target.checked) })} /> {s.name}
              </label>
            ))}
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Messages per student per day (blank = no limit)"><input className={inputClass} type="number" min={1} max={500} value={settings.daily_message_limit ?? ''} onChange={(e) => setSettings({ ...settings, daily_message_limit: e.target.value ? Number(e.target.value) : null })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.exam_lock} onChange={(e) => setSettings({ ...settings, exam_lock: e.target.checked })} /> Pause the tutor while a student is sitting a CBT exam</label>
        </div>
        <Button>Save tutor controls</Button>
      </form>
    </Card>
  );
}
