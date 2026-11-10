import { z } from "zod"

/**
 * The single source of truth for how strong a NEWLY set password must be —
 * account setup and the forgot-password reset flow both use this, mirroring
 * the backend's own single source of truth (`App\Support\Auth\PasswordPolicy`).
 * This is a client-side hint only; the backend re-validates and is
 * authoritative. Deliberately forward-only: existing accounts are never
 * checked against this — see the auth-hardening plan's "Known transitional
 * gap" note.
 */
export const strongPasswordSchema = z
  .string()
  .min(8, "Use at least 8 characters.")
  .regex(/[a-z]/, "Include a lowercase letter.")
  .regex(/[A-Z]/, "Include an uppercase letter.")
  .regex(/[0-9]/, "Include a number.")
  .regex(/[^A-Za-z0-9]/, "Include a symbol.")
