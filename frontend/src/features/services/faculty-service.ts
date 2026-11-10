import type {
  AcademicTerm,
  Section,
  Subject,
} from "@/features/schemas/reference-data-schema"
import {
  decideFacultySpecializationInputSchema,
  facultyAvailabilitiesEnvelopeSchema,
  facultyAvailabilityEnvelopeSchema,
  facultyAvailabilityInputSchema,
  facultySpecializationEnvelopeSchema,
  facultySpecializationInputSchema,
  facultySpecializationsEnvelopeSchema,
  facultySubjectPreferenceEnvelopeSchema,
  facultySubjectPreferenceInputSchema,
  facultySubjectPreferencesEnvelopeSchema,
  type DecideFacultySpecializationInput,
  type FacultyAvailability,
  type FacultyAvailabilityInput,
  type FacultySpecialization,
  type FacultySpecializationInput,
  type FacultySubjectPreference,
  type FacultySubjectPreferenceInput,
  facultyCurriculumSubjectPreferenceEnvelopeSchema,
  facultyCurriculumSubjectPreferenceInputSchema,
  facultyCurriculumSubjectPreferencesEnvelopeSchema,
  facultyPreferenceCatalogEnvelopeSchema,
  facultyTeachingHistoriesEnvelopeSchema,
  type FacultyCurriculumSubjectPreference,
  type FacultyCurriculumSubjectPreferenceInput,
  type FacultyPreferenceCatalogEntry,
  type FacultyTeachingHistory,
} from "@/features/schemas/faculty-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"
import { formatAcademicTerm } from "@/features/services/reference-data-service"
import { formatTimeRange } from "@/features/lib/format-time"

export const FACULTY_AVAILABILITIES_PATH = "/api/v1/faculty-availabilities"
export const FACULTY_SUBJECT_PREFERENCES_PATH =
  "/api/v1/faculty-subject-preferences"
export const FACULTY_PREFERENCE_CATALOG_PATH =
  "/api/v1/faculty-preference-catalog"
export const FACULTY_CURRICULUM_SUBJECT_PREFERENCES_PATH =
  "/api/v1/faculty-curriculum-subject-preferences"
export const FACULTY_TEACHING_HISTORY_PATH = "/api/v1/faculty-teaching-history"
export const FACULTY_SPECIALIZATIONS_PATH = "/api/v1/faculty-specializations"

function parseContract<T>(
  schema: {
    safeParse: (
      payload: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  label: string,
): T {
  const parsed = schema.safeParse(payload)

  if (parsed.success) {
    return parsed.data
  }

  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: parsed.error,
  })
}

