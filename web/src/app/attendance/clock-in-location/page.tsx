'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { errorText, fetchApi } from '@/lib/api';
import { Button, Card, ErrorNote, Field, Loading, PageShell, inputClass } from '@/components/Page';

/**
 * Where the school is, for staff GPS clock-in.
 *
 * Until this is saved every clock-in is refused — the server fails closed
 * rather than accept a "present" it cannot verify. Best done from a phone at
 * the gate ("Use my current location"); from an office PC, paste the
 * coordinates from Google Maps, because a desktop's location comes from its
 * IP address and can be kilometres out.
 */

interface Location {
  configured: boolean;
  latitude: number | null;
  longitude: number | null;
  radius_metres: number;
  staff_resume_time: string;
}

export default function ClockInLocationPage() {
  const [loaded, setLoaded] = useState<Location | null>(null);
  const [lat, setLat] = useState('');
  const [lng, setLng] = useState('');
  const [radius, setRadius] = useState(250);
  const [resume, setResume] = useState('07:30');
  const [error, setError] = useState<string | null>(null);
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    fetchApi<Location>('/attendance/clock-in-location')
      .then((l) => {
        setLoaded(l);
        setLat(l.latitude?.toFixed(6) ?? '');
        setLng(l.longitude?.toFixed(6) ?? '');
        setRadius(l.radius_metres);
        setResume(l.staff_resume_time);
      })
      .catch((err) => setError(errorText(err, 'Could not load the clock-in location.')));
  }, []);

  function here(): Promise<GeolocationPosition> {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) return reject(new Error('This browser cannot read its location. Paste the coordinates instead.'));
      navigator.geolocation.getCurrentPosition(resolve, () => reject(new Error('Location permission was refused or no fix was found. Paste the coordinates instead.')), {
        enableHighAccuracy: true,
        timeout: 25000,
      });
    });
  }

  async function useCurrent() {
    setNote(null);
    setBusy(true);
    try {
      const pos = await here();
      setLat(pos.coords.latitude.toFixed(6));
      setLng(pos.coords.longitude.toFixed(6));
      const acc = Math.round(pos.coords.accuracy);
      setNote(
        acc > 100
          ? { ok: false, text: `This device only knows its location to within ${acc} m — likely from its internet connection, not GPS. Use a phone at the gate, or paste from Google Maps.` }
          : { ok: true, text: `Got it, accurate to about ${acc} m. Save to keep it.` },
      );
    } catch (err) {
      setNote({ ok: false, text: (err as Error).message });
    } finally {
      setBusy(false);
    }
  }

  /** "6.6018, 3.3515" pasted from Google Maps into either box. */
  function maybeSplit(value: string, set: (v: string) => void) {
    const parts = value.split(/[,\s]+/).filter(Boolean);
    if (parts.length === 2 && parts.every((p) => !Number.isNaN(Number(p)))) {
      setLat(parts[0]);
      setLng(parts[1]);
    } else {
      set(value);
    }
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setNote(null);
    setError(null);
    setBusy(true);
    try {
      const saved = await fetchApi<Location>('/attendance/clock-in-location', {
        method: 'PUT',
        body: JSON.stringify({ latitude: Number(lat), longitude: Number(lng), radius_metres: radius, staff_resume_time: resume }),
      });
      setLoaded(saved);
      setNote({ ok: true, text: `Saved. Staff can now clock in within ${saved.radius_metres} m of this point.` });
    } catch (err) {
      setError(errorText(err, 'Could not save the location.'));
    } finally {
      setBusy(false);
    }
  }

  async function test() {
    setNote(null);
    setBusy(true);
    try {
      const pos = await here();
      const r = await fetchApi<{ within: boolean; distance_metres: number; radius_metres: number }>('/attendance/clock-in-location/test', {
        method: 'POST',
        body: JSON.stringify({ latitude: pos.coords.latitude, longitude: pos.coords.longitude }),
      });
      const d = Math.round(r.distance_metres);
      setNote(r.within
        ? { ok: true, text: `A clock-in from here would be accepted — ${d} m from the saved point.` }
        : { ok: false, text: `A clock-in from here would be refused — ${d} m away, radius ${r.radius_metres} m.` });
    } catch (err) {
      setNote({ ok: false, text: errorText(err, (err as Error).message) });
    } finally {
      setBusy(false);
    }
  }

  const mapUrl = lat && lng ? `https://www.google.com/maps?q=${encodeURIComponent(`${lat},${lng}`)}` : null;

  return (
    <PageShell
      title="Clock-in location"
      subtitle="Staff clock in with their phone. A clock-in counts only when the phone is inside this circle."
      actions={<Link href="/attendance/staff" className="text-sm font-medium text-brand underline">Staff attendance →</Link>}
    >
      <ErrorNote message={error} />
      {!loaded ? (
        !error && <Loading />
      ) : (
        <form onSubmit={save} className="max-w-2xl space-y-6">
          {!loaded.configured && (
            <p className="rounded-lg border border-negative/20 bg-negative-soft px-4 py-3 text-sm text-negative">
              Not set yet — staff cannot clock in until it is.
            </p>
          )}

          <Card
            title="School location"
            description="Easiest from a phone standing at the school gate. On a computer, open Google Maps, right-click the school, click the numbers to copy them, and paste here."
          >
            <Button type="button" onClick={useCurrent} disabled={busy}>Use my current location</Button>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
              <Field label="Latitude"><input className={inputClass} inputMode="decimal" required value={lat} placeholder="6.601800" onChange={(e) => maybeSplit(e.target.value, setLat)} /></Field>
              <Field label="Longitude"><input className={inputClass} inputMode="decimal" required value={lng} placeholder="3.351500" onChange={(e) => maybeSplit(e.target.value, setLng)} /></Field>
            </div>
            {mapUrl && <a href={mapUrl} target="_blank" rel="noreferrer" className="mt-2 inline-block text-xs text-brand underline">Check this point on Google Maps</a>}
          </Card>

          <Card title="Radius" description="Big enough to cover the whole compound and allow for GPS drift indoors. 150–300 m suits most schools.">
            <div className="flex items-center gap-4">
              <input type="range" min={50} max={1000} step={25} value={radius} onChange={(e) => setRadius(Number(e.target.value))} className="flex-1" aria-label="Radius in metres" />
              <span className="w-20 text-right font-semibold">{radius} m</span>
            </div>
          </Card>

          <Card title="Staff resume by" description="A clock-in after this time is marked late.">
            <div className="w-40"><Field label="Time"><input type="time" className={inputClass} required value={resume} onChange={(e) => setResume(e.target.value)} /></Field></div>
          </Card>

          {note && <p className={`text-sm ${note.ok ? 'text-positive' : 'text-negative'}`}>{note.text}</p>}

          <div className="flex flex-wrap gap-3">
            <Button type="submit" disabled={busy}>{busy ? 'Working…' : 'Save'}</Button>
            <Button type="button" variant="secondary" onClick={test} disabled={busy || !loaded.configured}>Test from here</Button>
          </div>
        </form>
      )}
    </PageShell>
  );
}
