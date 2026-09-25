'use client';

import React, { useCallback, useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import {
  Button,
  Card,
  Cell,
  Empty,
  ErrorNote,
  Field,
  Loading,
  PageShell,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * The school clinic log.
 *
 * Was a static list of two invented children and their invented ailments. The
 * write endpoint existed; nothing could read the log back, so whatever a nurse
 * recorded was never visible again.
 *
 * Deliberately narrow (doc §12): who was seen, what was done, by whom, when. It
 * does not show — and the endpoint does not return — the stored medical record,
 * blood group or allergy list. Those are held for an emergency, not for a
 * browsable list.
 */

interface Visit {
  id: number;
  student_id: number;
  student_name: string;
  admission_number: string | null;
  symptoms: string;
  treatment: string;
  attending_nurse: string;
  visited_at: string | null;
}

/** As `/students` returns one: the user relation is already flattened. */
interface StudentOption {
  id: number;
  admission_number: string | null;
  name?: string | null;
}

export default function ClinicLogPage() {
  const [visits, setVisits] = useState<Visit[]>([]);
  const [students, setStudents] = useState<StudentOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [showForm, setShowForm] = useState(false);

  const [studentId, setStudentId] = useState('');
  const [symptoms, setSymptoms] = useState('');
  const [treatment, setTreatment] = useState('');
  const [nurse, setNurse] = useState('');

  const load = useCallback(async () => {
    const res = await fetchApi<{ data: Visit[] }>('/health/clinic-visits');
    setVisits(res.data ?? []);
  }, []);

  useEffect(() => {
    (async () => {
      try {
        await load();
        const roll = await fetchApi<{ data?: StudentOption[] }>('/students').catch(() => null);
        setStudents(roll?.data ?? []);
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Could not load the clinic log.');
      } finally {
        setLoading(false);
      }
    })();
  }, [load]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/health/clinic-visit', {
        method: 'POST',
        body: JSON.stringify({
          student_id: Number(studentId),
          symptoms: symptoms.trim(),
          treatment: treatment.trim(),
          attending_nurse: nurse.trim(),
        }),
      });

      setNotice('Visit recorded.');
      setSymptoms('');
      setTreatment('');
      setStudentId('');
      setShowForm(false);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That visit could not be recorded.');
    } finally {
      setSaving(false);
    }
  }

  const nameOf = (s: StudentOption) => s.name ?? `Student #${s.id}`;

  return (
    <PageShell
      title="Clinic log"
      subtitle="Visits to the school clinic: who was seen, and what was done."
      actions={
        <Button onClick={() => setShowForm((v) => !v)} variant={showForm ? 'secondary' : 'primary'}>
          {showForm ? 'Cancel' : 'Log a visit'}
        </Button>
      }
    >
      <ErrorNote message={error} />

      {notice && (
        <p role="status" className="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {notice}
        </p>
      )}

      {showForm && (
        <Card title="Log a clinic visit" className="mb-6">
          <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
            <Field label="Student">
              <select
                required
                value={studentId}
                onChange={(e) => setStudentId(e.target.value)}
                className={inputClass}
              >
                <option value="">Select a student…</option>
                {students.map((s) => (
                  <option key={s.id} value={s.id}>
                    {nameOf(s)}
                    {s.admission_number ? ` (${s.admission_number})` : ''}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Attending nurse">
              <input
                required
                value={nurse}
                onChange={(e) => setNurse(e.target.value)}
                className={inputClass}
                placeholder="Name of the nurse on duty"
              />
            </Field>

            <Field label="Reported symptoms">
              <textarea
                required
                rows={3}
                value={symptoms}
                onChange={(e) => setSymptoms(e.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Treatment given">
              <textarea
                required
                rows={3}
                value={treatment}
                onChange={(e) => setTreatment(e.target.value)}
                className={inputClass}
              />
            </Field>

            <div className="sm:col-span-2">
              <Button type="submit" disabled={saving || !studentId}>
                {saving ? 'Saving…' : 'Record visit'}
              </Button>
            </div>
          </form>
        </Card>
      )}

      <Card title="Recent visits">
        {loading ? (
          <Loading />
        ) : visits.length === 0 ? (
          <Empty>No clinic visits recorded yet.</Empty>
        ) : (
          <Table columns={['Student', 'Symptoms', 'Treatment', 'Nurse', 'When']}>
            {visits.map((visit) => (
              <tr key={visit.id}>
                <Cell className="font-medium text-slate-900">
                  {visit.student_name}
                  {visit.admission_number && (
                    <span className="block text-xs text-slate-500">{visit.admission_number}</span>
                  )}
                </Cell>
                <Cell>{visit.symptoms}</Cell>
                <Cell>{visit.treatment}</Cell>
                <Cell>{visit.attending_nurse}</Cell>
                <Cell className="whitespace-nowrap text-xs">{when(visit.visited_at)}</Cell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <p className="mt-4 text-xs text-slate-500">
        This log holds only what happened at the visit. A student&apos;s stored medical details are
        on their own record and are not listed here.
      </p>
    </PageShell>
  );
}

function when(iso: string | null): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return date.toLocaleString('en-NG', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}
