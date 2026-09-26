'use client';

import React, { useState } from 'react';
import { errorText, fetchApi } from '@/lib/api';

/**
 * Enrol one student, with their class and their parents, in one step.
 *
 * The form this replaces asked for a name, an email, a gender and a birth
 * certificate number. The student landed in no class — so on no register, no
 * broadsheet and no fee list — with no parent to text. Email was required,
 * though most pupils have none.
 *
 * A parent needs only a name and a phone number. A parent already on the
 * school's books (an older sibling's mother, say) is recognised by that number
 * however it is written, and the child is added to her account rather than a
 * second one being made.
 */

export type ClassOption = {
  id: number;
  name: string;
  arms?: { id: number; name: string }[];
};

type Guardian = {
  name: string;
  phone: string;
  email: string;
  relationship: string;
  occupation: string;
};

const EMPTY_GUARDIAN: Guardian = { name: '', phone: '', email: '', relationship: 'mother', occupation: '' };

const STATES = [
  'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River',
  'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano',
  'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
  'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
];

const input =
  'w-full rounded-lg border border-line-soft bg-canvas px-3 py-2 text-sm text-ink focus:border-brand focus:outline-none';

function Label({ children, optional = false }: { children: React.ReactNode; optional?: boolean }) {
  return (
    <span className="mb-1 block text-xs font-semibold text-ink-muted">
      {children}
      {optional && <span className="font-normal text-ink-faint"> (optional)</span>}
    </span>
  );
}

type Created = {
  student?: { id: number; admission_number?: string | null };
  setup_link_sent?: boolean;
};

