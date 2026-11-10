import { z } from "zod"

/**
 * A freshman's first payment at the Cashier (ADR 0043, amended 2026-10-04). `outcome`:
 * - `awaiting_registrar_approval`: the payment is in and the freshman is submitted to the
 *   first block with open seats; the Registrar's approval is what enrolls them.
 * - `awaiting_section`: every block was full (or the freshman is not placed in a
 *   block), so the payment is held as credit until a section opens.
 * - `enrolled`: the Registrar has approved; only ever returned by a repeat confirmation.
 */
export const freshmanPaymentOutcomeSchema = z.enum([
  "awaiting_registrar_approval",
  "awaiting_section",
  "enrolled",
])

export const freshmanPaymentPreviewSchema = z
  .object({
    type: z.literal("freshman_payment_preview"),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    // `freshman` or `transferee`: the two students who pay first (ADR 0043, amended 2026-10-05).
    // Both keys default so a response from a server that predates them still parses.
    student_type: z.string().min(1).nullable().default(null),
    // A transferee's own chosen subjects, waiting at the payment hold; null for a freshman and
    // for a transferee who has not chosen yet.
    enrollment: z
      .object({
        id: z.number().int().positive(),
        status: z.string().min(1),
        status_label: z.string().min(1),
        total_units: z.number().nonnegative(),
        subjects: z.array(
          z
            .object({
              code: z.string().min(1),
              title: z.string().min(1),
              units: z.number().nonnegative(),
            })
            .strict(),
        ),
      })
      .strict()
      .nullable()
      .default(null),
    ticket_number: z.string().min(1).nullable(),
    minimum_amount: z.string().min(1),
    already_confirmed: z.boolean(),
    can_enroll: z.boolean(),
    block: z
      .object({
        block_code: z.string().min(1),
        total_units: z.number().nonnegative(),
        seats_remaining: z.number().int().nonnegative(),
        subjects: z.array(
          z
            .object({
              code: z.string().min(1),
              title: z.string().min(1),
              units: z.number().nonnegative(),
            })
            .strict(),
        ),
      })
      .strict()
      .nullable(),
    estimated_total: z.string().min(1).nullable(),
    // ADR 0046: what paying in full collects. Defaults so a response from a server that predates it
    // still parses and simply offers no discount.
    full_payment: z
      .object({
        applies: z.boolean(),
        percentage: z.string().nullable(),
        discount_amount: z.string().nullable(),
        net_total: z.string().nullable(),
        reason: z
          .enum(["off", "scheduled", "expired", "scholar", "no_estimate"])
          .nullable(),
      })
      .strict()
      .default({
        applies: false,
        percentage: null,
        discount_amount: null,
        net_total: null,
        reason: null,
      }),
  })
  .strict()

export const freshmanPaymentPreviewEnvelopeSchema = z
  .object({ data: freshmanPaymentPreviewSchema })
  .strict()

export const confirmFreshmanPaymentInputSchema = z
  .object({
    amount: z
      .string()
      .trim()
      .regex(/^\d+(\.\d{1,2})?$/, "Enter the amount received, e.g. 1000.00."),
    external_reference: z.string().trim().max(255).optional(),
    // ADR 0046: `full` pays the discounted estimate; `amount` must be the net the Cashier was shown.
    payment_type: z.enum(["full", "installment"]).optional(),
  })
  .strict()

export const freshmanPaymentSchema = z
  .object({
    type: z.literal("freshman_payment"),
    outcome: freshmanPaymentOutcomeSchema,
    block_code: z.string().min(1).nullable(),
    student: z
      .object({
        id: z.number().int().positive(),
        student_number: z.string().min(1),
        name: z.string().min(1),
      })
      .strict(),
    enrollment: z
      .object({
        id: z.number().int().positive(),
        status: z.string().min(1),
        total_units: z.number().nonnegative(),
      })
      .strict()
      .nullable(),
    payment: z
      .object({
        external_reference: z.string().nullable(),
        amount: z.string().nullable(),
        promissory_note_on_file: z.boolean(),
        confirmed_at: z.iso.datetime().nullable(),
      })
      .strict()
      .nullable(),
    document: z
      .object({
        id: z.number().int().positive().nullable(),
        document_type: z.string().nullable(),
        document_number: z.string().nullable(),
        generated_at: z.iso.datetime().nullable(),
      })
      .strict()
      .nullable(),
    held_amount: z.string().min(1).nullable(),
  })
  .strict()

export const freshmanPaymentEnvelopeSchema = z
  .object({ data: freshmanPaymentSchema })
  .strict()

export type FreshmanPaymentPreview = z.infer<
  typeof freshmanPaymentPreviewSchema
>
export type FreshmanPayment = z.infer<typeof freshmanPaymentSchema>
export type ConfirmFreshmanPaymentInput = z.input<
  typeof confirmFreshmanPaymentInputSchema
>
