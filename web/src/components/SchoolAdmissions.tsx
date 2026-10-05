import { Globe, Mail, MessageCircle, Phone } from 'lucide-react';
import type { PublicAdmissions } from '@/lib/school-profile';

/**
 * The admissions section of a school's public page.
 *
 * Shows only the ways the school chose to be reached (School Settings →
 * Admissions): any mix of the online form, WhatsApp, email and phone. A
 * school that takes admissions by WhatsApp alone shows one WhatsApp button
 * and nothing else. When admissions are closed, a short note says so.
 */
export function SchoolAdmissions({
  admissions,
  schoolName,
  accent,
}: {
  admissions: PublicAdmissions;
  schoolName: string;
  accent: string;
}) {
  const session = admissions.session ? ` for ${admissions.session}` : '';

  if (admissions.status === 'closed') {
    return (
      <section id="admissions" className="scroll-mt-20 border-t border-line-soft">
        <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
          <h2 className="text-xl font-semibold" style={{ color: accent }}>
            Admissions are closed{session}
          </h2>
          <p className="mt-2 max-w-3xl whitespace-pre-line text-ink-muted">
            {admissions.closed_message || `${schoolName} is not taking new admissions at the moment.`}
          </p>
        </div>
      </section>
    );
  }

  const { online, whatsapp, email, phone } = admissions.methods;
  const primary = 'inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold text-white';
  const secondary = 'inline-flex items-center gap-2 rounded-lg border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-sunken';

  // The first method the school offers gets the school's colour; the rest are quieter.
  const order = [online && 'online', whatsapp && 'whatsapp', email && 'email', phone && 'phone'].filter(Boolean);
  const style = (method: string) => (order[0] === method ? primary : secondary);
  const fill = (method: string) => (order[0] === method ? { backgroundColor: accent } : undefined);

  return (
    <section id="admissions" className="scroll-mt-20 border-t border-line-soft">
      <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <h2 className="text-xl font-semibold" style={{ color: accent }}>
          Admissions are open{session}
        </h2>
        <p className="mt-2 max-w-3xl whitespace-pre-line text-ink-muted">
          {admissions.instructions || `${schoolName} is accepting new pupils. ${order.length > 1 ? 'Apply or ask about admission using any of the options below.' : ''}`}
        </p>
        <div className="mt-5 flex flex-wrap gap-3">
          {online && (
            <a href="/apply" className={style('online')} style={fill('online')}>
              <Globe className="h-4 w-4" aria-hidden="true" />
              Apply online
            </a>
          )}
          {whatsapp && (
            <a
              href={`https://wa.me/${whatsapp.number}${whatsapp.message ? `?text=${encodeURIComponent(whatsapp.message)}` : ''}`}
              target="_blank"
              rel="noopener noreferrer"
              className={style('whatsapp')}
              style={fill('whatsapp')}
            >
              <MessageCircle className="h-4 w-4" aria-hidden="true" />
              WhatsApp us
            </a>
          )}
          {email && (
            <a
              href={`mailto:${email.address}${email.subject ? `?subject=${encodeURIComponent(email.subject)}` : ''}`}
              className={style('email')}
              style={fill('email')}
            >
              <Mail className="h-4 w-4" aria-hidden="true" />
              Email admissions
            </a>
          )}
          {phone && (
            <a href={`tel:${phone}`} className={style('phone')} style={fill('phone')}>
              <Phone className="h-4 w-4" aria-hidden="true" />
              Call admissions
            </a>
          )}
        </div>
      </div>
    </section>
  );
}
