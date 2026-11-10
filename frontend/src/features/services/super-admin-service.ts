import {
  actingContextResponseSchema,
  changeUserRoleSchema,
  deleteUserResponseSchema,
  inviteUserAccountSchema,
  managedUserResponseSchema,
  managedUsersResponseSchema,
  passwordResetResponseSchema,
  revokeSessionsResponseSchema,
  setUserStatusSchema,
  type ActingContextResponse,
  type ChangeUserRolePayload,
  type DeleteUserResponse,
  type InviteUserAccountPayload,
  type ManagedUserFilters,
  type ManagedUserResponse,
  type ManagedUsersResponse,
  type PasswordResetResponse,
  type RevokeSessionsResponse,
  type SetUserStatusPayload,
  type UpdateActingContextPayload,
} from "@/features/schemas/super-admin-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
  putAuthenticatedJson,
} from "@/features/services/api-client"

export const SUPER_ADMIN_ACTING_CONTEXT_PATH =
  "/api/v1/super-admin/acting-context"
export const SUPER_ADMIN_USERS_PATH = "/api/v1/super-admin/users"

function contractError(message: string, cause: unknown): ApiClientError {
  return new ApiClientError({
    kind: "contract",
    message,
    cause,
  })
}

export async function switchActingContext(
  payload: UpdateActingContextPayload,
  signal?: AbortSignal,
): Promise<ActingContextResponse> {
  const raw = await putAuthenticatedJson(
    SUPER_ADMIN_ACTING_CONTEXT_PATH,
    payload,
    signal,
  )

  const parsed = actingContextResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its acting context payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function clearActingContext(
  signal?: AbortSignal,
): Promise<ActingContextResponse> {
  const raw = await deleteAuthenticatedJson(
    SUPER_ADMIN_ACTING_CONTEXT_PATH,
    signal,
  )

  const parsed = actingContextResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its acting context payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function listManagedUsers(
  filters: ManagedUserFilters = {},
  signal?: AbortSignal,
): Promise<ManagedUsersResponse> {
  const params = new URLSearchParams()
  if (filters.q) params.set("q", filters.q)
  if (filters.role) params.set("role", filters.role)
  if (filters.status) params.set("status", filters.status)
  if (filters.college) params.set("college", filters.college)
  if (filters.pending_setup !== undefined) {
    params.set("pending_setup", filters.pending_setup ? "true" : "false")
  }
  if (filters.page) params.set("page", String(filters.page))
  if (filters.per_page) params.set("per_page", String(filters.per_page))

  const queryString = params.toString()
  const path = queryString
    ? `${SUPER_ADMIN_USERS_PATH}?${queryString}`
    : SUPER_ADMIN_USERS_PATH

  const raw = await getAuthenticatedJson(path, signal)
  const parsed = managedUsersResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its users list payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function inviteUserAccount(
  payload: InviteUserAccountPayload,
  signal?: AbortSignal,
): Promise<ManagedUserResponse> {
  const validatedPayload = inviteUserAccountSchema.parse(payload)
  const raw = await postAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/invite`,
    validatedPayload,
    signal,
  )
  const parsed = managedUserResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its invited user payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function changeUserRole(
  userId: number,
  payload: ChangeUserRolePayload,
  signal?: AbortSignal,
): Promise<ManagedUserResponse> {
  const validatedPayload = changeUserRoleSchema.parse(payload)
  const raw = await patchAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/${userId}/role`,
    validatedPayload,
    signal,
  )
  const parsed = managedUserResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its changed role payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function setUserAccountStatus(
  userId: number,
  payload: SetUserStatusPayload,
  signal?: AbortSignal,
): Promise<ManagedUserResponse> {
  const validatedPayload = setUserStatusSchema.parse(payload)
  const raw = await patchAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/${userId}/status`,
    validatedPayload,
    signal,
  )
  const parsed = managedUserResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its updated status payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function resendSetupInvitation(
  userId: number,
  signal?: AbortSignal,
): Promise<ManagedUserResponse> {
  const raw = await postAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/${userId}/setup-invitation`,
    {},
    signal,
  )
  const parsed = managedUserResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its resent invitation payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function sendPasswordReset(
  userId: number,
  signal?: AbortSignal,
): Promise<PasswordResetResponse> {
  const raw = await postAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/${userId}/password-reset`,
    {},
    signal,
  )
  const parsed = passwordResetResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its password reset payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function revokeUserSessions(
  userId: number,
  signal?: AbortSignal,
): Promise<RevokeSessionsResponse> {
  const raw = await deleteAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/${userId}/sessions`,
    signal,
  )
  const parsed = revokeSessionsResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its revoke sessions payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}

export async function deleteUserAccount(
  userId: number,
  signal?: AbortSignal,
): Promise<DeleteUserResponse> {
  const raw = await deleteAuthenticatedJson(
    `${SUPER_ADMIN_USERS_PATH}/${userId}`,
    signal,
  )
  const parsed = deleteUserResponseSchema.safeParse(raw)

  if (!parsed.success) {
    throw contractError(
      "The API responded, but its deleted user payload did not match the published v1 contract.",
      parsed.error,
    )
  }

  return parsed.data
}
