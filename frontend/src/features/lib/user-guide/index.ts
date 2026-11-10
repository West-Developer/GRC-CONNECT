import { rolePortalDefinitions } from "@/features/portal/role-capabilities"

import { admissionChapter } from "./content/admission"
import { accountingChapter } from "./content/accounting"
import { deanChapter } from "./content/dean"
import { executiveChapter } from "./content/executive"
import { facultyChapter } from "./content/faculty"
import { programChairChapter } from "./content/program-chair"
import { registrarHeadChapter } from "./content/registrar-head"
import { registrarStaffChapter } from "./content/registrar-staff"
import { startChapter } from "./content/start"
import { guideRoles, isGuideRole } from "./roles"
import { studentChapter } from "./content/student"
import type { GuideChapter, GuideChapterId, GuideRole } from "./types"

export type { GuideChapter, GuideChapterId, GuideRole } from "./types"
export { guideRoles, isGuideRole }

export const guideStartChapter: GuideChapter = startChapter

export const guideRoleChapters: Readonly<Record<GuideRole, GuideChapter>> = {
  student: studentChapter,
  admission_staff: admissionChapter,
  faculty: facultyChapter,
  program_chair: programChairChapter,
  dean: deanChapter,
  executive_director: executiveChapter,
  registrar_head: registrarHeadChapter,
  registrar_staff: registrarStaffChapter,
  accounting_staff: accountingChapter,
}

export const guideAllChapters: readonly GuideChapter[] = [
  guideStartChapter,
  ...guideRoles.map((role) => guideRoleChapters[role]),
]

/** What the `?role=` address parameter can hold. */
export type GuideSelection = GuideRole | "all" | null

export function parseGuideSelection(value: string | null): GuideSelection {
  if (value === "all") {
    return "all"
  }

  return value !== null && isGuideRole(value) ? value : null
}

/** The chapters on screen for a selection: everyone's start, then the role. */
export function chaptersForSelection(
  selection: GuideSelection,
): readonly GuideChapter[] {
  if (selection === "all") {
    return guideAllChapters
  }
  if (selection === null) {
    return [guideStartChapter]
  }

  return [guideStartChapter, guideRoleChapters[selection]]
}

export function guideRoleLabel(chapterId: GuideChapterId): string {
  return chapterId === "start"
    ? "Getting started"
    : rolePortalDefinitions[chapterId].roleLabel
}

/** Where the matching PDF is served from `public/downloads/`. */
export function guidePdfPath(selection: GuideSelection): string {
  return selection === null || selection === "all"
    ? "/downloads/grc-enrollment-system-user-manual-and-guide.pdf"
    : `/downloads/grc-enrollment-system-user-manual-and-guide-${selection.replace(/_/g, "-")}.pdf`
}
