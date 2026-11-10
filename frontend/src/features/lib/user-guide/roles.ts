import type { GuideRole } from "./types"

/**
 * The roles the guide documents, in the order a reader meets them on the
 * contents page. Kept apart from the content so the portal shell can ask
 * "does this role have a guide chapter?" without loading the whole guide.
 */
export const guideRoles = [
  "student",
  "admission_staff",
  "faculty",
  "program_chair",
  "dean",
  "executive_director",
  "registrar_head",
  "registrar_staff",
  "accounting_staff",
] as const satisfies readonly GuideRole[]

/** Whether a signed-in role has a chapter in the guide (IT Control and Super Admin do not). */
export function isGuideRole(role: string): role is GuideRole {
  return (guideRoles as readonly string[]).includes(role)
}
