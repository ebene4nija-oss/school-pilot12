'use client';

import React from 'react';
import { Button, nairaAmount } from '@/components/Page';

/**
 * A printable payment receipt, built from the statement the API returns —
 * the receipt endpoint returns metadata only, not a document. Prints alone:
 * everything else on the page carries `print:hidden`, and this overlay
 * becomes the page.
 */
export interface ReceiptData {
  school: string;
  student: { name: string | null; admission_number?: string | null; class?: string | null };
  invoiceNumber: string;
  term?: string | null;
  receiptNumber: string;
  amount: number;
  method: string | null;
  reference: string | null;
  paidAt: string | null;
  balance?: number;
}

export default function Receipt({ data, onClose }: { data: ReceiptData; onClose: () => void }) {
  const date = data.paidAt ? new Date(data.paidAt).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

  return (
    <div className="fixed inset-0 z-20 flex items-start justify-center overflow-y-auto bg-black/40 p-6 print:static print:block print:bg-white print:p-0">
      <div className="w-full max-w-md rounded-xl bg-white p-6 text-black shadow-xl print:max-w-none print:shadow-none">
        <p className="text-lg font-bold">{data.school}</p>
        <p className="text-sm">Payment receipt</p>
        <hr className="my-3" />
        <dl className="grid grid-cols-2 gap-y-1 text-sm">
          <dt>Receipt no.</dt><dd className="font-mono">{data.receiptNumber}</dd>
          <dt>Date</dt><dd>{date}</dd>
          <dt>Student</dt><dd>{data.student.name}</dd>
          {data.student.admission_number && <><dt>Admission no.</dt><dd>{data.student.admission_number}</dd></>}
          {data.student.class && <><dt>Class</dt><dd>{data.student.class}</dd></>}
          <dt>Invoice</dt><dd className="font-mono">{data.invoiceNumber}{data.term ? ` · ${data.term}` : ''}</dd>
          <dt>Paid by</dt><dd className="capitalize">{(data.method ?? '—').replace('_', ' ')}</dd>
          <dt>Reference</dt><dd className="break-all font-mono text-xs">{data.reference ?? '—'}</dd>
        </dl>
        <hr className="my-3" />
        <p className="flex justify-between text-base font-bold"><span>Amount received</span><span>{nairaAmount(data.amount)}</span></p>
        {data.balance !== undefined && <p className="mt-1 flex justify-between text-sm"><span>Balance on this invoice</span><span>{nairaAmount(data.balance)}</span></p>}
        <div className="mt-5 flex gap-2 print:hidden">
          <Button onClick={() => window.print()}>Print</Button>
          <Button variant="secondary" onClick={onClose}>Close</Button>
        </div>
      </div>
    </div>
  );
}
