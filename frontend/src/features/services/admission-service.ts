import {
  accountSetupEnvelopeSchema,
  accountSetupSchema,
  decideProfileChangeRequestSchema,
  nextStudentNumberEnvelopeSchema,
  paginatedProfileChangeRequestsSchema,
  paginatedStudentProfilesSchema,
  profileChangeRequestEnvelopeSchema,
  profileChangeRequestFiltersSchema,
  provisionStudentSchema,
  resendStudentAccountSetupEnvelopeSchema,
  resendStudentAccountSetupSchema,
  storeProfileChangeRequestSchema,
  studentProfileEnvelopeSchema,
  studentProfileFiltersSchema,
  updateStudentProfileSchema,
  type AccountSetupInput,
  type DecideProfileChangeRequestInput,
  type Paginated,
  type ProfileChangeRequest,
  type ProfileChangeRequestFilters,
  type ProvisionStudentInput,
  type ResendStudentAccountSetupInput,
  type ResendStudentAccountSetupResponse,
  type StoreProfileChangeRequestInput,
  type StudentProfile,
  type StudentProfileFilters,
  type UpdateStudentProfileInput,
} from "@/features/schemas/admission-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
  postJson,
} from "@/features/services/api-client"

export const STUDENT_PROFILES_PATH = "/api/v1/student-profiles"
export const OWN_STUDENT_PROFILE_PATH = "/api/v1/student-profile"
export const PROFILE_CHANGE_REQUESTS_PATH =
  "/api/v1/student-profile-change-requests"
export const ACCOUNT_SETUP_PATH = "/api/v1/auth/account-setup"
export const RESEND_STUDENT_ACCOUNT_SETUP_PATH =
  "/api/v1/auth/resend-student-account-setup"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  value: unknown,
  label: string,
): T {
  const result = schema.safeParse(value)
  if (result.success) return result.data

  throw new ApiClientError({
    kind: "contract",
    message: `The ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

function queryString(filters: Record<string, unknown>): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (
      (typeof value === "string" ||
        typeof value === "number" ||
        typeof value === "boolean") &&
      value !== ""
    ) {
      query.set(key, String(value))
    }
  }
  return query.toString()
}

/**
 * Admission saves a new student. `privacyNoticeVersion` is the version of the Data Privacy Notice Admission
 * showed the applicant before saving their details (ADR 0055): the server refuses the request without it and
 * keeps it against the new account.
 */
export async function provisionStudent(
  input: ProvisionStudentInput,
  privacyNoticeVersion: string,
): Promise<StudentProfile> {
  const parsed = parse(provisionStudentSchema, input, "provisioning request")
  const envelope = parse(
    studentProfileEnvelopeSchema,
    await postAuthenticatedJson(STUDENT_PROFILES_PATH, {
      ...parsed,
      privacy_notice_acknowledged: true,
      privacy_notice_version: privacyNoticeVersion,
    }),
    "created student profile",
  )
  return envelope.data
}

/**
 * The number the next new account would get. A preview: nothing is reserved, so it can change
 * before the account is saved. Null when every number for the year is in use.
 */
export async function getNextStudentNumber(
  signal?: AbortSignal,
): Promise<string | null> {
  const envelope = parse(
    nextStudentNumberEnvelopeSchema,
    await getAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}/next-student-number`,
      signal,
    ),
    "next student number",
  )
  return envelope.data.student_number
}

export async function listStudentProfiles(
  filters: StudentProfileFilters,
  signal?: AbortSignal,
): Promise<Paginated<StudentProfile>> {
  const parsed = parse(studentProfileFiltersSchema, filters, "student filter")
  return parse(
    paginatedStudentProfilesSchema,
    await getAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}?${queryString(parsed)}`,
      signal,
    ),
    "student directory",
  )
}

export async function getStudentProfile(
  id?: number,
  signal?: AbortSignal,
): Promise<StudentProfile> {
  const path = id ? `${STUDENT_PROFILES_PATH}/${id}` : OWN_STUDENT_PROFILE_PATH
  const envelope = parse(
    studentProfileEnvelopeSchema,
    await getAuthenticatedJson(path, signal),
    "student profile",
  )
  return envelope.data
}

/**
 * The Registrar Head's read of one student while reviewing an enrollment. A
 * separate path from `getStudentProfile`, whose API is Admission Staff's.
 */
export async function getRegistrarStudentProfile(
  id: number,
  signal?: AbortSignal,
): Promise<StudentProfile> {
  const envelope = parse(
    studentProfileEnvelopeSchema,
    await getAuthenticatedJson(
      `/api/v1/students/${id}/registrar-profile`,
      signal,
    ),
    "student profile",
  )
  return envelope.data
}

export async function updateStudentProfile(
  id: number,
  input: UpdateStudentProfileInput,
): Promise<StudentProfile> {
  const parsed = parse(updateStudentProfileSchema, input, "profile update")
  const envelope = parse(
    studentProfileEnvelopeSchema,
    await patchAuthenticatedJson(`${STUDENT_PROFILES_PATH}/${id}`, parsed),
    "updated student profile",
  )
  return envelope.data
}

export async function resendAccountSetupInvitation(
  id: number,
): Promise<StudentProfile> {
  const envelope = parse(
    studentProfileEnvelopeSchema,
    await postAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}/${id}/account-setup-invitations`,
    ),
    "account invitation status",
  )
  return envelope.data
}