function parseInput<T>(
  schema: {
    safeParse: (
      payload: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  input: unknown,
  label: string,
): T {
  const parsed = schema.safeParse(input)

  if (parsed.success) {
    return parsed.data
  }

  throw new ApiClientError({
    kind: "contract",
    message: `The ${label} request did not match the published v1 contract.`,
    cause: parsed.error,
  })
}

export async function getFacultyAvailabilities(
  signal?: AbortSignal,
): Promise<readonly FacultyAvailability[]> {
  const payload = await getAuthenticatedJson(
    FACULTY_AVAILABILITIES_PATH,
    signal,
  )
  return parseContract(
    facultyAvailabilitiesEnvelopeSchema,
    payload,
    "faculty availability list",
  ).data
}

export async function createFacultyAvailability(
  input: FacultyAvailabilityInput,
): Promise<FacultyAvailability> {
  const payload = await postAuthenticatedJson(
    FACULTY_AVAILABILITIES_PATH,
    parseInput(facultyAvailabilityInputSchema, input, "faculty availability"),
  )
  return parseContract(
    facultyAvailabilityEnvelopeSchema,
    payload,
    "created faculty availability",
  ).data
}

export async function updateFacultyAvailability(
  id: number,
  input: FacultyAvailabilityInput,
): Promise<FacultyAvailability> {
  const payload = await patchAuthenticatedJson(
    `${FACULTY_AVAILABILITIES_PATH}/${id}`,
    parseInput(facultyAvailabilityInputSchema, input, "faculty availability"),
  )
  return parseContract(
    facultyAvailabilityEnvelopeSchema,
    payload,
    "updated faculty availability",
  ).data
}

export async function deleteFacultyAvailability(id: number): Promise<void> {
  await deleteAuthenticatedJson(`${FACULTY_AVAILABILITIES_PATH}/${id}`)
}

export async function getFacultySubjectPreferences(
  signal?: AbortSignal,
): Promise<readonly FacultySubjectPreference[]> {
  const payload = await getAuthenticatedJson(
    FACULTY_SUBJECT_PREFERENCES_PATH,
    signal,
  )
  return parseContract(
    facultySubjectPreferencesEnvelopeSchema,
    payload,
    "faculty subject preference list",
  ).data
}

export async function createFacultySubjectPreference(
  input: FacultySubjectPreferenceInput,
): Promise<FacultySubjectPreference> {
  const payload = await postAuthenticatedJson(
    FACULTY_SUBJECT_PREFERENCES_PATH,
    parseInput(
      facultySubjectPreferenceInputSchema,
      input,
      "faculty subject preference",
    ),
  )
  return parseContract(
    facultySubjectPreferenceEnvelopeSchema,
    payload,
    "created faculty subject preference",
  ).data
}

export async function updateFacultySubjectPreference(
  id: number,
  input: FacultySubjectPreferenceInput,
): Promise<FacultySubjectPreference> {
  const payload = await patchAuthenticatedJson(
    `${FACULTY_SUBJECT_PREFERENCES_PATH}/${id}`,
    parseInput(
      facultySubjectPreferenceInputSchema,
      input,
      "faculty subject preference",
    ),
  )
  return parseContract(
    facultySubjectPreferenceEnvelopeSchema,
    payload,
    "updated faculty subject preference",
  ).data
}

export async function deleteFacultySubjectPreference(
  id: number,
): Promise<void> {
  await deleteAuthenticatedJson(`${FACULTY_SUBJECT_PREFERENCES_PATH}/${id}`)
}

export async function getFacultyPreferenceCatalog(
  signal?: AbortSignal,
): Promise<readonly FacultyPreferenceCatalogEntry[]> {
  const payload = await getAuthenticatedJson(
    FACULTY_PREFERENCE_CATALOG_PATH,
    signal,
  )
  return parseContract(
    facultyPreferenceCatalogEnvelopeSchema,
    payload,
    "faculty preference catalog",
  ).data
}

export async function getFacultyCurriculumSubjectPreferences(
  signal?: AbortSignal,
): Promise<readonly FacultyCurriculumSubjectPreference[]> {
  const payload = await getAuthenticatedJson(
    FACULTY_CURRICULUM_SUBJECT_PREFERENCES_PATH,
    signal,
  )
  return parseContract(
    facultyCurriculumSubjectPreferencesEnvelopeSchema,
    payload,
    "curriculum subject preference list",
  ).data
}

export async function createFacultyCurriculumSubjectPreference(
  input: FacultyCurriculumSubjectPreferenceInput,
): Promise<FacultyCurriculumSubjectPreference> {
  const payload = await postAuthenticatedJson(
    FACULTY_CURRICULUM_SUBJECT_PREFERENCES_PATH,
    parseInput(
      facultyCurriculumSubjectPreferenceInputSchema,
      input,
      "curriculum subject preference",
    ),
  )
  return parseContract(
    facultyCurriculumSubjectPreferenceEnvelopeSchema,
    payload,
    "created curriculum subject preference",
  ).data
}

export async function updateFacultyCurriculumSubjectPreference(
  id: number,
  input: FacultyCurriculumSubjectPreferenceInput,
): Promise<FacultyCurriculumSubjectPreference> {
  const payload = await patchAuthenticatedJson(
    `${FACULTY_CURRICULUM_SUBJECT_PREFERENCES_PATH}/${id}`,
    parseInput(
      facultyCurriculumSubjectPreferenceInputSchema,
      input,
      "curriculum subject preference",
    ),
  )
  return parseContract(
    facultyCurriculumSubjectPreferenceEnvelopeSchema,
    payload,
    "updated curriculum subject preference",
  ).data
}

export async function deleteFacultyCurriculumSubjectPreference(
  id: number,
): Promise<void> {
  await deleteAuthenticatedJson(
    `${FACULTY_CURRICULUM_SUBJECT_PREFERENCES_PATH}/${id}`,
  )
}

export async function getFacultyTeachingHistory(
  signal?: AbortSignal,
): Promise<readonly FacultyTeachingHistory[]> {
  const payload = await getAuthenticatedJson(
    FACULTY_TEACHING_HISTORY_PATH,
    signal,
  )
  return parseContract(
    facultyTeachingHistoriesEnvelopeSchema,
    payload,
    "faculty teaching history",
  ).data
}

export async function getFacultySpecializations(
  signal?: AbortSignal,
  professorId?: number,
): Promise<readonly FacultySpecialization[]> {
  const path =
    professorId === undefined
      ? FACULTY_SPECIALIZATIONS_PATH
      : `${FACULTY_SPECIALIZATIONS_PATH}?professor_id=${professorId}`
  const payload = await getAuthenticatedJson(path, signal)

  return parseContract(
    facultySpecializationsEnvelopeSchema,
    payload,
    "faculty specialization list",
  ).data
}

export async function createFacultySpecialization(
  input: FacultySpecializationInput,
): Promise<FacultySpecialization> {
  const payload = await postAuthenticatedJson(
    FACULTY_SPECIALIZATIONS_PATH,
    parseInput(
      facultySpecializationInputSchema,
      input,
      "faculty specialization",
    ),
  )

  return parseContract(
    facultySpecializationEnvelopeSchema,
    payload,
    "created faculty specialization",
  ).data
}

export async function decideFacultySpecialization(
  id: number,
  input: DecideFacultySpecializationInput,
): Promise<FacultySpecialization> {
  const payload = await patchAuthenticatedJson(
    `${FACULTY_SPECIALIZATIONS_PATH}/${id}`,
    parseInput(
      decideFacultySpecializationInputSchema,
      input,
      "faculty specialization decision",
    ),
  )

  return parseContract(
    facultySpecializationEnvelopeSchema,
    payload,
    "decided faculty specialization",
  ).data
}

/** A professor changing the proficiency of a specialization they declared. */
export async function updateFacultySpecializationProficiency(
  id: number,
  proficiency: FacultySpecializationInput["proficiency"],
): Promise<FacultySpecialization> {
  const payload = await patchAuthenticatedJson(
    `${FACULTY_SPECIALIZATIONS_PATH}/${id}/proficiency`,
    { proficiency },
  )

  return parseContract(
    facultySpecializationEnvelopeSchema,
    payload,
    "updated faculty specialization",
  ).data
}

export async function deleteFacultySpecialization(id: number): Promise<void> {
  await deleteAuthenticatedJson(`${FACULTY_SPECIALIZATIONS_PATH}/${id}`)
}

export interface TeachingScheduleRow {
  sectionId: number
  termId: number
  sectionCode: string
  subjectId: number
  subjectCode: string
  subjectTitle: string
  units?: number
  termLabel: string
  days: string
  rawDays: string | null
  time: string
  startsAtTime: string | null
  endsAtTime: string | null
  room: string
  status: Section["status"]
  statusLabel: string
  modality?: Section["modality"]
  enrolledCount: number
  capacity: number
}


/**
 * Translates only the already API-scoped section list into display rows. The
 * browser never asks for rosters or grades, and does not broaden that scope.
 */
export function getFacultyTeachingSchedule(
  sections: readonly Section[],
  subjects: readonly Subject[],
  terms: readonly AcademicTerm[],
): TeachingScheduleRow[] {
  const subjectsById = new Map(subjects.map((subject) => [subject.id, subject]))
  const termsById = new Map(terms.map((term) => [term.id, term]))

  return sections.map((section) => {
    const subject = subjectsById.get(section.subject_id)
    const term = termsById.get(section.academic_term_id)

    return {
      sectionId: section.id,
      termId: section.academic_term_id,
      sectionCode: section.section_code,
      subjectId: section.subject_id,
      subjectCode: subject?.code ?? "Subject unavailable",
      subjectTitle: subject?.title ?? "Subject details unavailable",
      units: subject?.units,
      termLabel: term ? formatAcademicTerm(term) : "Term unavailable",
      days: section.schedule_days ?? "Days pending",
      rawDays: section.schedule_days,
      time:
        section.starts_at_time && section.ends_at_time
          ? formatTimeRange(section.starts_at_time, section.ends_at_time)
          : "Time pending",
      startsAtTime: section.starts_at_time,
      endsAtTime: section.ends_at_time,
      room: section.room ?? "Room pending",
      status: section.status,
      statusLabel: section.status_label,
      modality: section.modality,
      enrolledCount: section.enrolled_count,
      capacity: section.capacity,
    }
  })
}
