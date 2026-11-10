import {
  staffAccountSetupEnvelopeSchema,
  staffInvitationEnvelopeSchema,
  staffInvitationsEnvelopeSchema,
  type StaffAccountSetupInput,
  type StaffInvitableRole,
  type StaffInvitation,
} from "@/features/schemas/staff-invitation-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
  postJson,
} from "@/features/services/api-client"

export const STAFF_INVITATIONS_PATH = "/api/v1/staff-invitations"
export const STAFF_ACCOUNT_SETUP_PATH = "/api/v1/auth/staff-account-setup"

export async function listStaffInvitations(
  signal?: AbortSignal,
): Promise<readonly StaffInvitation[]> {
  const payload = await getAuthenticatedJson(STAFF_INVITATIONS_PATH, signal)
  const result = staffInvitationsEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its staff invitation list did not match the published v1 contract.",
    cause: result.error,
  })
}

export async function inviteStaffAccount(
  email: string,
  role: StaffInvitableRole,
): Promise<StaffInvitation> {
  const payload = await postAuthenticatedJson(STAFF_INVITATIONS_PATH, {
    email,
    role,
  })
  const result = staffInvitationEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its staff invitation did not match the published v1 contract.",
    cause: result.error,
  })
}

export async function resendStaffInvitation(
  id: number,
): Promise<StaffInvitation> {
  const payload = await postAuthenticatedJson(
    `${STAFF_INVITATIONS_PATH}/${id}/resend`,
  )
  const result = staffInvitationEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its staff invitation did not match the published v1 contract.",
    cause: result.error,
  })
}

/**
 * `privacyNoticeVersion`: the version of the Data Privacy Notice the staff member read and agreed to on the
 * setup page (ADR 0055). Sent only when given, and then kept against the account.
 */
export async function setupStaffAccount(
  input: StaffAccountSetupInput,
  privacyNoticeVersion?: string,
): Promise<"active"> {
  const payload = await postJson(
    STAFF_ACCOUNT_SETUP_PATH,
    privacyNoticeVersion === undefined
      ? input
      : {
          ...input,
          privacy_notice_acknowledged: true,
          privacy_notice_version: privacyNoticeVersion,
        },
  )
  const result = staffAccountSetupEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data.status

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its staff account setup did not match the published v1 contract.",
    cause: result.error,
  })
}
