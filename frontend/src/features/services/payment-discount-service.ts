import {
  paymentDiscountEnvelopeSchema,
  updatePaymentDiscountInputSchema,
  type PaymentDiscountSetting,
  type UpdatePaymentDiscountInput,
} from "@/features/schemas/payment-discount-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  putAuthenticatedJson,
} from "@/features/services/api-client"

export const PAYMENT_DISCOUNT_PATH = "/api/v1/payment-discount-settings"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  value: unknown,
  label: string,
): T {
  const result = schema.safeParse(value)
  if (result.success) return result.data
  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

/** The full-payment discount's percentage and validity (ADR 0046). */
export async function getPaymentDiscountSetting(
  signal?: AbortSignal,
): Promise<PaymentDiscountSetting> {
  return parse(
    paymentDiscountEnvelopeSchema,
    await getAuthenticatedJson(PAYMENT_DISCOUNT_PATH, signal),
    "payment discount setting",
  ).data
}

/** Registrar Head only. Both dates are always sent: null clears one. */
export async function updatePaymentDiscountSetting(
  input: UpdatePaymentDiscountInput,
): Promise<PaymentDiscountSetting> {
  const body = parse(
    updatePaymentDiscountInputSchema,
    input,
    "payment discount request",
  )
  return parse(
    paymentDiscountEnvelopeSchema,
    await putAuthenticatedJson(PAYMENT_DISCOUNT_PATH, body),
    "saved payment discount setting",
  ).data
}
