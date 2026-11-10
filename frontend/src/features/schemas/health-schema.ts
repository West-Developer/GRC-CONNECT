import { z } from "zod"

const utcDateTimeSchema = z.iso
  .datetime()
  .refine((value) => value.endsWith("Z"), {
    message: "The health timestamp must be RFC 3339 UTC.",
  })

export const healthEnvelopeSchema = z
  .object({
    data: z
      .object({
        type: z.literal("service-health"),
        service: z.literal("grc-enrollment-api"),
        status: z.literal("ok"),
        api_version: z.literal("v1"),
        generated_at: utcDateTimeSchema,
      })
      .strict(),
  })
  .strict()
