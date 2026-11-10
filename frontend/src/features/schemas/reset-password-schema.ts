import { z } from "zod"

import { strongPasswordSchema } from "@/features/schemas/password-schema"

export const resetPasswordSchema = z
  .object({
    email: z.email("Enter a valid email address."),
    code: z
      .string()
      .trim()
      .regex(/^[0-9]{6}$/, "Enter the 6-digit code from your email."),
    password: strongPasswordSchema,
    password_confirmation: z.string().min(8),
  })
  .strict()
  .refine((input) => input.password === input.password_confirmation, {
    path: ["password_confirmation"],
    message: "Passwords must match.",
  })

export const resetPasswordEnvelopeSchema = z
  .object({
    data: z.object({
      type: z.literal("reset-password"),
      status: z.literal("reset"),
    }),
  })
  .strict()

export type ResetPasswordInput = z.infer<typeof resetPasswordSchema>
