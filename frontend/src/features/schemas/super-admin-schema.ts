import { z } from "zod"

import { userSchema } from "@/features/schemas/auth-schema"

export const switchableRoles = [
  "admission_staff",
  "program_chair",
  "dean",
  "executive_director",
  "registrar_head",
  "registrar_staff",
  "accounting_staff",
  "it_admin",
] as const

export type SwitchableRole = (typeof switchableRoles)[number]

export const superAdminInvitableRoles = [
  ...switchableRoles,
  "faculty",
] as const

export type SuperAdminInvitableRole = (typeof superAdminInvitableRoles)[number]

export const collegeCodes = ["ccs", "coe", "coa", "cbae"] as const
export type CollegeCode = (typeof collegeCodes)[number]

export const updateActingContextSchema = z
  .object({
    role: z.enum(switchableRoles),
    college: z
      .enum(collegeCodes)
      .nullable()
      .optional(),
  })
  .strict()

export type UpdateActingContextPayload = z.infer<
  typeof updateActingContextSchema
>

export const actingContextResponseSchema = z
  .object({
    data: userSchema,
  })
  .strict()

export type ActingContextResponse = z.infer<
  typeof actingContextResponseSchema
>

export const managedUserSchema = z
  .object({
    type: z.literal("user_account"),
    id: z.number().int().positive(),
    name: z.string(),
    email: z.string().email(),
    role: z.string(),
    role_label: z.string(),
    college: z.string().nullable(),
    college_label: z.string().nullable(),
    status: z.enum(["active", "disabled"]),
    status_label: z.string(),
    pending_setup: z.boolean(),
    account_setup_completed_at: z.string().nullable(),
    last_login_at: z.string().nullable(),
    created_at: z.string(),
    manageable: z.boolean(),
    active_session_count: z.number().int().nonnegative(),
  })
  .strict()

export type ManagedUser = z.infer<typeof managedUserSchema>

export const managedUsersResponseSchema = z.object({
  data: z.array(managedUserSchema),
  meta: z.object({
    current_page: z.number(),
    from: z.number().nullable(),
    last_page: z.number(),
    per_page: z.number(),
    to: z.number().nullable(),
    total: z.number(),
  }),
  links: z.object({
    first: z.string().nullable(),
    last: z.string().nullable(),
    prev: z.string().nullable(),
    next: z.string().nullable(),
  }),
})

export type ManagedUsersResponse = z.infer<typeof managedUsersResponseSchema>

export const managedUserResponseSchema = z
  .object({
    data: managedUserSchema,
  })
  .strict()

export type ManagedUserResponse = z.infer<typeof managedUserResponseSchema>

export interface ManagedUserFilters {
  q?: string
  role?: string
  status?: string
  college?: string
  pending_setup?: boolean
  page?: number
  per_page?: number
}

export const inviteUserAccountSchema = z
  .object({
    email: z.string().email("A valid email address is required."),
    role: z.enum(superAdminInvitableRoles),
    college: z.enum(collegeCodes).nullable().optional(),
  })
  .superRefine((val, ctx) => {
    if (["program_chair", "dean", "faculty"].includes(val.role) && !val.college) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "College is required for Program Head, Dean, and Faculty.",
        path: ["college"],
      })
    }
  })

export type InviteUserAccountPayload = z.infer<typeof inviteUserAccountSchema>

export const changeUserRoleSchema = z
  .object({
    role: z.enum(superAdminInvitableRoles),
    college: z.enum(collegeCodes).nullable().optional(),
    reason: z.string().min(1, "Reason is required."),
  })
  .superRefine((val, ctx) => {
    if (["program_chair", "dean", "faculty"].includes(val.role) && !val.college) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: "College is required for Program Head, Dean, and Faculty.",
        path: ["college"],
      })
    }
  })

export type ChangeUserRolePayload = z.infer<typeof changeUserRoleSchema>

export const setUserStatusSchema = z.object({
  status: z.enum(["active", "disabled"]),
  reason: z.string().min(1, "Reason is required."),
})

export type SetUserStatusPayload = z.infer<typeof setUserStatusSchema>

export const revokeSessionsResponseSchema = z.object({
  data: z.object({
    user_id: z.number(),
    sessions_revoked: z.boolean(),
  }),
})

export type RevokeSessionsResponse = z.infer<typeof revokeSessionsResponseSchema>

export const passwordResetResponseSchema = z.object({
  data: z.object({
    user_id: z.number(),
    status: z.string(),
  }),
})

export type PasswordResetResponse = z.infer<typeof passwordResetResponseSchema>

export const deleteUserResponseSchema = z.object({
  data: z.object({
    user_id: z.number(),
    deleted: z.boolean(),
  }),
})

export type DeleteUserResponse = z.infer<typeof deleteUserResponseSchema>
