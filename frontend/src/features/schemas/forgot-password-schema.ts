import { z } from "zod"

export const forgotPasswordSchema = z
  .object({
    email: z.email("Enter a valid email address."),
  })
  .strict()

export const forgotPasswordEnvelopeSchema = z
  .object({
    data: z.object({
      type: z.literal("forgot-password"),
      status: z.literal("sent"),
      message: z.string(),
    }),
  })
  .strict()

export type ForgotPasswordInput = z.infer<typeof forgotPasswordSchema>
export type ForgotPasswordResponse = z.infer<
  typeof forgotPasswordEnvelopeSchema
>["data"]
