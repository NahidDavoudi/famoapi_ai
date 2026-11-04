/**
 * TypeScript Definitions for Weekly Appointment SMS Reminders & Related Endpoints
 * Base Path: /api/v1
 *
 * Covers:
 * 1. Standard API Response Envelope & Error Codes
 * 2. GET  /api/v1/appointments/sms/preview
 * 3. POST /api/v1/appointments/sms/send
 * 4. GET  /api/v1/appointments/sms/log
 * 5. GET/POST/PUT/DELETE /api/v1/appointments (CRUD & Status Change)
 * 6. GET/POST/PUT/DELETE /api/v1/students/{studentId}/parent-contacts
 *
 * NOTE ON PHP vs DEV-SERVER COMPATIBILITY:
 * Some field names differ slightly between the PHP production backend (`app/Modules/Appointments`)
 * and the local Node test/dev server (`dev-server.mjs`). Both variants are typed below so the
 * React SPA can safely normalize responses in both environments. See Section 8 of
 * `docs/frontend/appointments-sms.md` for the full discrepancy table.
 */

// ============================================================================
// 1. Common Envelope, Pagination & Error Types
// ============================================================================

export type UserRole = 'admin' | 'supporter' | 'student' | 'teacher';

export type ApiErrorCode =
  | 'UNAUTHORIZED'
  | 'AUTH_ERROR'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'VALIDATION_ERROR'
  | 'CONCURRENT_SEND_LOCKED'
  | 'RATE_LIMITED'
  | 'INTERNAL_ERROR'
  | (string & {});

export interface ApiErrorDetail {
  code: ApiErrorCode;
  message: string;
  details?: Record<string, string>;
}

export interface ApiPagination {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

export interface ApiSuccessEnvelope<TData, TPagination = ApiPagination | null> {
  success: true;
  data: TData;
  pagination: TPagination;
  error: null;
}

export interface ApiErrorEnvelope {
  success: false;
  data: null;
  pagination: null;
  error: ApiErrorDetail;
  /** Returned at top-level on HTTP 429 RATE_LIMITED responses */
  retry_after?: number;
}

export type ApiResponse<TData, TPagination = ApiPagination | null> =
  | ApiSuccessEnvelope<TData, TPagination>
  | ApiErrorEnvelope;

// ============================================================================
// 2. Domain Status & Mode Unions
// ============================================================================

/**
 * Per-student SMS status in `GET /api/v1/appointments/sms/preview`
 * Evaluation priority:
 * 1. no_appointment -> 2. no_phone -> 3. already_sent / changed_since_sent -> 4. failed -> 5. will_send
 */
export type AppointmentSmsPreviewStatus =
  | 'will_send'
  | 'already_sent'
  | 'changed_since_sent'
  | 'failed'
  | 'no_phone'
  | 'no_appointment';

/**
 * Dispatch mode in `POST /api/v1/appointments/sms/send`
 * - `unsent`: targets `will_send` (students with no log or `skipped_no_phone`)
 * - `failed`: targets `failed` (retries failed SMS dispatches for the week)
 * - `changed`: targets `changed_since_sent` (sends updated time when `appointment_at` changed after `sent`)
 */
export type AppointmentSmsSendMode = 'unsent' | 'failed' | 'changed';

/**
 * Stored status in `appointment_sms_log` table and `GET /api/v1/appointments/sms/log`
 */
export type AppointmentSmsLogStatus = 'sent' | 'failed' | 'skipped_no_phone';

/**
 * Per-student result status returned by `POST /api/v1/appointments/sms/send`
 */
export type AppointmentSmsSendItemStatus =
  | 'sent'
  | 'failed'
  | 'skipped_no_phone'
  | 'already_sent'
  | 'changed_since_sent'
  | 'no_appointment'
  | 'skipped';

/**
 * Action code returned in `dev-server.mjs` `details[i].action`
 */
export type AppointmentSmsSendAction =
  | 'sent'
  | 'failed'
  | 'skipped_no_phone'
  | 'skipped_already_sent'
  | 'skipped_no_appointment'
  | 'skipped_mode_mismatch'
  | 'skipped_changed_requires_changed_mode'
  | 'skipped_failed_requires_failed_mode';

/**
 * All appointment statuses accepted by `PUT /api/v1/appointments/{id}/status`
 */
export type AppointmentStatus =
  | 'pending'
  | 'confirmed'
  | 'scheduled'
  | 'completed'
  | 'cancelled'
  | 'canceled'
  | 'absent'
  | 'done';

/**
 * Subset of `AppointmentStatus` treated as "active" for weekly SMS reminders
 */
export type ActiveAppointmentStatus = 'pending' | 'confirmed' | 'scheduled';

/**
 * Parent/guardian relationship values in `parent_contacts`
 */
export type ParentRelationship = 'father' | 'mother' | 'guardian' | 'other';

// ============================================================================
// 3. GET /api/v1/appointments/sms/preview
// ============================================================================

export interface GetAppointmentSmsPreviewQuery {
  /** Any Gregorian date inside the target Saturday–Friday week (`YYYY-MM-DD`) */
  week_date: string;
}

export interface AppointmentSmsPreviewSummary {
  /** Total students evaluated (PHP backend & OpenAPI key) */
  total?: number;
  /** Total students evaluated (`dev-server.mjs` key) */
  total_students?: number;
  will_send: number;
  already_sent: number;
  changed_since_sent: number;
  failed: number;
  no_phone: number;
  no_appointment: number;
  multiple_appointments: number;
}

export interface AppointmentSmsPreviewItem {
  student_id: number;
  student_name: string;
  grade: number | null;
  field: string | null;
  status: AppointmentSmsPreviewStatus;
  recipient_type: 'parent';
  parent_name: string | null;

