import {
  confirmPaymentInputSchema,
  adjustEnrollmentAssessmentInputSchema,
  scholarshipDiscountInputSchema,
  eligibleSubjectsEnvelopeSchema,
  enrollmentEnvelopeSchema,
  enrollmentFiltersSchema,
  paginatedEnrollmentsSchema,
  paymentConfirmationEnvelopeSchema,
  paymentQuoteEnvelopeSchema,
  reviseEnrollmentSubjectsInputSchema,
  storeEnrollmentInputSchema,
  updateEnrollmentInputSchema,
  type ConfirmPaymentInput,
  type AdjustEnrollmentAssessmentInput,
  type ScholarshipPercentage,
  type EligibleSubject,
  type Enrollment,
  type EnrollmentFilters,
  type Paginated,
  type PaymentConfirmation,
  type PaymentQuote,
  type ReviseEnrollmentSubjectsInput,
  type StoreEnrollmentInput,
  type UpdateEnrollmentInput,
} from "@/features/schemas/enrollment-schema"
import {
  corPreviewEnvelopeSchema,
  type CorPreviewResponse,
} from "@/features/schemas/enrollment-document-schema"
import {
  enrollmentBlocksEnvelopeSchema,
  type EnrollmentBlock,
} from "@/features/schemas/enrollment-block-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  deleteAuthenticatedJson,
  patchAuthenticatedJson,
  putAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const ELIGIBLE_SUBJECTS_PATH = "/api/v1/eligible-subjects"
export const ENROLLMENT_BLOCKS_PATH = "/api/v1/enrollment-blocks"
export const ENROLLMENTS_PATH = "/api/v1/enrollments"

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

export async function getEligibleSubjects(
  academicTermId: number,
  signal?: AbortSignal,
  enrollmentId?: number,
): Promise<readonly EligibleSubject[]> {
  const payload = await getAuthenticatedJson(
    `${ELIGIBLE_SUBJECTS_PATH}?academic_term_id=${academicTermId}${enrollmentId === undefined ? "" : `&enrollment_id=${enrollmentId}`}`,
    signal,
  )
  return parse(eligibleSubjectsEnvelopeSchema, payload, "eligible subject pool")
    .data
}

/**
 * Empty for an irregular student — they use `getEligibleSubjects` instead.
 */
export async function getEnrollmentBlocks(
  academicTermId: number,
  signal?: AbortSignal,
): Promise<readonly EnrollmentBlock[]> {
  const payload = await getAuthenticatedJson(
    `${ENROLLMENT_BLOCKS_PATH}?academic_term_id=${academicTermId}`,
    signal,
  )
  return parse(enrollmentBlocksEnvelopeSchema, payload, "enrollment block pool")
    .data
}

/**
 * The authenticated student's own enrollments only — page 1 of the
 * role-scoped paginated endpoint, unwrapped to a flat list. A student
 * accumulates at most a handful of enrollments across their whole academic
 * life, so pagination controls add nothing here; `listEnrollments` below is
 * for the Registrar Head/Accounting Staff queues, which do need them.
 */
export async function getEnrollments(
  signal?: AbortSignal,
): Promise<readonly Enrollment[]> {
  const payload = await getAuthenticatedJson(ENROLLMENTS_PATH, signal)
  return parse(paginatedEnrollmentsSchema, payload, "enrollment list").data
}

export async function listEnrollments(
  filters: EnrollmentFilters,
  signal?: AbortSignal,
): Promise<Paginated<Enrollment>> {
  const parsed = parse(enrollmentFiltersSchema, filters, "enrollment filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    // The API's boolean rule takes 1 or 0, not "true" or "false".
    if (Array.isArray(value))
      value.forEach((id) => query.append(`${key}[]`, String(id)))
    else if (typeof value === "boolean") query.set(key, value ? "1" : "0")
    else if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedEnrollmentsSchema,
    await getAuthenticatedJson(
      `${ENROLLMENTS_PATH}?${query.toString()}`,
      signal,
    ),
    "enrollment list",
  )
}

