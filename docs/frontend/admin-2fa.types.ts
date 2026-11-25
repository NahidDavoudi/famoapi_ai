/**
 * Frontend TypeScript contract for FAMO Multi-Channel Admin 2FA Login Flow
 * Fallback chain: SMS -> Email -> Authenticator App (TOTP)
 */

export type AdminTwoFactorChannel = 'sms' | 'email' | 'totp';
export type AdminVerifiedAuthMethod = 'sms' | 'email' | 'totp' | 'recovery';

export interface AdminLoginRequest {
  username: string;
  password: string;
}

export interface AdminTwoFactorSecondaryActions {
  /** True when active channel is sms/email and sends_remaining > 0 */
  resend_current: boolean;
  /** True when active channel is sms, email is configured/available, and sends_remaining > 0 */
  send_via_email: boolean;
  /** True whenever authenticator app (TOTP) is enrolled and in ADMIN_2FA_CHAIN */
  use_authenticator: boolean;
}

export interface AdminTwoFactorChallengeData {
  requires_2fa: true;
  two_fa_method: AdminTwoFactorChannel;
  channel: AdminTwoFactorChannel;
  /** "Code sent via SMS" | "Code sent via email" | "Enter the code from your authenticator app" */
  channel_message: string;
  /** Localized Persian channel message */
  channel_message_fa: string;
  destination_mask: string | null;
  phone_mask: string | null;
  email_mask: string | null;
  user_id: number;
  username: string;
  role: 'admin';
  challenge_token: string;
  expires_in: number;
  resend_cooldown_seconds: number;
  sends_used: number;
  max_sends: number;
  sends_remaining: number;
  available_channels: AdminTwoFactorChannel[];
  secondary_actions: AdminTwoFactorSecondaryActions;
}

export interface AdminVerifyTwoFactorRequest {
  user_id: number;
  /** 6-digit OTP/TOTP code or single-use recovery code (e.g. XXXXX-XXXXX) */
  code: string;
  challenge_token: string;
  /** Optional explicit method hint ('sms' | 'email' | 'totp' | 'recovery') */
  method?: AdminVerifiedAuthMethod;
}

export interface AdminVerifyTwoFactorSuccessData {
  token: string;
  auth_method: AdminVerifiedAuthMethod;
  remaining_recovery_codes?: number;
  user: {
    id: number;
    username: string;
    role: 'admin';
    student_id: null;
    instructor_id: null;
  };
}

export interface AdminResendTwoFactorRequest {
  user_id: number;
  challenge_token: string;
}

export interface AdminSwitchChannelRequest {
  user_id: number;
  challenge_token: string;
  channel: AdminTwoFactorChannel;
}

export interface ApiEnvelope<T> {
  success: boolean;
  data: T | null;
  pagination: null;
  error: {
    code: string;
    message: string;
    details?: Record<string, string>;
  } | null;
}
