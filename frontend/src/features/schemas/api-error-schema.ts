import { z } from "zod"

export const apiErrorEnvelopeSchema = z
  .object({
    error: z
      .object({
        code: z.string().regex(/^[A-Z0-9_]+$/),
        message: z.string().min(1),
        errors: z.record(z.string(), z.array(z.string())),
        request_id: z.string().regex(/^[A-Za-z0-9._-]{1,128}$/),
      })
      .strict(),
  })
  .strict()
