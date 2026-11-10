import { z } from "zod"

/**
 * A Transferee's or Returnee's "I have no subject to credit". It is `pending` while it waits for the
 * Program Chair and `confirmed` once the Chair has reviewed it; only then may the student go on to enroll
 * (ADR 0026 / 0050, amended).
 */
export const noCreditDeclarationSchema = z
  .object({
    type: z.literal("no_credit_declaration"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string(),
    status: z.enum(["pending", "confirmed"]),
    declared_at: z.iso.datetime(),
    confirmed_at: z.iso.datetime().nullable(),
  })
  .strict()

/** The student's own declaration, or `data: null` when they have made none. */
export const myNoCreditDeclarationEnvelopeSchema = z
  .object({ data: noCreditDeclarationSchema.nullable() })
  .strict()

export const noCreditDeclarationEnvelopeSchema = z
  .object({ data: noCreditDeclarationSchema })
  .strict()

export const noCreditDeclarationListEnvelopeSchema = z
  .object({ data: z.array(noCreditDeclarationSchema) })
  .strict()

export type NoCreditDeclaration = z.infer<typeof noCreditDeclarationSchema>
