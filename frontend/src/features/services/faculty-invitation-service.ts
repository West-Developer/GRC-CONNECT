import {
  facultyAccountSetupEnvelopeSchema,
  facultyInvitationEnvelopeSchema,
  facultyInvitationsEnvelopeSchema,
  type FacultyAccountSetupInput,
  type FacultyInvitation,
} from "@/features/schemas/faculty-invitation-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
  postJson,
} from "@/features/services/api-client"

export const FACULTY_INVITATIONS_PATH = "/api/v1/faculty-invitations"
export const FACULTY_ACCOUNT_SETUP_PATH = "/api/v1/auth/faculty-account-setup"

export async function listFacultyInvitations(
  signal?: AbortSignal,
): Promise<readonly FacultyInvitation[]> {
  const payload = await getAuthenticatedJson(FACULTY_INVITATIONS_PATH, signal)
  const result = facultyInvitationsEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its faculty invitation list did not match the published v1 contract.",
    cause: result.error,
  })
}

export async function inviteFacultyAccount(
  email: string,
): Promise<FacultyInvitation> {
  const payload = await postAuthenticatedJson(FACULTY_INVITATIONS_PATH, { email })
  const result = facultyInvitationEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its faculty invitation did not match the published v1 contract.",
    cause: result.error,
  })
}

export async function resendFacultyInvitation(
  id: number,
): Promise<FacultyInvitation> {
  const payload = await postAuthenticatedJson(
    `${FACULTY_INVITATIONS_PATH}/${id}/resend`,
  )
  const result = facultyInvitationEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its faculty invitation did not match the published v1 contract.",
    cause: result.error,
  })
}

/**
 * `privacyNoticeVersion`: the version of the Data Privacy Notice the professor read and agreed to on the setup
 * page (ADR 0055). Sent only when given, and then kept against the account.
 */
export async function setupFacultyAccount(
  input: FacultyAccountSetupInput,
  privacyNoticeVersion?: string,
): Promise<"active"> {
  const payload = await postJson(
    FACULTY_ACCOUNT_SETUP_PATH,
    privacyNoticeVersion === undefined
      ? input
      : {
          ...input,
          privacy_notice_acknowledged: true,
          privacy_notice_version: privacyNoticeVersion,
        },
  )
  const result = facultyAccountSetupEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data.status

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its faculty account setup did not match the published v1 contract.",
    cause: result.error,
  })
}
