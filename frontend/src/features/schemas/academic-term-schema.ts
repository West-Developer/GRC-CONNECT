import { z } from "zod"

// Fields carry the browser's raw `datetime-local` value (no seconds, no
// timezone suffix) — the service layer converts each to a full ISO 8601
// string before it reaches the API, so this schema only checks presence.
const localDateTimeSchema = z.string().trim().min(1, "Enter a date and time.")

export const storeAcademicTermInputSchema = z
  .object({
    school_year: z
      .string()
      .trim()
      .regex(/^\d{4}-\d{4}$/, "Use the YYYY-YYYY format."),
    semester: z.enum(["1st", "2nd"]),
    enrollment_opens_at: localDateTimeSchema,
    enrollment_closes_at: localDateTimeSchema,
    add_drop_deadline_at: localDateTimeSchema,
  })
  .strict()

export type StoreAcademicTermInput = z.infer<
  typeof storeAcademicTermInputSchema
>

/**
 * Only school year and semester — the archive-and-open action never
 * collects enrollment dates. Those are set afterwards on the enrollment
 * schedule card, once the new term exists.
 */
export const archiveAndCreateNextInputSchema = z
  .object({
    school_year: z
      .string()
      .trim()
      .regex(/^\d{4}-\d{4}$/, "Use the YYYY-YYYY format.")
      .optional(),
    semester: z.enum(["1st", "2nd"]).optional(),
  })
  .strict()

export type ArchiveAndCreateNextInput = z.infer<
  typeof archiveAndCreateNextInputSchema
>

/**
 * A Draft term can have its identifying year and semester corrected before
 * it begins. Enrollment dates are deliberately absent from this payload.
 */
export const updateDraftAcademicTermIdentityInputSchema = z
  .object({
    school_year: z
      .string()
      .trim()
      .regex(/^\d{4}-\d{4}$/, "Use the YYYY-YYYY format."),
    semester: z.enum(["1st", "2nd"]),
  })
  .strict()

export type UpdateDraftAcademicTermIdentityInput = z.infer<
  typeof updateDraftAcademicTermIdentityInputSchema
>
