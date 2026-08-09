'use client';

/**
 * Fees & Finance (§7.12).
 *
 * This page used to be two hardcoded `useState` arrays and a "Create Fee
 * Structure" button with no handler. It now drives the real endpoints: the
 * builder writes fee structures, Generate Invoices raises the term's bills from
 * them, and the defaulter table is the live debtor list rather than two
 * invented names.
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react';
import Sidebar from '@/components/Sidebar';
import { fetchApi, ApiError } from '@/lib/api';
import type {
  DefaulterReport,
  FeeStructure,
  FeeStructureIndex,
  InvoiceGenerationResult,
  NotificationChannel,
  ReminderResult,
} from '@/types/api';

const naira = (value: number) =>
  `₦${Number(value ?? 0).toLocaleString('en-NG', { maximumFractionDigits: 2 })}`;

const AGEING_LABELS: Record<string, string> = {
  not_yet_due: 'Not yet due',
  '1-30_days': '1–30 days',
  '31-60_days': '31–60 days',
  '61-90_days': '61–90 days',
  over_90_days: 'Over 90 days',
};

/** Blank form state: a school-wide, mandatory fee. */
const emptyForm = {
  id: null as number | null,
  class_id: '' as string,
  title: '',
  amount: '',
  is_mandatory: true,
};

