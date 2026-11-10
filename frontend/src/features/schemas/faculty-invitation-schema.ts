import { z } from "zod"

import { strongPasswordSchema } from "@/features/schemas/password-schema"

export const facultyInvitationSchema = z
  .object({
    type: z.literal("faculty_invitation"),
    id: z.number().int().positive(),
    email: z.email(),
    name: z.string().min(1),
    status: z.enum(["pending", "activated", "failed"]),
    invitation_sent_at: z.iso.datetime({ offset: true }).nullable(),
    activated_at: z.iso.datetime({ offset: true }).nullable(),
  })
  .strict()

export const facultyInvitationEnvelopeSchema = z
  .object({ data: facultyInvitationSchema })
  .strict()

export const facultyInvitationsEnvelopeSchema = z
  .object({ data: z.array(facultyInvitationSchema) })
  .strict()

export const storeFacultyInvitationSchema = z
  .object({
    email: z.email(),
  })
  .strict()

export const facultyAccountSetupSchema = z
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

export const facultyAccountSetupEnvelopeSchema = z
  .object({
    data: z.object({
      type: z.literal("faculty-account-setup"),
      status: z.literal("active"),
    }),
  })
  .strict()

export type FacultyInvitation = z.infer<typeof facultyInvitationSchema>
export type StoreFacultyInvitationInput = z.infer<
  typeof storeFacultyInvitationSchema
>
export type FacultyAccountSetupInput = z.infer<typeof facultyAccountSetupSchema>
