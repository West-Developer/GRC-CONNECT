import {
  createTransfereeCreditInputSchema,
  creditMappingWorkspaceEnvelopeSchema,
  creditSubjectSuggestionsEnvelopeSchema,
  decideTransfereeCreditInputSchema,
  paginatedTransfereeCreditsSchema,
  submittedTransfereeCreditsEnvelopeSchema,
  transfereeCreditActionInputSchema,
  transfereeCreditEnvelopeSchema,
  transfereeCreditFiltersSchema,
  updateTransfereeCreditInputSchema,
  type CreateTransfereeCreditInput,
  type CreditMappingWorkspace,
  type CreditSubjectSuggestion,
  type DecideTransfereeCreditInput,
  type Paginated,
  type TransfereeCredit,
  type TransfereeCreditActionInput,
  type TransfereeCreditFilters,
  type UpdateTransfereeCreditInput,
} from "@/features/schemas/transferee-credit-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const TRANSFEREE_CREDITS_PATH = "/api/v1/transferee-credits"
const STUDENT_PROFILES_PATH = "/api/v1/student-profiles"

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

export async function listTransfereeCredits(
  filters: TransfereeCreditFilters,
  signal?: AbortSignal,
): Promise<Paginated<TransfereeCredit>> {
  const parsed = parse(
    transfereeCreditFiltersSchema,
    filters,
    "transferee credit filter",
  )
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedTransfereeCreditsSchema,
    await getAuthenticatedJson(
      `${TRANSFEREE_CREDITS_PATH}?${query.toString()}`,
      signal,
    ),
    "transferee credit list",
  )
}

export async function createTransfereeCredit(
  input: CreateTransfereeCreditInput,
): Promise<TransfereeCredit> {
  const payload = await postAuthenticatedJson(
    TRANSFEREE_CREDITS_PATH,
    parse(createTransfereeCreditInputSchema, input, "transferee credit"),
  )
  return parse(
    transfereeCreditEnvelopeSchema,
    payload,
    "created transferee credit",
  ).data
}

/** The Program Chair's corrections and mapping of a pending credit. */
export async function updateTransfereeCredit(
  id: number,
  input: UpdateTransfereeCreditInput,
): Promise<TransfereeCredit> {
  const payload = await patchAuthenticatedJson(
    `${TRANSFEREE_CREDITS_PATH}/${id}`,
    parse(updateTransfereeCreditInputSchema, input, "transferee credit edit"),
  )
  return parse(
    transfereeCreditEnvelopeSchema,
    payload,
    "updated transferee credit",
  ).data
}

/** Endorse / decline (Program Chair) or approve / reject / return (Registrar Head). */
export async function actOnTransfereeCredit(
  id: number,
  input: TransfereeCreditActionInput,
): Promise<TransfereeCredit> {
  const payload = await patchAuthenticatedJson(
    `${TRANSFEREE_CREDITS_PATH}/${id}`,
    parse(transfereeCreditActionInputSchema, input, "transferee credit action"),
  )
  return parse(
    transfereeCreditEnvelopeSchema,
    payload,
    "updated transferee credit",
  ).data
}

/** The Registrar Head's decision on an endorsed credit. */
export async function decideTransfereeCredit(
  id: number,
  input: DecideTransfereeCreditInput,
): Promise<TransfereeCredit> {
  return actOnTransfereeCredit(
    id,
    parse(
      decideTransfereeCreditInputSchema,
      input,
      "transferee credit decision",
    ),
  )
}

/**
 * Subjects of the student's own curriculum this credit could be mapped to,
 * best first. Advice for the Program Chair, computed on demand.
 */
export async function getTransfereeCreditSuggestions(
  id: number,
  signal?: AbortSignal,
): Promise<CreditSubjectSuggestion[]> {
  return parse(
    creditSubjectSuggestionsEnvelopeSchema,
    await getAuthenticatedJson(
      `${TRANSFEREE_CREDITS_PATH}/${id}/suggestions`,
      signal,
    ),
    "credit subject suggestions",
  ).data
}

/**
 * Sends the student's whole saved list to the Program Chair. Idempotent: a
 * repeat sends nothing and answers an empty list.
 */
export async function submitTransfereeCredits(): Promise<TransfereeCredit[]> {
  return parse(
    submittedTransfereeCreditsEnvelopeSchema,
    await postAuthenticatedJson(`${TRANSFEREE_CREDITS_PATH}/submit`, {}),
    "submitted transferee credits",
  ).data
}

/** Removes one subject from the student's own saved list (only a draft can go). */
export async function deleteTransfereeCredit(id: number): Promise<void> {
  await deleteAuthenticatedJson(`${TRANSFEREE_CREDITS_PATH}/${id}`)
}

/**
 * What the Program Chair's side-by-side screen needs for one student: the
 * suggestions for every pending credit and the subjects of the student's own
 * curriculum. Advice only, computed on demand.
 */
export async function getCreditMappingWorkspace(
  studentId: number,
  signal?: AbortSignal,
): Promise<CreditMappingWorkspace> {
  return parse(
    creditMappingWorkspaceEnvelopeSchema,
    await getAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}/${studentId}/transferee-credits/suggestions`,
      signal,
    ),
    "credit mapping workspace",
  ).data
}

/**
 * The Program Chair sends a whole student to the Registrar Head. All or
 * nothing: a pending credit without a mapped subject is refused by the API.
 * Idempotent: a repeat sends nothing and answers an empty list.
 */
export async function submitCreditsToRegistrarHead(
  studentId: number,
): Promise<TransfereeCredit[]> {
  return parse(
    submittedTransfereeCreditsEnvelopeSchema,
    await postAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}/${studentId}/transferee-credits/submit-to-registrar-head`,
      {},
    ),
    "credits sent to the Registrar Head",
  ).data
}

/**
 * The Registrar Head approves every endorsed credit of one student at once.
 * Idempotent: a repeat approves nothing and answers an empty list.
 */
export async function approveAllStudentCredits(
  studentId: number,
): Promise<TransfereeCredit[]> {
  return parse(
    submittedTransfereeCreditsEnvelopeSchema,
    await postAuthenticatedJson(
      `${STUDENT_PROFILES_PATH}/${studentId}/transferee-credits/approve-all`,
      {},
    ),
    "approved transferee credits",
  ).data
}
