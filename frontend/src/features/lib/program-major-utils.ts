import type {
  Curriculum,
  Program,
  Section,
} from "@/features/schemas/reference-data-schema"
import type { SectionPlan } from "@/features/schemas/section-plan-schema"

export interface MajorshipInfo {
  id: number
  code: string
  name: string
  prefix: string
  shortLabel: string
}

/**
 * Standard block section prefixes for GRC degree programs.
 */
export function getProgramBlockPrefix(programCode: string): string {
  const normalized = programCode.toUpperCase().replace(/[\s_-]/gu, "")

  if (normalized.includes("ELEM") || normalized === "BEED") return "ELEM"
  if (normalized.includes("SOCSCI")) return "SOCSCI"
  if (normalized.includes("FIL")) return "FIL"
  if (normalized.includes("ENG")) return "ENG"
  if (normalized.includes("VAL")) return "VAL"
  if (normalized.includes("TCP")) return "TCP"
  if (normalized.includes("FINANCE") || normalized.includes("FM")) return "FM"
  if (normalized.includes("ENTREP")) return "EN"
  if (normalized.includes("MARKETING") || normalized.includes("MM")) return "MM"
  if (
    normalized.includes("HUMANRESOURCE") ||
    normalized.includes("HRM") ||
    normalized.includes("HR")
  )
    return "HR"
  if (normalized.includes("IT") || normalized.includes("CCS") || normalized.includes("CS"))
    return "IT"
  if (normalized.includes("ACC") || normalized.includes("BSA")) return "ACC"

  return programCode
}

/**
 * Extracts the alphabetic prefix from a generated block code (e.g., "ELEM101" -> "ELEM", "IT201" -> "IT").
 */
export function extractBlockPrefix(blockCode: string): string {
  const match = /^([A-Za-z]+)/u.exec(blockCode)
  return match ? match[1].toUpperCase() : blockCode
}

/**
 * Derives a human-friendly short label for a program/major (e.g. "BEED (Elementary Education)" or "BSED - English").
 */
export function getProgramShortLabel(program: Pick<Program, "code" | "name">): string {
  if (program.code === "BEED") return "Elementary Education (BEED)"
  if (program.code === "BSED-ENG") return "English (BSED-ENG)"
  if (program.code === "BSED-FIL") return "Filipino (BSED-FIL)"
  if (program.code === "BSED-SOCSCI") return "Social Studies (BSED-SOCSCI)"
  if (program.code === "BSED-VAL") return "Values Education (BSED-VAL)"
  if (program.code === "TCP") return "Teacher Certificate (TCP)"
  if (program.code === "BSBA-FM") return "Financial Management (FM)"
  if (program.code === "BSBA-MM") return "Marketing Management (MM)"
  if (program.code === "BSBA-HRM") return "Human Resource Management (HRM)"
  if (program.code === "BSENTREP") return "Entrepreneurship (BSENTREP)"
  if (program.code === "BSIT") return "Information Technology (BSIT)"
  if (program.code === "BSA") return "Accountancy (BSA)"

  return `${program.name} (${program.code})`
}

/**
 * Resolves the program associated with a section or block code.
 */
export function findProgramForSection({
  section,
  blockCode,
  plans = [],
  curricula = [],
  programs = [],
}: {
  section?: Section | null
  blockCode?: string
  plans?: readonly SectionPlan[]
  curricula?: readonly Curriculum[]
  programs?: readonly Program[]
}): Program | null {
  // 1. Try resolving via section_plan_id
  if (section?.section_plan_id) {
    const plan = plans.find((p) => p.id === section.section_plan_id)
    if (plan?.curriculum_id) {
      const curriculum = curricula.find((c) => c.id === plan.curriculum_id)
      if (curriculum?.program_id) {
        const program = programs.find((p) => p.id === curriculum.program_id)
        if (program) return program
      }
    }
  }

  // 2. Try resolving via block code prefix matching
  const code = blockCode ?? section?.section_code ?? ""
  const prefix = extractBlockPrefix(code)

  const programByPrefix = programs.find(
    (p) => getProgramBlockPrefix(p.code) === prefix,
  )
  if (programByPrefix) return programByPrefix

  // 3. Fallback: match prefix in curriculum name
  const curriculumByPrefix = curricula.find((c) => {
    const pPrefix = getProgramBlockPrefix(c.name)
    return pPrefix === prefix || c.name.toUpperCase().includes(prefix)
  })
  if (curriculumByPrefix?.program_id) {
    return programs.find((p) => p.id === curriculumByPrefix.program_id) ?? null
  }

  return null
}

/**
 * Resolves the curriculum associated with a section or block code.
 */
export function findCurriculumForSection({
  section,
  blockCode,
  plans = [],
  curricula = [],
  programs = [],
  selectedCurriculaByYearAndProgram = new Map<string, number>(),
  yearLevel = 1,
}: {
  section?: Section | null
  blockCode?: string
  plans?: readonly SectionPlan[]
  curricula?: readonly Curriculum[]
  programs?: readonly Program[]
  selectedCurriculaByYearAndProgram?: Map<string, number>
  yearLevel?: number
}): Curriculum | null {
  // 1. Try via section_plan_id
  if (section?.section_plan_id) {
    const plan = plans.find((p) => p.id === section.section_plan_id)
    if (plan?.curriculum_id) {
      const cur = curricula.find((c) => c.id === plan.curriculum_id)
      if (cur) return cur
    }
  }

  // 2. Try via program resolution + selectedCurricula map
  const program = findProgramForSection({
    section,
    blockCode,
    plans,
    curricula,
    programs,
  })

  if (program) {
    const curId = selectedCurriculaByYearAndProgram.get(`${yearLevel}_${program.id}`)
    if (curId) {
      const cur = curricula.find((c) => c.id === curId)
      if (cur) return cur
    }

    // Try finding section plan for this program and year
    const programCurriculaIds = curricula
      .filter((c) => c.program_id === program.id)
      .map((c) => c.id)
    const plan = plans.find(
      (p) =>
        p.year_level === yearLevel &&
        programCurriculaIds.includes(p.curriculum_id) &&
        p.section_count > 0,
    )
    if (plan) {
      const cur = curricula.find((c) => c.id === plan.curriculum_id)
      if (cur) return cur
    }

    // Fallback: newest curriculum for this program
    const newestForProg = curricula
      .filter((c) => c.program_id === program.id && c.status !== "draft")
      .sort((a, b) => b.effective_school_year.localeCompare(a.effective_school_year))[0]
    if (newestForProg) return newestForProg
  }

  return null
}

