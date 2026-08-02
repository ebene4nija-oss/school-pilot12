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

export interface ApiResponse<T> {
  status?: string;
  message?: string;
  data?: T;
  errors?: Record<string, string[]>;
}
