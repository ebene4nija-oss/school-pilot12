'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Card } from '@/components/Page';

/**
 * Each child who rides the school bus: which bus, the pickup point, the
 * driver to call, and whether the bus is running. Nothing for a child who
 * doesn't ride, or a school without transport.
 */

interface Ride {
  rides: boolean;
  pickup_point?: string | null;
  bus?: { bus_number: string; route_name: string | null; driver_name: string; driver_phone: string; in_service: boolean } | null;
}

function ChildBus({ studentId, name }: { studentId: number; name: string | null }) {
  const [ride, setRide] = useState<Ride | null>(null);

  useEffect(() => {
    fetchApi<Ride>(`/parent/children/${studentId}/transport`).then(setRide).catch(() => setRide(null));
  }, [studentId]);

  if (!ride?.rides) return null;

  return (
    <Card className="mt-6" title={`${name ?? 'Child'}'s school bus`}>
      {ride.bus ? (
        <div className="flex flex-wrap items-start justify-between gap-3 text-sm">
          <div>
            <p className="font-semibold text-ink">{ride.bus.bus_number}{ride.bus.route_name ? ` · ${ride.bus.route_name}` : ''}</p>
            {ride.pickup_point && <p className="text-ink-muted">Pickup: {ride.pickup_point}</p>}
            <p className="mt-1">Driver: {ride.bus.driver_name} · <a className="font-semibold text-brand underline" href={`tel:${ride.bus.driver_phone}`}>{ride.bus.driver_phone}</a></p>
          </div>
          <Badge tone={ride.bus.in_service ? 'green' : 'red'}>{ride.bus.in_service ? 'Running' : 'Off the road'}</Badge>
        </div>
      ) : (
        <p className="text-sm text-ink-muted">On the bus list; the school hasn&apos;t assigned a bus yet.</p>
      )}
    </Card>
  );
}

export default function ParentTransport({ kids }: { kids: { id: number; name: string | null }[] }) {
  return <>{kids.map((c) => <ChildBus key={c.id} studentId={c.id} name={c.name} />)}</>;
}
