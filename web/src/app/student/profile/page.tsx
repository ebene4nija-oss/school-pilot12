'use client';

import React, { useEffect, useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';
import { Badge, Button, Card, Empty, ErrorNote, Field, Loading, PageShell, StatTile, inputClass } from '@/components/Page';
import { day } from '@/lib/student';

/**
 * My profile (TASK 03, the student's own view).
 *
 * The school's record of the student, read-only: identity, class, guardians
 * by name, class history, this term's attendance, achievements and the
 * digital ID card. Health details are not shown here — they sit behind the
 * school's audited medical endpoint (NDPA, doc §12). The one thing a student
 * may change is their own contact number.
 */

interface Profile {
  student: { id: number; name: string; email: string | null; admission_number: string | null; class: string | null; arm: string | null; gender: string | null; date_of_birth: string | null; status: string | null; state_of_origin: string | null; lga: string | null; previous_school: string | null };
  account: { phone: string | null };
  guardians: { name: string | null; relationship: string | null }[];
  class_history: { date: string | null; action: string; from: string | null; to: string | null; session: string | null }[];
  term: { name: string } | null;
  attendance: { present: number; late: number; absent: number; excused: number; marked_days: number };
}
interface DigitalCard { serial: string; status: string; valid: boolean; holder_name: string; role: string; school: string; photo: string | null; expires_on: string | null; qr: string | null }
interface Achievement { id: number; achievement_type: string; title: string; description: string | null; date_achieved: string | null; verified_at: string | null }

const cap = (s: string | null) => (s ? s.charAt(0).toUpperCase() + s.slice(1).replaceAll('_', ' ') : '—');

export default function StudentProfile() {
  const [p, setP] = useState<Profile | null>(null);
  const [card, setCard] = useState<DigitalCard | null>(null);
  const [portfolio, setPortfolio] = useState<Achievement[]>([]);
  const [phone, setPhone] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchApi<Profile>('/me/profile')
      .then((res) => {
        setP(res);
        setPhone(res.account.phone ?? '');
        fetchApi<{ data: Achievement[] }>(`/students/${res.student.id}/portfolio`).then((r) => setPortfolio(r.data ?? [])).catch(() => undefined);
      })
      .catch((err) => setError(errorText(err, 'Could not load your profile.')));
    // 404 when the school does not issue digital cards: the section is simply left out.
    fetchApi<{ card: DigitalCard | null }>('/me/id-card').then((r) => setCard(r.card)).catch(() => undefined);
  }, []);

  async function savePhone(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const res = await fetchApi<{ message: string; data: { phone: string | null } }>('/me/profile', { method: 'POST', body: JSON.stringify({ phone: phone.trim() || null }) });
      setPhone(res.data.phone ?? '');
      setNotice(res.message);
    } catch (err) {
      setError(errorText(err, 'Could not save your number.'));
    } finally {
      setBusy(false);
    }
  }

  if (!p) {
    return <PageShell title="My profile"><ErrorNote message={error} />{!error && <Loading />}</PageShell>;
  }

  const s = p.student;
  const rate = p.attendance.marked_days ? Math.round(((p.attendance.present + p.attendance.late) / p.attendance.marked_days) * 1000) / 10 : null;
  const rows: [string, React.ReactNode][] = [
    ['Admission number', s.admission_number ?? '—'],
    ['Class', [s.class, s.arm].filter(Boolean).join(' ') || '—'],
    ['Status', cap(s.status)],
    ['Gender', cap(s.gender)],
    ['Date of birth', s.date_of_birth ? day(s.date_of_birth) : '—'],
    ['State of origin', [s.state_of_origin, s.lga].filter(Boolean).join(', ') || '—'],
    ['Previous school', s.previous_school ?? '—'],
    ['Sign-in email', s.email ?? '—'],
  ];

  return (
    <PageShell title={s.name} subtitle={[s.class, s.arm].filter(Boolean).join(' ') || undefined}>
      <ErrorNote message={error} />
      {notice && <p role="status" className="mb-6 rounded-lg bg-positive-soft px-4 py-3 text-sm text-positive">{notice}</p>}

      <div className="grid gap-5 lg:grid-cols-[1fr_20rem]">
        <div className="space-y-5">
          <Card title="My details" description="Kept by the school office. If something is wrong, ask them to correct it.">
            <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              {rows.map(([k, v]) => (
                <div key={k}><dt className="text-xs uppercase tracking-wide text-ink-faint">{k}</dt><dd className="mt-0.5 font-medium">{v}</dd></div>
              ))}
            </dl>
          </Card>

          <div className="grid gap-4 sm:grid-cols-4">
            <StatTile label={`Attendance${p.term ? ` · ${p.term.name}` : ''}`} value={rate !== null ? `${rate}%` : '—'} tone="green" hint={`${p.attendance.marked_days} days marked`} />
            <StatTile label="Late" value={p.attendance.late} tone={p.attendance.late ? 'amber' : 'slate'} />
            <StatTile label="Absent" value={p.attendance.absent} tone={p.attendance.absent ? 'red' : 'slate'} />
            <StatTile label="Excused" value={p.attendance.excused} tone="blue" />
          </div>

          <Card title="Class history">
            {p.class_history.length === 0 ? <Empty>No moves recorded yet.</Empty> : (
              <ol className="space-y-2 text-sm">
                {p.class_history.map((h, i) => (
                  <li key={i} className="flex flex-wrap gap-x-2">
                    <span className="w-28 shrink-0 text-ink-muted">{h.date ? day(h.date) : ''}</span>
                    <span className="font-medium">{cap(h.action)}</span>
                    <span>{[h.from, h.to].filter(Boolean).join(' → ')}</span>
                    {h.session && <span className="text-ink-muted">· {h.session}</span>}
                  </li>
                ))}
              </ol>
            )}
          </Card>

          <Card title="Achievements" description="Recorded by your teachers.">
            {portfolio.length === 0 ? <Empty>No achievements recorded yet.</Empty> : (
              <ul className="space-y-3 text-sm">
                {portfolio.map((a) => (
                  <li key={a.id}>
                    <span className="font-medium">{a.title}</span> <Badge tone="blue">{a.achievement_type}</Badge> {a.verified_at && <Badge tone="green">verified</Badge>}
                    {a.date_achieved && <span className="ml-2 text-xs text-ink-muted">{day(a.date_achieved)}</span>}
                    {a.description && <p className="text-ink-muted">{a.description}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="space-y-5">
          {card && (
            <Card title="My ID card">
              <div className="flex gap-4">
                {card.photo && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={card.photo} alt="" className="h-24 w-20 rounded object-cover" />
                )}
                <div className="min-w-0 flex-1 text-sm">
                  <p className="text-xs text-ink-muted">{card.school}</p>
                  <p className="font-semibold">{card.holder_name}</p>
                  <p className="font-mono text-xs">{card.serial}</p>
                  {card.expires_on && <p className="text-xs text-ink-muted">Valid to {card.expires_on}</p>}
                  <Badge tone={card.valid ? 'green' : 'red'}>{card.status}</Badge>
                </div>
              </div>
              {card.qr && (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={card.qr} alt="Card verification code" className="mx-auto mt-4 h-32 w-32" />
              )}
            </Card>
          )}

          <Card title="Parents and guardians">
            {p.guardians.length === 0 ? <p className="text-sm text-ink-muted">None linked yet.</p> : (
              <ul className="space-y-1 text-sm">{p.guardians.map((g, i) => <li key={i}><span className="font-medium">{g.name}</span>{g.relationship && <span className="text-ink-muted"> · {g.relationship}</span>}</li>)}</ul>
            )}
          </Card>

          <Card title="My phone number" description="Optional. Used by the school to reach you.">
            <form onSubmit={savePhone} className="space-y-2">
              <Field label="Phone"><input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" placeholder="080…" /></Field>
              <Button type="submit" variant="secondary" disabled={busy}>Save</Button>
            </form>
          </Card>
        </div>
      </div>
    </PageShell>
  );
}
