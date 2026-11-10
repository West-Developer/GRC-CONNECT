/**
 * The PRD §3 roles plus the IT Control role, mirroring
 * `App\Domain\Identity\UserRole` in the backend.
 *
 * This is a runtime value, not just a type: `schemas/auth-schema.ts` feeds it
 * to `z.enum()` to validate the `role` field on real API responses, so an
 * unknown role from the server is rejected as a contract violation rather than
 * silently rendering an empty portal.
 */
export const userRoles = [
  "student",
  "admission_staff",
  "faculty",
  "program_chair",
  "dean",
  "executive_director",
  "registrar_head",
  "registrar_staff",
  "accounting_staff",
  "it_admin",
  "queue_kiosk",
  "super_admin",
] as const

export type UserRole = (typeof userRoles)[number]