  /** Masked primary parent phone in PHP backend (e.g. `"0912***22"`) */
  mobile_mask?: string | null;
  /** Normalized primary parent phone in `dev-server.mjs` (e.g. `"09120001122"`) */
  mobile?: string | null;
  /** Present in `dev-server.mjs` (`true` when `mobile !== null`) */
  has_valid_phone?: boolean;

  appointment_id: number | null;
  /** Gregorian date (`YYYY-MM-DD`) of nearest active appointment in the week */
  appointment_date: string | null;

  /** Jalali date (`YYYY/MM/DD`) in PHP backend (e.g. `"1405/08/02"`) */
  appointment_jalali?: string | null;
  /** Persian weekday name in PHP backend (e.g. `"شنبه"`) */
  weekday?: string | null;
  /** Combined Persian weekday + Jalali date in `dev-server.mjs` (e.g. `"شنبه 1405/08/02"`) */
  day_and_date_fa?: string | null;

  /** `HH:MM` in PHP backend, `HH:MM:SS` in `dev-server.mjs` */
  start_time: string | null;
  /** `HH:MM` in PHP backend, `HH:MM:SS` in `dev-server.mjs` */
  end_time: string | null;
  /** `HH:MM` short time in `dev-server.mjs` */
  time_fa?: string | null;

  appointment_status: ActiveAppointmentStatus | string | null;
  /** Canonical snapshot compared against SMS log: `"YYYY-MM-DD HH:MM:SS"` */
  appointment_at: string | null;
  /** `true` when the student has > 1 active appointment in `[week_date, week_end]` */
  multiple_appointments: boolean;
  /** Count of active appointments for the student in this week */
  active_appointments_count: number;

  /** UTC timestamp (`YYYY-MM-DD HH:MM:SS`) of last successful SMS send */
  last_sent_at: string | null;
  /** Snapshot `appointment_at` from last SMS log (PHP backend key) */
  last_appointment_at?: string | null;
  /** Snapshot `appointment_at` from last SMS log (`dev-server.mjs` key) */
  last_sent_appointment_at?: string | null;

