export interface User {
  id: number;
  name: string;
  email: string;
  role: 'super_admin' | 'school_admin' | 'teacher' | 'student' | 'parent';
  school_id?: number | null;
}

export interface Student {
  id: number;
  school_id: number;
  user_id: number;
  class_id?: number | null;
  arm_id?: number | null;
  admission_number?: string;
  gender: 'male' | 'female' | 'other';
  user?: User;
}

export interface ScoreEntry {
  id: number;
  school_id: number;
  term_id: number;
  student_id: number;
  subject_id: number;
  first_ca: number;
  second_ca: number;
  exam: number;
  total_score: number;
  grade: string;
  teacher_comment?: string | null;
  ai_comment_status: 'none' | 'pending_approval' | 'approved' | 'rejected';
}

export interface FeeStructure {
  id: number;
  term_id: number;
  term: string | null;
  class_id: number | null;
  class: string | null;
  scope: 'school_wide' | 'class';
  title: string;
  amount: number;
  is_mandatory: boolean;
  /** How many issued invoice lines were raised from this fee. */
  invoiced_lines: number;
}

/** What a class actually costs: its own fees plus the school-wide ones. */
export interface ClassFeeTotal {
  class_id: number;
  class: string;
  lines: { title: string; amount: number }[];
  total_payable: number;
}

export interface FeeStructureIndex {
  term_id: number | null;
  currency: string;
  data: FeeStructure[];
  by_class: ClassFeeTotal[];
  terms: { id: number; name: string; is_current: boolean }[];
  classes: { id: number; name: string }[];
}

export interface InvoiceGenerationResult {
  message: string;
  currency: string;
  summary: {
    invoices_created: number;
    invoices_updated: number;
    students_unchanged: number;
    lines_added: number;
    total_billed: number;
  };
}

export interface Defaulter {
  invoice_id: number;
  invoice_number: string;
  student_id: number;
  student_name: string | null;
  admission_number: string | null;
  class: string | null;
  term: string | null;
  total_amount: number;
  amount_paid: number;
  balance: number;
  due_date: string | null;
  days_overdue: number;
  ageing_bucket: string;
  /** Who to ring about this bill, and on what number. */
  contacts: {
    name: string;
    phone: string | null;
    relationship: 'guardian' | 'student';
  }[];
  contactable: boolean;
}

export type NotificationChannel = 'push' | 'sms' | 'whatsapp';

export interface ReminderResult {
  message: string;
  reminders_queued: number;
  batch_id: string | null;
  unreachable: {
    student_id: number;
    student_name: string | null;
    admission_number: string | null;
    balance: number;
    reason: string;
  }[];
}

export interface DefaulterReport {
  summary: {
    defaulting_students: number;
    invoices_outstanding: number;
    total_outstanding: number;
    currency: string;
    by_ageing: Record<string, { invoices: number; amount: number }>;
  };
  defaulters: Defaulter[];
}

export interface ApiResponse<T> {
  status?: string;
  message?: string;
  data?: T;
  errors?: Record<string, string[]>;
}
