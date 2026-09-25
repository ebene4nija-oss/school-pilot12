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
  Field,
  Loading,
  PageShell,
  StatTile,
  Table,
  inputClass,
} from '@/components/Page';

/**
 * The bus fleet and where it is.
 *
 * Was two invented buses with hardcoded Lagos coordinates under an animated
 * "● Live Broadcasting" badge and a fake radar — a page that claimed to be
 * tracking vehicles that did not exist.
 *
 * Positions come from the driver's own phone (doc §3 — no purchased trackers),
 * which is why this page leads with how old each fix is. A coordinate with no
 * timestamp beside it reads as live when it may be hours stale, and a parent
 * ringing about a late bus is the wrong person to discover that.
 */

interface Bus {
  id: number;
  bus_number: string;
  driver_name: string;
  driver_phone: string;
  route_id: number | null;
  route_name: string | null;
  is_active: boolean;
  current_lat: number | null;
  current_lng: number | null;
  last_ping_at: string | null;
  position_is_fresh: boolean;
}

export default function TransportPage() {
  const [buses, setBuses] = useState<Bus[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showForm, setShowForm] = useState(false);

  const [busNumber, setBusNumber] = useState('');
  const [driverName, setDriverName] = useState('');
  const [driverPhone, setDriverPhone] = useState('');
  const [routeName, setRouteName] = useState('');

  /** Returns the fleet; the caller owns the state. */
  const fetchFleet = useCallback(
    async () => (await fetchApi<{ data: Bus[] }>('/transport/buses')).data ?? [],
    [],
  );

  const load = useCallback(async () => {
    setBuses(await fetchFleet());
  }, [fetchFleet]);

  useEffect(() => {
    let cancelled = false;

    const refresh = async () => {
      try {
        const fleet = await fetchFleet();
        if (!cancelled) setBuses(fleet);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Could not load the fleet.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void refresh();

    /*
     * Positions change while the page is open, so it refreshes itself. Every
     * 30s: a driver's phone pings far less often than that, and polling harder
     * would only cost a school on a metered connection.
     */
    const timer = setInterval(() => void refresh(), 30_000);

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [fetchFleet]);

  async function registerBus(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      await fetchApi('/transport/buses', {
        method: 'POST',
        body: JSON.stringify({
          bus_number: busNumber.trim(),
          driver_name: driverName.trim(),
          driver_phone: driverPhone.trim(),
          route_name: routeName.trim() || null,
        }),
      });

      setNotice(`${busNumber.trim()} registered.`);
      setBusNumber('');
      setDriverName('');
      setDriverPhone('');
      setRouteName('');
      setShowForm(false);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'That bus could not be registered.');
    } finally {
      setBusy(false);
    }
  }

  const live = buses.filter((b) => b.position_is_fresh).length;

  return (
    <PageShell
      title="Transport"
      subtitle="Buses, routes and the last position each driver's phone reported."
      actions={
        <Button onClick={() => setShowForm((v) => !v)} variant={showForm ? 'secondary' : 'primary'}>
          {showForm ? 'Cancel' : 'Register a bus'}
        </Button>
      }
    >
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
          <div className="grid gap-4 sm:grid-cols-3">
            <StatTile label="Buses" value={buses.length} />
            <StatTile
              label="Reporting now"
              value={live}
              tone={live > 0 ? 'green' : 'slate'}
              hint="A fix in the last 10 minutes"
            />
            <StatTile
              label="On a route"
              value={buses.filter((b) => b.is_active).length}
              hint="Marked active by the driver"
            />
          </div>

          {showForm && (
            <Card title="Register a bus">
              <form onSubmit={registerBus} className="grid gap-4 sm:grid-cols-2">
                <Field label="Bus number">
                  <input
                    required
                    value={busNumber}
                    onChange={(e) => setBusNumber(e.target.value)}
                    className={inputClass}
                    placeholder="e.g. BUS-001"
                  />
                </Field>
                <Field label="Route (optional)">
                  <input
                    value={routeName}
                    onChange={(e) => setRouteName(e.target.value)}
                    className={inputClass}
                    placeholder="e.g. Lekki Phase 1"
                  />
                </Field>
                <Field label="Driver">
                  <input
                    required
                    value={driverName}
                    onChange={(e) => setDriverName(e.target.value)}
                    className={inputClass}
                  />
                </Field>
                <Field label="Driver's phone">
                  <input
                    required
                    value={driverPhone}
                    onChange={(e) => setDriverPhone(e.target.value)}
                    className={inputClass}
                    placeholder="The phone that reports the bus's position"
                  />
                </Field>
                <div className="sm:col-span-2">
                  <Button type="submit" disabled={busy}>
                    {busy ? 'Saving…' : 'Register bus'}
                  </Button>
                </div>
              </form>
            </Card>
          )}

          <Card title="Fleet">
            {buses.length === 0 ? (
              <Empty>No buses registered yet.</Empty>
            ) : (
              <Table columns={['Bus', 'Route', 'Driver', 'Last position', 'Reported']}>
                {buses.map((bus) => (
                  <tr key={bus.id}>
                    <Cell className="font-medium text-brand">{bus.bus_number}</Cell>
                    <Cell>{bus.route_name ?? <span className="text-ink-faint">No route set</span>}</Cell>
                    <Cell>
                      {bus.driver_name}
                      <a
                        href={`tel:${bus.driver_phone}`}
                        className="block text-xs text-info hover:underline"
                      >
                        {bus.driver_phone}
                      </a>
                    </Cell>
                    <Cell className="font-mono text-xs">
                      {bus.current_lat !== null && bus.current_lng !== null ? (
                        <a
                          href={`https://www.google.com/maps?q=${bus.current_lat},${bus.current_lng}`}
                          target="_blank"
                          rel="noreferrer"
                          className="text-info hover:underline"
                        >
                          {bus.current_lat.toFixed(5)}, {bus.current_lng.toFixed(5)}
                        </a>
                      ) : (
                        <span className="text-ink-faint">Never reported</span>
                      )}
                    </Cell>
                    <Cell>
                      {bus.last_ping_at ? (
                        <>
                          {bus.position_is_fresh ? (
                            <Badge tone="green">Live</Badge>
                          ) : (
                            <Badge tone="amber">Stale</Badge>
                          )}
                          <span className="ml-2 text-xs text-ink-muted">{ago(bus.last_ping_at)}</span>
                        </>
                      ) : (
                        <Badge tone="slate">No signal</Badge>
                      )}
                    </Cell>
                  </tr>
                ))}
              </Table>
            )}
          </Card>

          <p className="text-xs text-ink-muted">
            Positions are sent by the driver&apos;s phone, so a bus out of network coverage shows its
            last known fix. &ldquo;Stale&rdquo; means nothing has been heard for over ten minutes —
            treat the position as historic, not current.
          </p>
        </div>
      )}
    </PageShell>
  );
}

/** How long ago, in the plainest words that fit a table cell. */
function ago(iso: string): string {
  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) return '';

  const seconds = Math.max(0, Math.round((Date.now() - then) / 1000));

  if (seconds < 60) return 'just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
  if (seconds < 86_400) return `${Math.floor(seconds / 3600)} hr ago`;

  return `${Math.floor(seconds / 86_400)} day(s) ago`;
}
