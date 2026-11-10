import { z } from "zod"

const percentagePattern = /^\d{1,3}(\.\d)?$/
const datePattern = /^\d{4}-\d{2}-\d{2}$/

export const paymentDiscountStatusSchema = z.enum([
  "active",
  "scheduled",
  "expired",
  "off",
])

/** The full-payment discount setting as the API returns it (ADR 0046). */
export const paymentDiscountSettingSchema = z
  .object({
    type: z.literal("payment_discount_setting"),
    percentage: z.string().regex(percentagePattern),
    valid_from: z.string().regex(datePattern).nullable(),
    valid_until: z.string().regex(datePattern).nullable(),
    status: paymentDiscountStatusSchema,
    updated_by: z.string().nullable(),
    updated_at: z.iso.datetime().nullable(),
  })
  .strict()

export const paymentDiscountEnvelopeSchema = z
  .object({ data: paymentDiscountSettingSchema })
  .strict()

/** What the form holds: dates are "" while empty. */
export const paymentDiscountFormSchema = z
  .object({
    percentage: z
      .string()
      .trim()
      .regex(
        percentagePattern,
        "Enter a percentage with at most one decimal, for example 10 or 12.5.",
      )
      .refine((value) => Number(value) <= 100, "The percentage cannot be more than 100."),
    valid_from: z
      .string()
      .refine((value) => value === "" || datePattern.test(value), "Pick a valid date."),
    valid_until: z
      .string()
      .refine((value) => value === "" || datePattern.test(value), "Pick a valid date."),
  })
  .refine(
    (values) =>
      values.valid_from === "" ||
      values.valid_until === "" ||
      values.valid_until >= values.valid_from,
    { path: ["valid_until"], message: "The end date cannot be before the start date." },
  )

/** What is sent: an empty date becomes null, which clears it. */
export const updatePaymentDiscountInputSchema = z
  .object({
    percentage: z.string().regex(percentagePattern),
    valid_from: z.string().regex(datePattern).nullable(),
    valid_until: z.string().regex(datePattern).nullable(),
  })
  .strict()

export type PaymentDiscountSetting = z.infer<typeof paymentDiscountSettingSchema>
export type PaymentDiscountFormValues = z.infer<typeof paymentDiscountFormSchema>
export type UpdatePaymentDiscountInput = z.input<typeof updatePaymentDiscountInputSchema>
