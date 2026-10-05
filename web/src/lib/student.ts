import { fetchApi } from '@/lib/api';

/**
 * The signed-in student's own record id.
 *
 * Several shared endpoints take a student id in the path (the result checker,
 * the fee statement, the portfolio) and authorise it server-side against the
 * signed-in user. One request per page load, however many components ask.
 */
let pending: Promise<number> | null = null;

export function myStudentId(): Promise<number> {
  pending ??= fetchApi<{ student: { id: number } }>('/me/profile')
    .then((res) => res.student.id)
    .catch((err) => {
      pending = null;
      throw err;
    });

  return pending;
}

export const when = (iso: string) =>
  new Date(iso).toLocaleString('en-NG', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export const day = (iso: string) =>
  new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' });

/** A learning material: a link opens its target, a file downloads through the proxy. */
export async function openMaterial(m: { id: number; type: string }) {
  if (m.type === 'link') {
    const res = await fetchApi<{ url: string }>(`/learning-materials/${m.id}/download`);
    window.open(res.url, '_blank', 'noopener');
  } else {
    window.open(`/api/backend/learning-materials/${m.id}/download`, '_blank');
  }
}

export const fileSize = (b: number | null) => (b ? (b > 1_048_576 ? `${(b / 1_048_576).toFixed(1)} MB` : `${Math.ceil(b / 1024)} KB`) : '');
