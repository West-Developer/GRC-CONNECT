import { z } from "zod"

import { strongPasswordSchema } from "@/features/schemas/password-schema"

/** Every role a Registrar Head may invite through this flow — mirrors `UserRole::registrarInvitableCases()`. */
export const staffInvitableRoleSchema = z.enum([
  "faculty",
  "program_chair",
  "dean",
  "executive_director",
  "registrar_head",
  "registrar_staff",
  "accounting_staff",
  "it_admin",
])

export const staffInvitationSchema = z
  .object({
    type: z.literal("staff_invitation"),
    id: z.number().int().positive(),
    email: z.email(),
    name: z.string().min(1),
    role: staffInvitableRoleSchema,
    role_label: z.string().min(1),
    status: z.enum(["pending", "activated", "failed"]),
    invitation_sent_at: z.iso.datetime({ offset: true }).nullable(),
    activated_at: z.iso.datetime({ offset: true }).nullable(),
  })
  .strict()

export const staffInvitationEnvelopeSchema = z
  .object({ data: staffInvitationSchema })
  .strict()

export const staffInvitationsEnvelopeSchema = z
  .object({ data: z.array(staffInvitationSchema) })
  .strict()

export const storeStaffInvitationSchema = z
  .object({
    email: z.email(),
    role: staffInvitableRoleSchema,
  })
  .strict()

export const staffAccountSetupSchema = z
  .object({
    email: z.email(),
    code: z
      .string()
      .trim()
      .regex(/^[0-9]{6}$/, "Enter the 6-digit code from your email."),
    name: z.string().trim().min(1),
    college: z.enum(["ccs", "coe", "coa", "cbae"]).optional().nullable(),
    masters_degree: z.string().trim().max(255).optional().nullable(),
    password: strongPasswordSchema,
    password_confirmation: z.string().min(8),
  })
  .strict()
  .refine((input) => input.password === input.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords must match.",
  })

export const staffAccountSetupEnvelopeSchema = z
  .object({
    data: z.object({
      type: z.literal("staff-account-setup"),
      status: z.literal("active"),
    }),
  })
  .strict()

export type StaffInvitableRole = z.infer<typeof staffInvitableRoleSchema>
export type StaffInvitation = z.infer<typeof staffInvitationSchema>
export type StoreStaffInvitationInput = z.infer<
  typeof storeStaffInvitationSchema
>
export type StaffAccountSetupInput = z.infer<typeof staffAccountSetupSchema>