/**
 * Sends a freshman's "view the live queue" link again. The old link stops
 * working: the new email carries a new one.
 */
export async function resendQueueLink(id: number): Promise<StudentProfile> {
  const envelope = parse(
    studentProfileEnvelopeSchema,
    await postAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}/${id}/queue-link-resends`,
    ),
    "queue link status",
  )
  return envelope.data
}

export async function listProfileChangeRequests(
  filters: ProfileChangeRequestFilters,
  signal?: AbortSignal,
): Promise<Paginated<ProfileChangeRequest>> {
  const parsed = parse(
    profileChangeRequestFiltersSchema,
    filters,
    "profile change-request filter",
  )
  return parse(
    paginatedProfileChangeRequestsSchema,
    await getAuthenticatedJson(
      `${PROFILE_CHANGE_REQUESTS_PATH}?${queryString(parsed)}`,
      signal,
    ),
    "profile change-request list",
  )
}

export async function createProfileChangeRequest(
  input: StoreProfileChangeRequestInput,
): Promise<ProfileChangeRequest> {
  const parsed = parse(
    storeProfileChangeRequestSchema,
    input,
    "profile change request",
  )
  const envelope = parse(
    profileChangeRequestEnvelopeSchema,
    await postAuthenticatedJson(PROFILE_CHANGE_REQUESTS_PATH, parsed),
    "created profile change request",
  )
  return envelope.data
}

export async function reviseProfileChangeRequest(
  id: number,
  input: StoreProfileChangeRequestInput,
): Promise<ProfileChangeRequest> {
  const parsed = parse(
    storeProfileChangeRequestSchema,
    input,
    "profile change revision",
  )
  const envelope = parse(
    profileChangeRequestEnvelopeSchema,
    await patchAuthenticatedJson(
      `${PROFILE_CHANGE_REQUESTS_PATH}/${id}`,
      parsed,
    ),
    "revised profile change request",
  )
  return envelope.data
}

export async function cancelProfileChangeRequest(
  id: number,
): Promise<ProfileChangeRequest> {
  const envelope = parse(
    profileChangeRequestEnvelopeSchema,
    await deleteAuthenticatedJson(`${PROFILE_CHANGE_REQUESTS_PATH}/${id}`),
    "cancelled profile change request",
  )
  return envelope.data
}

export async function decideProfileChangeRequest(
  id: number,
  input: DecideProfileChangeRequestInput,
): Promise<ProfileChangeRequest> {
  const parsed = parse(
    decideProfileChangeRequestSchema,
    input,
    "profile change decision",
  )
  const envelope = parse(
    profileChangeRequestEnvelopeSchema,
    await patchAuthenticatedJson(
      `${PROFILE_CHANGE_REQUESTS_PATH}/${id}/decision`,
      parsed,
    ),
    "decided profile change request",
  )
  return envelope.data
}

/**
 * `privacyNoticeVersion`: the version of the Data Privacy Notice the student read and agreed to on the setup
 * page (ADR 0055). Sent only when given, and then kept against the account.
 */
export async function setupStudentAccount(
  input: AccountSetupInput,
  privacyNoticeVersion?: string,
): Promise<"active"> {
  const parsed = parse(accountSetupSchema, input, "account setup request")
  const envelope = parse(
    accountSetupEnvelopeSchema,
    await postJson(
      ACCOUNT_SETUP_PATH,
      privacyNoticeVersion === undefined
        ? parsed
        : {
            ...parsed,
            privacy_notice_acknowledged: true,
            privacy_notice_version: privacyNoticeVersion,
          },
    ),
    "account setup response",
  )
  return envelope.data.status
}

export async function requestStudentAccountSetupResend(
  email: string,
): Promise<ResendStudentAccountSetupResponse> {
  const parsed = parse(
    resendStudentAccountSetupSchema,
    { email },
    "account setup resend request",
  )
  const envelope = parse(
    resendStudentAccountSetupEnvelopeSchema,
    await postJson(RESEND_STUDENT_ACCOUNT_SETUP_PATH, parsed),
    "account setup resend response",
  )
  return envelope.data
}

export type {
  AccountSetupInput,
  DecideProfileChangeRequestInput,
  ProfileChangeRequest,
  ProfileChangeRequestFilters,
  ProvisionStudentInput,
  ResendStudentAccountSetupInput,
  ResendStudentAccountSetupResponse,
  StoreProfileChangeRequestInput,
  StudentProfile,
  StudentProfileFilters,
  UpdateStudentProfileInput,
}
