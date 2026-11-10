import { z } from "zod"

const moneySchema = z.string().regex(/^\d+\.\d{2}$/)

export const cashierTransactionSchema = z
  .object({
    type: z.literal("cashier_transaction"),
    id: z.string().regex(/^(enrollment_payment|account_payment):\d+$/),
    transaction_type: z.enum(["enrollment_payment", "account_payment"]),
    student_id: z.number().int().positive(),
    student_name: z.string().min(1),
    student_number: z.string().min(1),
    enrollment_id: z.number().int().positive().nullable(),
    amount: moneySchema,
    processed_at: z.iso.datetime(),
  })
  .strict()

export const cashierPaymentCandidateSchema = z
  .object({
    type: z.literal("cashier_payment_candidate"),
    student_id: z.number().int().positive(),
    student_name: z.string().min(1),
    student_number: z.string().min(1),
    year_level: z.number().int().positive(),
    enrollment_id: z.number().int().positive(),
    ticket: z
      .object({
        id: z.number().int().positive(),
        ticket_number: z.string().min(1),
        status: z.enum(["waiting", "serving"]),
      })
      .strict()
      .nullable(),
  })
  .strict()

// One row of the Cashier's student search (Advance Payment). Unlike a payment
// candidate it carries no enrollment or ticket: it works for any student.
export const cashierStudentSchema = z
  .object({
    type: z.literal("cashier_student"),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    year_level: z.number().int().positive(),
    financial_status: z.enum(["payee", "scholar"]),
    financial_status_label: z.string().min(1),
  })
  .strict()

export const cashierStudentsEnvelopeSchema = z
  .object({ data: z.array(cashierStudentSchema) })
  .strict()

export const cashierStudentSearchSchema = z.string().trim().min(2).max(100)

const paginationLinksSchema = z
  .object({
    first: z.string().url(),
    last: z.string().url(),
    prev: z.string().url().nullable(),
    next: z.string().url().nullable(),
  })
  .strict()
const paginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().min(1).max(100),
    total: z.number().int().nonnegative(),
  })
  .passthrough()

export const paginatedCashierTransactionsSchema = z
  .object({
    data: z.array(cashierTransactionSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const cashierTransactionFiltersSchema = z
  .object({
    student_number: z.string().trim().min(1).max(255).optional(),
    processed_on: z.iso.date().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(20),
  })
  .strict()

export const cashierPaymentCandidateLookupSchema = z
  .string()
  .trim()
  .min(1)
  .max(255)

export const cashierPaymentCandidateEnvelopeSchema = z
  .object({ data: cashierPaymentCandidateSchema })
  .strict()

export type CashierStudent = z.infer<typeof cashierStudentSchema>
export type CashierTransaction = z.infer<typeof cashierTransactionSchema>
export type CashierPaymentCandidate = z.infer<
  typeof cashierPaymentCandidateSchema
>
export type CashierTransactionFilters = z.input<
  typeof cashierTransactionFiltersSchema
>
export interface PaginatedCashierTransactions {
  data: readonly CashierTransaction[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}
