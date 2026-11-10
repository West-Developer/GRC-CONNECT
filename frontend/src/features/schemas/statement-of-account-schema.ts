import { z } from "zod"

/** Mirrors `BuildStatementOfAccount` (ADR 0036). Amounts are 2-decimal strings. */
const amountSchema = z.string().regex(/^-?\d+\.\d{2}$/)

const lineSchema = z
  .object({
    category: z.string().min(1),
    label: z.string().min(1),
    quantity: z.string().nullable(),
    unit_amount: z.string().nullable(),
    amount: amountSchema,
  })
  .strict()

const paymentSchema = z
  .object({
    kind: z.enum(["enrollment_payment", "balance_payment"]),
    label: z.string().min(1),
    reference_number: z.string().min(1),
    amount: amountSchema,
    promissory_note_on_file: z.boolean(),
    paid_at: z.iso.datetime().nullable(),
  })
  .strict()

export const statementTermSchema = z
  .object({
    academic_term_id: z.number().int().positive(),
    label: z.string().min(1),
    enrollment_id: z.number().int().positive(),
    enrollment_status: z.string().min(1),
    lines: z.array(lineSchema),
    scholarship_discount: amountSchema,
    assessment_total: amountSchema,
    payments: z.array(paymentSchema),
    paid_total: amountSchema,
    outstanding: amountSchema,
    prior_balance: amountSchema,
    running_balance: amountSchema,
  })
  .strict()

export const statementOfAccountSchema = z
  .object({
    type: z.literal("statement_of_account"),
    student: z
      .object({
        student_profile_id: z.number().int().positive(),
        student_number: z.string().min(1),
        name: z.string().min(1),
        program_code: z.string().min(1),
        program_name: z.string().min(1),
      })
      .strict(),
    academic_term_id: z.number().int().positive().nullable(),
    summary: z
      .object({
        total_assessed: amountSchema,
        total_paid: amountSchema,
        outstanding_balance: amountSchema,
        advance_payment_balance: amountSchema,
      })
      .strict(),
    terms: z.array(statementTermSchema),
    credits: z.array(
      z
        .object({
          reference_number: z.string().min(1),
          amount: amountSchema,
          received_at: z.iso.datetime().nullable(),
        })
        .strict(),
    ),
    generated_at: z.iso.datetime(),
  })
  .strict()

export const statementOfAccountEnvelopeSchema = z
  .object({ data: statementOfAccountSchema })
  .strict()

export type StatementOfAccount = z.infer<typeof statementOfAccountSchema>
export type StatementTerm = z.infer<typeof statementTermSchema>