  attempts: number;
  last_error: string | null;
}

export interface AppointmentSmsPreviewData {
  /** Saturday starting the week (`YYYY-MM-DD`) */
  week_date: string;
  /** Friday ending the week (`YYYY-MM-DD`) */
  week_end: string;
  /** Present at top-level in `dev-server.mjs` */
  recipient_type?: 'parent';
  summary: AppointmentSmsPreviewSummary;
  items: AppointmentSmsPreviewItem[];
  /** Alias of `items` returned by PHP backend */
  students?: AppointmentSmsPreviewItem[];
}

export type GetAppointmentSmsPreviewResponse = ApiResponse<AppointmentSmsPreviewData, null>;

// ============================================================================
// 4. POST /api/v1/appointments/sms/send
// ============================================================================

export interface PostAppointmentSmsSendRequest {
  /** Target week date (`YYYY-MM-DD`), normalized on backend to Saturday via `IranDay::weekStart` */
  week_date: string;
  /** Dispatch filter mode (defaults to `'unsent'` in controller, required in OpenAPI) */
  mode: AppointmentSmsSendMode;
  /**
   * Optional list of student IDs to process.
   * Omit or pass `null` to process all eligible students.
   * WARNING: Do NOT pass an empty array `[]` — PHP returns `422 VALIDATION_ERROR`.
   */
  student_ids?: number[] | null;
  /** Optional client idempotency / double-click token (locked for 60s in Redis) */
  operation_id?: string | null;
}

export interface AppointmentSmsSendSummary {
  sent: number;
  failed: number;
  skipped: number;
  skipped_no_phone: number;
  /** Present in PHP backend `summary` */
  already_sent?: number;
  /** Present in PHP backend `summary` */
  no_appointment?: number;
}

export interface AppointmentSmsSendResultItem {
  student_id: number;
  student_name: string;
  /** Present in PHP backend (`"parent"`) */
  recipient_type?: 'parent';
  /** Masked phone in PHP backend (`"0912***22"`) */
  mobile_mask?: string | null;
  /** Full normalized phone in `dev-server.mjs` (`"09120001122"`) */
  mobile?: string | null;
  appointment_at: string | null;
  multiple_appointments: boolean;
  /** Present in `dev-server.mjs` */
  action?: AppointmentSmsSendAction;
  status: AppointmentSmsSendItemStatus;
  error: string | null;
}

export interface AppointmentSmsSendData {
  week_date: string;
  week_end: string;
  mode: AppointmentSmsSendMode;
  /** Present at top-level in `dev-server.mjs` */
  recipient_type?: 'parent';
  /** Top-level counters in PHP backend */
  sent?: number;
  failed?: number;
  skipped?: number;
  skipped_no_phone?: number;
  summary: AppointmentSmsSendSummary;
  /** Per-student array in PHP backend & OpenAPI */
  items?: AppointmentSmsSendResultItem[];
  /** Per-student array in `dev-server.mjs` */
  details?: AppointmentSmsSendResultItem[];
}

export type PostAppointmentSmsSendResponse = ApiResponse<AppointmentSmsSendData, null>;

// ============================================================================
// 5. GET /api/v1/appointments/sms/log
// ============================================================================

export interface GetAppointmentSmsLogQuery {
  /** Optional filter by week (`YYYY-MM-DD`, normalized to Saturday via `IranDay::weekStart`) */
  week_date?: string;
  /** Optional filter by student ID (`>= 1`) */
  student_id?: number;
  /** Optional filter by log status */
  status?: AppointmentSmsLogStatus;
  /** Page number (default `1`) */
  page?: number;
  /** Items per page (default `50` in PHP, `20` in OpenAPI/dev-server, max `100`) */
  per_page?: number;
}

export interface AppointmentSmsLogItem {
  id: number;
  student_id: number;
  student_name: string;
  /** Present in PHP backend */
  grade?: number | null;
  /** Present in PHP backend */
  field?: string | null;
  /** Saturday of the week (`YYYY-MM-DD`) */
  week_date: string;
  /** Masked phone in PHP backend (`"0912***22"` or `null` when empty) */
  mobile_mask?: string | null;
  /** Raw phone in `dev-server.mjs` (`"09120001122"` or `""`) */
  mobile?: string;
  recipient_type: 'parent' | 'student';
  appointment_at: string | null;
  status: AppointmentSmsLogStatus;
  error: string | null;
  attempts: number;
  sent_at: string | null;
  created_by: number | null;
  /** Admin username in PHP backend */
  created_by_username?: string | null;
  /** Admin full name in `dev-server.mjs` */
  created_by_name?: string | null;
  created_at: string;
  updated_at: string;
}

export interface AppointmentSmsLogData {
  /** Array key in PHP backend & OpenAPI */
  items?: AppointmentSmsLogItem[];
  /** Array key in `dev-server.mjs` */
  logs?: AppointmentSmsLogItem[];
}

export type GetAppointmentSmsLogResponse = ApiResponse<AppointmentSmsLogData, ApiPagination>;

// ============================================================================
// 6. Existing Appointments CRUD Endpoints (/api/v1/appointments)
// ============================================================================

export interface GetAppointmentsListQuery {
  page?: number;
  /**
   * IMPORTANT: PHP `AppointmentController::list` reads camelCase `perPage` (default 20, max 100),
   * whereas `openapi.yaml` documents `per_page`. Pass both or `perPage` for safety.
   */
  perPage?: number;
  per_page?: number;
}

export interface AppointmentRecord {
  id: number;
  student_id: number;
  /** Gregorian date (`YYYY-MM-DD`) */
  appointment_date: string;
  /** `HH:MM:SS` or `HH:MM` */
  start_time: string | null;
  /** `HH:MM:SS` or `HH:MM` */
  end_time: string | null;
  status: AppointmentStatus;
  type: string | null;
  description: string | null;
  created_at: string;
  updated_at?: string;
  /** Joined student fields returned by PHP `Appointment::findAll` and `Appointment::findById` */
  student_name?: string;
  grade?: number | null;
  field?: string | null;
  phone?: string | null;
}

export interface UpsertAppointmentRequest {
  student_id: number;
  /** Gregorian date (`YYYY-MM-DD`). Note: Upserts on `(student_id, appointment_date)`! */
  appointment_date: string;
  /** `HH:MM` or `HH:MM:SS` */
  start_time: string;
  /** `HH:MM` or `HH:MM:SS` */
  end_time: string;
  /** Max 100 chars */
  type?: string;
  /** Max 1000 chars */
  description?: string;
}

export interface UpdateAppointmentStatusRequest {
  status: AppointmentStatus;
}

export type GetAppointmentsListResponse = ApiResponse<AppointmentRecord[], ApiPagination>;
export type PostAppointmentResponse = ApiResponse<AppointmentRecord, null>;
export type PutAppointmentStatusResponse = ApiResponse<AppointmentRecord, null>;
export type DeleteAppointmentResponse = ApiResponse<null, null>;

// ============================================================================
// 7. Parent Contacts Endpoints (/api/v1/students/{studentId}/parent-contacts)
// ============================================================================

export interface ParentContactRecord {
  id: number;
  student_id: number;
  parent_name: string;
  relationship: ParentRelationship;
  /** Normalized Iranian mobile (`09XXXXXXXXX`) */
  phone: string;
  /**
   * `SMALLINT` (`0 | 1`) in PostgreSQL PDO responses, documented as `boolean` in OpenAPI.
   * Check with `Boolean(contact.is_primary)`.
   */
  is_primary: boolean | 0 | 1;
  created_at: string;
  updated_at: string;
}

export interface CreateParentContactRequest {
  parent_name: string;
  relationship: ParentRelationship;
  /** Accepts Persian/Arabic digits and `+98`/`0098`/`98`/`09`; normalized to `09XXXXXXXXX` */
  phone: string;
  /** If omitted and student has 0 contacts, backend automatically sets `is_primary = true` */
  is_primary?: boolean | 0 | 1;
}

export interface UpdateParentContactRequest {
  parent_name?: string;
  relationship?: ParentRelationship;
  phone?: string;
  is_primary?: boolean | 0 | 1;
}

export type GetParentContactsResponse = ApiResponse<ParentContactRecord[], null>;
export type PostParentContactResponse = ApiResponse<ParentContactRecord, null>;
export type PutParentContactResponse = ApiResponse<ParentContactRecord, null>;
export type DeleteParentContactResponse = ApiResponse<null, null>;