export default function FeesPage() {
  const [fees, setFees] = useState<FeeStructureIndex | null>(null);
  const [debtors, setDebtors] = useState<DefaulterReport | null>(null);
  const [termId, setTermId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [form, setForm] = useState(emptyForm);
  const [showForm, setShowForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const [dueDate, setDueDate] = useState('');
  const [generateClassId, setGenerateClassId] = useState('');
  const [generating, setGenerating] = useState(false);

  // Reminder state. Channels start empty on purpose: SMS is billed per message,
  // so the bursar picks rather than inherits a default.
  const [selected, setSelected] = useState<number[]>([]);
  const [channels, setChannels] = useState<NotificationChannel[]>([]);
  const [reminderNote, setReminderNote] = useState('');
  const [reminding, setReminding] = useState(false);
  const [unreachable, setUnreachable] = useState<ReminderResult['unreachable']>([]);

  /**
   * The two reads this page needs. Kept free of state updates so the mount
   * effect below can await it before touching React state — the linter forbids
   * a synchronous setState in an effect body, and it is right to.
   *
   * The debtor list is a separate concern and a separate failure: a school with
   * no invoices raised yet should still see its fee structures.
   */
  const fetchFinance = useCallback(async (forTerm?: number | null) => {
    const query = forTerm ? `?term_id=${forTerm}` : '';

    const [structures, defaulters] = await Promise.all([
      fetchApi<FeeStructureIndex>(`/finance/fee-structure${query}`),
      fetchApi<DefaulterReport>(`/finance/defaulters${query}`).catch(() => null),
    ]);

    return { structures, defaulters };
  }, []);

  const apply = useCallback(
    (result: { structures: FeeStructureIndex; defaulters: DefaulterReport | null }) => {
      setFees(result.structures);
      setTermId(result.structures.term_id);
      setDebtors(result.defaulters);
    },
    [],
  );

  /** Reload from an event handler, where a spinner is the right feedback. */
  const load = useCallback(
    async (forTerm?: number | null) => {
      setLoading(true);
      setError(null);

      try {
        apply(await fetchFinance(forTerm));
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'Could not load the finance module.');
      } finally {
        setLoading(false);
      }
    },
    [apply, fetchFinance],
  );

  useEffect(() => {
    let active = true;

    (async () => {
      try {
        const result = await fetchFinance();
        if (active) apply(result);
      } catch (err) {
        if (active) {
          setError(err instanceof ApiError ? err.message : 'Could not load the finance module.');
        }
      } finally {
        // Guarded so a bursar who navigates away mid-request does not get a
        // state update on an unmounted page.
        if (active) setLoading(false);
      }
    })();

    return () => {
      active = false;
    };
  }, [apply, fetchFinance]);

  const currentTerm = useMemo(
    () => fees?.terms.find((t) => t.id === termId) ?? null,
    [fees, termId],
  );

  const collected = useMemo(
    () => (debtors?.defaulters ?? []).reduce((sum, row) => sum + row.amount_paid, 0),
    [debtors],
  );

  const invoiced = useMemo(
    () => (debtors?.defaulters ?? []).reduce((sum, row) => sum + row.total_amount, 0),
    [debtors],
  );

  const handleTermChange = (value: string) => {
    const next = value ? Number(value) : null;
    setTermId(next);
    load(next);
  };

  const startEdit = (fee: FeeStructure) => {
    setForm({
      id: fee.id,
      class_id: fee.class_id ? String(fee.class_id) : '',
      title: fee.title,
      amount: String(fee.amount),
      is_mandatory: fee.is_mandatory,
    });
    setFieldErrors({});
    setShowForm(true);
  };

  const startCreate = () => {
    setForm(emptyForm);
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!termId) {
      setError('Create an academic term before defining fees.');
      return;
    }

    setSaving(true);
    setFieldErrors({});
    setNotice(null);

    const body = JSON.stringify({
      term_id: termId,
      class_id: form.class_id ? Number(form.class_id) : null,
      title: form.title,
      amount: Number(form.amount),
      is_mandatory: form.is_mandatory,
    });

    try {
      if (form.id) {
        await fetchApi(`/finance/fee-structure/${form.id}`, { method: 'PUT', body });
        setNotice('Fee updated. Invoices already issued keep the amount they were raised with.');
      } else {
        await fetchApi('/finance/fee-structure', { method: 'POST', body });
        setNotice('Fee structure saved.');
      }

      setShowForm(false);
      setForm(emptyForm);
      load(termId);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const payload = err.payload as { errors?: Record<string, string[]> };
        setFieldErrors(payload?.errors ?? {});
      } else {
        setError(err instanceof ApiError ? err.message : 'Could not save the fee.');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (fee: FeeStructure) => {
    const warning =
      fee.invoiced_lines > 0
        ? `${fee.title} has already been billed on ${fee.invoiced_lines} invoice(s). Those bills keep the charge. Remove the fee so it is not billed again?`
        : `Remove ${fee.title}?`;

    if (!window.confirm(warning)) return;

    try {
      const res = await fetchApi<{ message: string; note: string | null }>(
        `/finance/fee-structure/${fee.id}`,
        { method: 'DELETE' },
      );
      setNotice(res.note ? `${res.message} ${res.note}` : res.message);
      load(termId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not remove the fee.');
    }
  };

  const handleGenerate = async () => {
    if (!termId) return;

    const scope = generateClassId
      ? fees?.classes.find((c) => String(c.id) === generateClassId)?.name
      : 'every active student';

    if (!window.confirm(`Raise ${currentTerm?.name ?? 'this term'}'s invoices for ${scope}?`)) return;

    setGenerating(true);
    setNotice(null);
    setError(null);

    try {
      const result = await fetchApi<InvoiceGenerationResult>('/finance/invoices/generate', {
        method: 'POST',
        body: JSON.stringify({
          term_id: termId,
          class_id: generateClassId ? Number(generateClassId) : null,
          due_date: dueDate || null,
        }),
      });

      const { summary } = result;
      setNotice(
        `${result.message} ${naira(summary.total_billed)} newly billed across ${summary.lines_added} line(s).`,
      );
      load(termId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not raise invoices.');
    } finally {
      setGenerating(false);
    }
  };

  const toggleChannel = (channel: NotificationChannel) =>
    setChannels((current) =>
      current.includes(channel) ? current.filter((c) => c !== channel) : [...current, channel],
    );

  const toggleRow = (invoiceId: number) =>
    setSelected((current) =>
      current.includes(invoiceId)
        ? current.filter((id) => id !== invoiceId)
        : [...current, invoiceId],
    );

  const allSelected =
    (debtors?.defaulters.length ?? 0) > 0 && selected.length === debtors?.defaulters.length;

  const toggleAll = () =>
    setSelected(allSelected ? [] : (debtors?.defaulters ?? []).map((row) => row.invoice_id));

  const handleRemind = async () => {
    if (!debtors || channels.length === 0) return;

    // No ticks means "everyone on this list" — the common case is the whole
    // sweep, and forcing 200 checkbox clicks to get there would be silly.
    const targets = selected.length > 0 ? selected : debtors.defaulters.map((r) => r.invoice_id);

    const costs = channels.filter((c) => c === 'sms');
    const warning =
      costs.length > 0
        ? `Send a reminder to ${targets.length} family/families by SMS? SMS is billed per message.`
        : `Send a reminder covering ${targets.length} invoice(s)?`;

    if (!window.confirm(warning)) return;

    setReminding(true);
    setNotice(null);
    setError(null);
    setUnreachable([]);

    try {
      const result = await fetchApi<ReminderResult>('/finance/defaulters/remind', {
        method: 'POST',
        body: JSON.stringify({
          invoice_ids: targets,
          term_id: termId,
          channels,
          note: reminderNote || null,
        }),
      });

      setNotice(result.message);
      setUnreachable(result.unreachable ?? []);
      setSelected([]);
      setReminderNote('');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not send the reminders.');
    } finally {
      setReminding(false);
    }
  };

  const structures = fees?.data ?? [];

  return (
    <div className="flex bg-slate-950 text-slate-100 min-h-screen">
      <Sidebar />
      <main className="flex-1 p-8">
        <div className="flex flex-wrap gap-4 justify-between items-center mb-8">
          <div>
            <h1 className="text-2xl font-bold text-white">Fees &amp; Finance Management</h1>
            <p className="text-slate-400 text-sm">
              Fee structures, automated invoicing, and defaulter tracking
            </p>
          </div>

          <div className="flex items-center gap-2">
            <select
              value={termId ?? ''}
              onChange={(e) => handleTermChange(e.target.value)}
              className="px-3 py-2.5 bg-slate-900 border border-slate-800 rounded-lg text-sm text-white focus:border-emerald-500 focus:outline-none"
            >
              {(fees?.terms ?? []).map((term) => (
                <option key={term.id} value={term.id}>
                  {term.name}
                  {term.is_current ? ' (current)' : ''}
                </option>
              ))}
            </select>

            <button
              onClick={startCreate}
              className="px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 font-semibold text-slate-950 rounded-lg text-sm transition"
            >
              + Create Fee Structure
            </button>
          </div>
        </div>

        {error && (
          <div className="mb-6 p-4 bg-rose-500/10 border border-rose-500/30 rounded-lg text-sm text-rose-300">
            {error}
          </div>
        )}

        {notice && (
          <div className="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-lg text-sm text-emerald-300 flex justify-between gap-4">
            <span>{notice}</span>
            <button onClick={() => setNotice(null)} className="text-emerald-500 hover:text-emerald-300">
              ✕
            </button>
          </div>
        )}

        {/* Summary tiles — derived from outstanding invoices, not invented. */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Invoiced (unsettled)</span>
            <h2 className="text-2xl font-extrabold text-white mt-1">{naira(invoiced)}</h2>
            <span className="text-xs text-slate-400 mt-2 block">
              {debtors?.summary.invoices_outstanding ?? 0} open invoice(s)
            </span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Part-paid so far</span>
            <h2 className="text-2xl font-extrabold text-emerald-400 mt-1">{naira(collected)}</h2>
            <span className="text-xs text-slate-400 mt-2 block">Against open invoices</span>
          </div>

          <div className="bg-slate-900 border border-slate-800 p-5 rounded-xl">
            <span className="text-xs font-semibold text-slate-400 uppercase">Outstanding balance</span>
            <h2 className="text-2xl font-extrabold text-rose-400 mt-1">
              {naira(debtors?.summary.total_outstanding ?? 0)}
            </h2>
            <span className="text-xs text-rose-400 mt-2 block">
              {debtors?.summary.defaulting_students ?? 0} student(s) owing
            </span>
          </div>
        </div>

        {/* Builder form */}
        {showForm && (
          <div className="mb-8 bg-slate-900 border border-slate-800 p-6 rounded-xl shadow-lg">
            <h2 className="text-lg font-bold text-white mb-4">
              {form.id ? 'Edit Fee' : 'New Fee'} — {currentTerm?.name ?? 'current term'}
            </h2>

            <form onSubmit={handleSave} className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Fee Title</label>
                <input
                  type="text"
                  value={form.title}
                  onChange={(e) => setForm({ ...form, title: e.target.value })}
                  placeholder="e.g. Tuition, Lab / ICT, Development Levy"
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                  required
                />
                {fieldErrors.title && (
                  <p className="text-xs text-rose-400 mt-1">{fieldErrors.title[0]}</p>
                )}
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Amount (₦)</label>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.amount}
                  onChange={(e) => setForm({ ...form, amount: e.target.value })}
                  placeholder="45000"
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                  required
                />
                {fieldErrors.amount && (
                  <p className="text-xs text-rose-400 mt-1">{fieldErrors.amount[0]}</p>
                )}
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-400 mb-1">Applies To</label>
                <select
                  value={form.class_id}
                  onChange={(e) => setForm({ ...form, class_id: e.target.value })}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                >
                  <option value="">Whole school</option>
                  {(fees?.classes ?? []).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} only
                    </option>
                  ))}
                </select>
              </div>

              <div className="flex items-end">
                <label className="flex items-center gap-2 text-sm text-slate-300 pb-2">
                  <input
                    type="checkbox"
                    checked={form.is_mandatory}
                    onChange={(e) => setForm({ ...form, is_mandatory: e.target.checked })}
                    className="accent-emerald-500 w-4 h-4"
                  />
                  Compulsory
                  <span className="text-xs text-slate-500">
                    (optional fees are never auto-billed)
                  </span>
                </label>
              </div>

              <div className="md:col-span-2 flex gap-3">
                <button
                  type="submit"
                  disabled={saving}
                  className="flex-1 py-2 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-50 text-slate-950 font-bold rounded-lg text-sm transition"
                >
                  {saving ? 'Saving…' : form.id ? 'Update Fee' : 'Save Fee'}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setShowForm(false);
                    setForm(emptyForm);
                  }}
                  className="px-6 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold rounded-lg text-sm transition"
                >
                  Cancel
                </button>
              </div>
            </form>
          </div>
        )}

        {/* Fee structure definitions */}
        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg mb-8">
          <div className="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 className="font-semibold text-white">Fee Structure Definitions</h3>
            <span className="text-xs bg-slate-800 text-slate-300 px-2.5 py-1 rounded-full">
              {structures.length} defined
            </span>
          </div>

          {loading ? (
            <div className="p-8 text-center text-sm text-slate-400">Loading fee structures…</div>
          ) : structures.length === 0 ? (
            <div className="p-8 text-center text-sm text-slate-400">
              No fees defined for this term yet. Create one to start billing.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm text-slate-300">
                <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
                  <tr>
                    <th className="p-3.5">Fee</th>
                    <th className="p-3.5">Applies To</th>
                    <th className="p-3.5">Amount (₦)</th>
                    <th className="p-3.5">Type</th>
                    <th className="p-3.5">Billed On</th>
                    <th className="p-3.5 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800">
                  {structures.map((fee) => (
                    <tr key={fee.id} className="hover:bg-slate-800/50 transition">
                      <td className="p-3.5 font-medium text-white">{fee.title}</td>
                      <td className="p-3.5">
                        {fee.scope === 'school_wide' ? (
                          <span className="text-slate-400">Whole school</span>
                        ) : (
                          fee.class
                        )}
                      </td>
                      <td className="p-3.5 font-bold text-emerald-400">
                        {fee.amount.toLocaleString()}
                      </td>
                      <td className="p-3.5">
                        <span
                          className={`px-2 py-0.5 text-xs font-medium rounded-md border ${
                            fee.is_mandatory
                              ? 'bg-slate-800 text-slate-300 border-slate-700'
                              : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                          }`}
                        >
                          {fee.is_mandatory ? 'Compulsory' : 'Optional'}
                        </span>
                      </td>
                      <td className="p-3.5 text-xs text-slate-400">
                        {fee.invoiced_lines > 0 ? `${fee.invoiced_lines} invoice(s)` : '—'}
                      </td>
                      <td className="p-3.5 text-right space-x-2 whitespace-nowrap">
                        <button
                          onClick={() => startEdit(fee)}
                          className="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-md text-xs font-medium transition"
                        >
                          Edit
                        </button>
                        <button
                          onClick={() => handleDelete(fee)}
                          className="px-3 py-1 bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 rounded-md text-xs font-medium transition"
                        >
                          Remove
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* What each class actually pays, and the button that bills it */}
        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg mb-8">
          <div className="p-4 border-b border-slate-800">
            <h3 className="font-semibold text-white">Raise Invoices</h3>
            <p className="text-xs text-slate-400 mt-1">
              Bills every active student for {currentTerm?.name ?? 'the selected term'}. Safe to run
              more than once — a student already billed for a fee is never charged twice.
            </p>
          </div>

          <div className="p-4 grid grid-cols-1 md:grid-cols-4 gap-4 items-end border-b border-slate-800">
            <div>
              <label className="block text-xs font-semibold text-slate-400 mb-1">Scope</label>
              <select
                value={generateClassId}
                onChange={(e) => setGenerateClassId(e.target.value)}
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
              >
                <option value="">All classes</option>
                {(fees?.classes ?? []).map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-400 mb-1">
                Payment Deadline
              </label>
              <input
                type="date"
                value={dueDate}
                onChange={(e) => setDueDate(e.target.value)}
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
              />
            </div>

            <div className="md:col-span-2">
              <button
                onClick={handleGenerate}
                disabled={generating || structures.length === 0}
                className="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40 disabled:cursor-not-allowed text-slate-950 font-bold rounded-lg text-sm transition"
              >
                {generating ? 'Raising invoices…' : 'Generate Invoices for Term'}
              </button>
            </div>
          </div>

          <table className="w-full text-left text-sm text-slate-300">
            <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
              <tr>
                <th className="p-3.5">Class Level</th>
                <th className="p-3.5">Compulsory Fees</th>
                <th className="p-3.5 text-right">Total Payable (₦)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800">
              {(fees?.by_class ?? []).map((row) => (
                <tr key={row.class_id} className="hover:bg-slate-800/50 transition">
                  <td className="p-3.5 font-medium text-white">{row.class}</td>
                  <td className="p-3.5 text-xs text-slate-400">
                    {row.lines.length === 0
                      ? 'No fees defined'
                      : row.lines
                          .map((l) => `${l.title} ${naira(l.amount)}`)
                          .join('  ·  ')}
                  </td>
                  <td className="p-3.5 text-right font-bold text-emerald-400">
                    {row.total_payable.toLocaleString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Defaulters */}
        <div className="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-lg">
          <div className="p-4 border-b border-slate-800 flex justify-between items-center">
            <h3 className="font-semibold text-rose-400">Fee Defaulters List</h3>
            {debtors && (
              <span className="text-xs bg-rose-500/10 text-rose-400 border border-rose-500/20 px-2.5 py-1 rounded-full">
                {naira(debtors.summary.total_outstanding)} outstanding
              </span>
            )}
          </div>

          {loading ? (
            <div className="p-8 text-center text-sm text-slate-400">Loading debtor list…</div>
          ) : !debtors || debtors.defaulters.length === 0 ? (
            <div className="p-8 text-center text-sm text-slate-400">
              Nobody is owing. Either every bill is settled, or invoices have not been raised yet.
            </div>
          ) : (
            <>
              {/* Reminder controls */}
              <div className="p-4 border-b border-slate-800 flex flex-wrap gap-4 items-end">
                <div>
                  <span className="block text-xs font-semibold text-slate-400 mb-1.5">
                    Send by
                  </span>
                  <div className="flex gap-2">
                    {(['push', 'whatsapp', 'sms'] as NotificationChannel[]).map((channel) => (
                      <button
                        key={channel}
                        type="button"
                        onClick={() => toggleChannel(channel)}
                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold capitalize transition border ${
                          channels.includes(channel)
                            ? 'bg-emerald-500 text-slate-950 border-emerald-500'
                            : 'bg-slate-950 text-slate-300 border-slate-800 hover:bg-slate-800'
                        }`}
                      >
                        {channel}
                        {channel === 'sms' && (
                          <span className="ml-1 font-normal opacity-70">(billed)</span>
                        )}
                      </button>
                    ))}
                  </div>
                </div>

                <div className="flex-1 min-w-[220px]">
                  <label className="block text-xs font-semibold text-slate-400 mb-1.5">
                    Add a note (optional)
                  </label>
                  <input
                    type="text"
                    value={reminderNote}
                    onChange={(e) => setReminderNote(e.target.value)}
                    maxLength={300}
                    placeholder="e.g. Kindly see the bursar before Friday."
                    className="w-full px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-white text-sm focus:border-emerald-500 focus:outline-none"
                  />
                </div>

                <button
                  onClick={handleRemind}
                  disabled={reminding || channels.length === 0}
                  className="px-4 py-2 bg-rose-500 hover:bg-rose-600 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold rounded-lg text-sm transition"
                  title={channels.length === 0 ? 'Choose at least one channel' : undefined}
                >
                  {reminding
                    ? 'Queueing…'
                    : `Send Reminder${selected.length > 0 ? ` (${selected.length})` : ' to All'}`}
                </button>
              </div>

              {unreachable.length > 0 && (
                <div className="p-4 bg-amber-500/10 border-b border-amber-500/30 text-xs text-amber-300">
                  <strong className="block mb-1">
                    {unreachable.length} family/families could not be messaged — chase these by
                    phone:
                  </strong>
                  {unreachable.map((row) => (
                    <span key={row.student_id} className="block">
                      {row.student_name} ({row.admission_number}) — {naira(row.balance)} · {row.reason}
                    </span>
                  ))}
                </div>
              )}

              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm text-slate-300">
                  <thead className="bg-slate-950 text-slate-400 text-xs font-semibold uppercase">
                    <tr>
                      <th className="p-3.5 w-10">
                        <input
                          type="checkbox"
                          checked={allSelected}
                          onChange={toggleAll}
                          aria-label="Select all defaulters"
                          className="accent-emerald-500 w-4 h-4"
                        />
                      </th>
                      <th className="p-3.5">Student Name</th>
                      <th className="p-3.5">Class</th>
                      <th className="p-3.5">Invoice</th>
                      <th className="p-3.5">Outstanding</th>
                      <th className="p-3.5">Ageing</th>
                      <th className="p-3.5">Contact</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800">
                    {debtors.defaulters.map((row) => (
                      <tr key={row.invoice_id} className="hover:bg-slate-800/50 transition">
                        <td className="p-3.5">
                          <input
                            type="checkbox"
                            checked={selected.includes(row.invoice_id)}
                            onChange={() => toggleRow(row.invoice_id)}
                            aria-label={`Select ${row.student_name ?? 'student'}`}
                            className="accent-emerald-500 w-4 h-4"
                          />
                        </td>
                        <td className="p-3.5 font-medium text-white">
                          {row.student_name}
                          <span className="block text-xs text-slate-500 font-mono">
                            {row.admission_number}
                          </span>
                        </td>
                        <td className="p-3.5">{row.class ?? '—'}</td>
                        <td className="p-3.5 font-mono text-xs text-slate-400">
                          {row.invoice_number}
                        </td>
                        <td className="p-3.5 font-bold text-rose-400">{naira(row.balance)}</td>
                        <td className="p-3.5">
                          <span
                            className={`px-2 py-0.5 text-xs font-medium rounded-md border ${
                              row.days_overdue > 60
                                ? 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                                : row.days_overdue > 0
                                  ? 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                                  : 'bg-slate-800 text-slate-400 border-slate-700'
                            }`}
                          >
                            {AGEING_LABELS[row.ageing_bucket] ?? row.ageing_bucket}
                          </span>
                        </td>
                        <td className="p-3.5 text-xs">
                          {row.contacts.length === 0 ? (
                            <span className="text-slate-500">No contact on file</span>
                          ) : (
                            row.contacts.map((contact, index) => (
                              <span key={index} className="block mb-1 last:mb-0">
                                <span className="text-slate-300">{contact.name}</span>
                                {contact.relationship === 'student' && (
                                  <span className="text-slate-500"> (student)</span>
                                )}
                                {contact.phone ? (
                                  // Tappable: the bursar's next action after
                                  // reading this row is usually to dial it.
                                  <a
                                    href={`tel:${contact.phone}`}
                                    className="block font-mono text-emerald-400 hover:text-emerald-300"
                                  >
                                    {contact.phone}
                                  </a>
                                ) : (
                                  <span className="block text-amber-400">No phone number</span>
                                )}
                              </span>
                            ))
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      </main>
    </div>
  );
}
