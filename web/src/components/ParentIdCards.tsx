'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { Badge, Card } from '@/components/Page';

/**
 * A child's digital ID card, where the school shares it (TASK 18 §15). It
 * shows the card's live state from the server, never a saved image, so a
 * card reported lost reads as lost here at once. Renders nothing when the
 * school has not enabled digital cards for parents.
 */

interface DigitalCard { serial: string; status: string; valid: boolean; headline: string; holder_name: string; role: string; school: string; photo: string | null; expires_on: string | null; qr: string | null }

export default function ParentIdCards() {
  const [children, setChildren] = useState<{ student_id: number; card: DigitalCard | null }[]>([]);

  useEffect(() => {
    fetchApi<{ children: { student_id: number; card: DigitalCard | null }[] }>('/parent/id-cards')
      .then((res) => setChildren(res.children.filter((c) => c.card)))
      .catch(() => undefined);
  }, []);

  if (children.length === 0) return null;

  return (
    <Card title="ID cards" className="mt-6">
      <div className="grid gap-4 sm:grid-cols-2">
        {children.map(({ student_id, card }) => card && (
          <div key={student_id} className="flex gap-4 rounded-lg border border-line-soft p-4">
            {card.photo && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={card.photo} alt="" className="h-24 w-20 rounded object-cover" />
            )}
            <div className="min-w-0 flex-1">
              <p className="text-xs text-ink-muted">{card.school}</p>
              <p className="font-semibold">{card.holder_name}</p>
              <p className="text-sm text-ink-muted">{card.role}</p>
              <p className="mt-1 font-mono text-xs">{card.serial}{card.expires_on ? ` · to ${card.expires_on}` : ''}</p>
              <p className="mt-2"><Badge tone={card.valid ? 'green' : 'red'}>{card.headline}</Badge></p>
            </div>
            {card.qr && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={card.qr} alt="Verification code" className="h-24 w-24" />
            )}
          </div>
        ))}
      </div>
    </Card>
  );
}