export default function EnrolStudentForm({
  classes,
  onCreated,
}: {
  classes: ClassOption[];
  onCreated: () => void;
}) {
  const [name, setName] = useState('');
  const [gender, setGender] = useState('');
  const [dob, setDob] = useState('');
  const [classId, setClassId] = useState('');
  const [armId, setArmId] = useState('');
  const [admissionNumber, setAdmissionNumber] = useState('');
  const [stateOfOrigin, setStateOfOrigin] = useState('');
  const [lga, setLga] = useState('');
  const [religion, setReligion] = useState('');
  const [previousSchool, setPreviousSchool] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [guardians, setGuardians] = useState<Guardian[]>([{ ...EMPTY_GUARDIAN }]);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const arms = classes.find((c) => String(c.id) === classId)?.arms ?? [];

  const setGuardian = (index: number, patch: Partial<Guardian>) =>
    setGuardians((list) => list.map((g, i) => (i === index ? { ...g, ...patch } : g)));

  const reset = () => {
    setName('');
    setGender('');
    setDob('');
    setAdmissionNumber('');
    setStateOfOrigin('');
    setLga('');
    setReligion('');
    setPreviousSchool('');
    setEmail('');
    setPhone('');
    setGuardians([{ ...EMPTY_GUARDIAN }]);
    // Class and arm are kept: admissions are usually entered a class at a time.
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setDone(null);

    const optional = (v: string) => (v.trim() === '' ? undefined : v.trim());

    try {
      const res = await fetchApi<Created>('/students', {
        method: 'POST',
        body: JSON.stringify({
          name: name.trim(),
          gender,
          date_of_birth: optional(dob),
          class_id: classId ? Number(classId) : undefined,
          arm_id: armId ? Number(armId) : undefined,
          admission_number: optional(admissionNumber),
          state_of_origin: optional(stateOfOrigin),
          lga: optional(lga),
          religion: optional(religion),
          previous_school: optional(previousSchool),
          email: optional(email),
          phone: optional(phone),
          guardians: guardians.map((g) => ({
            name: g.name.trim(),
            phone: g.phone.trim(),
            email: optional(g.email),
            relationship: g.relationship,
            occupation: optional(g.occupation),
          })),
        }),
      });

      const number = res.student?.admission_number;
      setDone(
        `${name.trim()} enrolled${number ? ` as ${number}` : ''}.` +
          (res.setup_link_sent ? ' A sign-in link has been emailed.' : ''),
      );
      reset();
      onCreated();
    } catch (err) {
      setError(errorText(err, 'Could not save that student.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-6">
      <section>
        <h3 className="mb-3 text-sm font-bold text-ink">Student</h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <label className="md:col-span-2">
            <Label>Full name</Label>
            <input className={input} value={name} onChange={(e) => setName(e.target.value)} placeholder="Surname, first name, middle name" required />
          </label>
          <label>
            <Label>Gender</Label>
            <select className={input} value={gender} onChange={(e) => setGender(e.target.value)} required>
              <option value="">Choose…</option>
              <option value="female">Female</option>
              <option value="male">Male</option>
            </select>
          </label>

          <label>
            <Label>Class</Label>
            <select
              className={input}
              value={classId}
              onChange={(e) => {
                setClassId(e.target.value);
                setArmId('');
              }}
              required
            >
              <option value="">Choose a class…</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            {classes.length === 0 && (
              <span className="mt-1 block text-[11px] text-caution">No classes yet — set them up in the setup wizard first.</span>
            )}
          </label>
          <label>
            <Label optional={arms.length > 0}>Arm</Label>
            <select className={`${input} disabled:opacity-40`} value={armId} onChange={(e) => setArmId(e.target.value)} disabled={arms.length === 0}>
              <option value="">{arms.length === 0 ? 'This class has no arms' : 'No arm'}</option>
              {arms.map((a) => (
                <option key={a.id} value={a.id}>{a.name}</option>
              ))}
            </select>
          </label>
          <label>
            <Label optional>Admission number</Label>
            <input className={input} value={admissionNumber} onChange={(e) => setAdmissionNumber(e.target.value)} placeholder="Blank = next number" />
          </label>

          <label>
            <Label optional>Date of birth</Label>
            <input type="date" className={input} value={dob} onChange={(e) => setDob(e.target.value)} max={new Date().toISOString().slice(0, 10)} />
          </label>
          <label>
            <Label optional>State of origin</Label>
            <select className={input} value={stateOfOrigin} onChange={(e) => setStateOfOrigin(e.target.value)}>
              <option value="">—</option>
              {STATES.map((s) => (
                <option key={s} value={s}>{s}</option>
              ))}
            </select>
          </label>
          <label>
            <Label optional>LGA</Label>
            <input className={input} value={lga} onChange={(e) => setLga(e.target.value)} />
          </label>

          <label>
            <Label optional>Religion</Label>
            <select className={input} value={religion} onChange={(e) => setReligion(e.target.value)}>
              <option value="">—</option>
              <option value="Christianity">Christianity</option>
              <option value="Islam">Islam</option>
              <option value="Other">Other</option>
            </select>
          </label>
          <label className="md:col-span-2">
            <Label optional>Previous school</Label>
            <input className={input} value={previousSchool} onChange={(e) => setPreviousSchool(e.target.value)} />
          </label>

          <label>
            <Label optional>Student email</Label>
            <input type="email" className={input} value={email} onChange={(e) => setEmail(e.target.value)} placeholder="Only if the student has one" />
          </label>
          <label>
            <Label optional>Student phone</Label>
            <input type="tel" className={input} value={phone} onChange={(e) => setPhone(e.target.value)} />
          </label>
        </div>
      </section>

      {guardians.map((g, i) => (
        <section key={i} className="rounded-lg border border-line-soft p-4">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-bold text-ink">{i === 0 ? 'Parent or guardian' : 'Second parent or guardian'}</h3>
            {i > 0 && (
              <button type="button" onClick={() => setGuardians((list) => list.slice(0, 1))} className="text-xs text-negative hover:underline">
                Remove
              </button>
            )}
          </div>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <label>
              <Label>Name</Label>
              <input className={input} value={g.name} onChange={(e) => setGuardian(i, { name: e.target.value })} required />
            </label>
            <label>
              <Label>Phone</Label>
              <input type="tel" className={input} value={g.phone} onChange={(e) => setGuardian(i, { phone: e.target.value })} placeholder="0803 123 4567" required />
            </label>
            <label>
              <Label>Relationship</Label>
              <select className={input} value={g.relationship} onChange={(e) => setGuardian(i, { relationship: e.target.value })}>
                <option value="mother">Mother</option>
                <option value="father">Father</option>
                <option value="guardian">Guardian</option>
                <option value="other">Other</option>
              </select>
            </label>
            <label>
              <Label optional>Email</Label>
              <input type="email" className={input} value={g.email} onChange={(e) => setGuardian(i, { email: e.target.value })} />
            </label>
            <label>
              <Label optional>Occupation</Label>
              <input className={input} value={g.occupation} onChange={(e) => setGuardian(i, { occupation: e.target.value })} />
            </label>
          </div>
          {i === 0 && (
            <p className="mt-3 text-[11px] text-ink-faint">
              A parent already registered with this phone number — for a brother or sister — is linked, not duplicated.
              Without an email, the parent gets SMS but cannot sign in to the parent app until one is added.
            </p>
          )}
        </section>
      ))}

      {guardians.length < 2 && (
        <button
          type="button"
          onClick={() => setGuardians((list) => [...list, { ...EMPTY_GUARDIAN, relationship: 'father' }])}
          className="text-xs font-semibold text-brand hover:underline"
        >
          + Add a second parent or guardian
        </button>
      )}

      {error && (
        <p role="alert" className="rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">{error}</p>
      )}
      {done && (
        <p role="status" className="rounded-lg border border-positive/30 bg-positive-soft px-4 py-3 text-sm text-positive">✓ {done}</p>
      )}

      <button type="submit" disabled={busy} className="w-full rounded-lg bg-brand py-2.5 text-sm font-bold text-white transition hover:bg-brand-soft disabled:opacity-60">
        {busy ? 'Enrolling…' : 'Enrol student'}
      </button>
    </form>
  );
}