export async function createEnrollment(
  input: StoreEnrollmentInput,
): Promise<Enrollment> {
  const payload = await postAuthenticatedJson(
    ENROLLMENTS_PATH,
    parse(storeEnrollmentInputSchema, input, "enrollment request"),
  )
  return parse(enrollmentEnvelopeSchema, payload, "created enrollment").data
}

export async function updateEnrollment(
  id: number,
  input: UpdateEnrollmentInput,
): Promise<Enrollment> {
  const payload = await patchAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${id}`,
    parse(updateEnrollmentInputSchema, input, "enrollment decision"),
  )
  return parse(enrollmentEnvelopeSchema, payload, "updated enrollment").data
}

/**
 * Program Head adding/removing whole subjects from a student's proposed
 * schedule while it sits at their own review stage.
 */
export async function reviseEnrollmentSubjects(
  id: number,
  input: ReviseEnrollmentSubjectsInput,
): Promise<Enrollment> {
  const payload = await patchAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${id}/subjects`,
    parse(
      reviseEnrollmentSubjectsInputSchema,
      input,
      "enrollment subject revision",
    ),
  )
  return parse(enrollmentEnvelopeSchema, payload, "revised enrollment").data
}

export async function confirmPayment(
  id: number,
  input: ConfirmPaymentInput,
): Promise<PaymentConfirmation> {
  const payload = await postAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${id}/payment`,
    parse(confirmPaymentInputSchema, input, "payment confirmation request"),
  )
  return parse(
    paymentConfirmationEnvelopeSchema,
    payload,
    "payment confirmation",
  ).data
}

/**
 * Accounting-only, pre-payment correction. The API recomputes all totals and
 * rejects the request after payment/COR generation, so this client never
 * mutates authoritative billing state locally.
 */
export async function adjustEnrollmentAssessment(
  id: number,
  input: AdjustEnrollmentAssessmentInput,
): Promise<Enrollment> {
  const payload = await patchAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${id}/assessment`,
    parse(
      adjustEnrollmentAssessmentInputSchema,
      input,
      "assessment adjustment request",
    ),
  )
  return parse(enrollmentEnvelopeSchema, payload, "adjusted assessment").data
}

/**
 * ADR 0025: Accounting-only, pre-payment. Assigns (or replaces) the scholarship
 * tier as a negative assessment line and marks the student a Scholar. The API
 * computes the discount; the client only ever sends one of the three tiers.
 */
export async function applyScholarshipDiscount(
  id: number,
  percentage: ScholarshipPercentage,
): Promise<Enrollment> {
  const payload = await putAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${id}/scholarship-discount`,
    parse(
      scholarshipDiscountInputSchema,
      { percentage },
      "scholarship discount request",
    ),
  )
  return parse(enrollmentEnvelopeSchema, payload, "scholarship discount").data
}

/** The Cashier's "Regular payee" choice: removes any scholarship and marks the student a Payee. */
export async function removeScholarshipDiscount(
  id: number,
): Promise<Enrollment> {
  const payload = await deleteAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${id}/scholarship-discount`,
  )
  return parse(enrollmentEnvelopeSchema, payload, "scholarship removal").data
}

export async function getCorPreview(
  enrollmentId: number,
  signal?: AbortSignal,
): Promise<CorPreviewResponse> {
  const payload = await getAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${enrollmentId}/cor-preview`,
    signal,
  )
  return parse(corPreviewEnvelopeSchema, payload, "COR preview").data
}

/**
 * ADR 0046: what paying in full collects for a pending-payment enrollment, computed by the API
 * so the browser never disagrees with it on rounding.
 */
export async function getPaymentQuote(
  enrollmentId: number,
  signal?: AbortSignal,
): Promise<PaymentQuote> {
  const payload = await getAuthenticatedJson(
    `${ENROLLMENTS_PATH}/${enrollmentId}/payment-quote`,
    signal,
  )
  return parse(paymentQuoteEnvelopeSchema, payload, "payment quote").data
}
