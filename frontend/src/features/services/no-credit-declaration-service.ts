import {
  myNoCreditDeclarationEnvelopeSchema,
  noCreditDeclarationEnvelopeSchema,
  noCreditDeclarationListEnvelopeSchema,
  type NoCreditDeclaration,
} from "@/features/schemas/no-credit-declaration-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const NO_CREDIT_DECLARATION_PATH = "/api/v1/no-credit-declaration"
export const NO_CREDIT_DECLARATIONS_PATH = "/api/v1/no-credit-declarations"

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

/** The signed-in student's own "no subject to credit", or null when they have made none. */
export async function getMyNoCreditDeclaration(
  signal?: AbortSignal,
): Promise<NoCreditDeclaration | null> {
  return parse(
    myNoCreditDeclarationEnvelopeSchema,
    await getAuthenticatedJson(NO_CREDIT_DECLARATION_PATH, signal),
    "no-credit declaration",
  ).data
}

/** The student tells their Program Head they have no subject to credit. Safe to repeat. */
export async function declareNoCredit(): Promise<NoCreditDeclaration> {
  return parse(
    noCreditDeclarationEnvelopeSchema,
    await postAuthenticatedJson(NO_CREDIT_DECLARATION_PATH, {}),
    "no-credit declaration",
  ).data
}

/** The declarations still waiting for the signed-in Program Chair. */
export async function listNoCreditDeclarations(
  signal?: AbortSignal,
): Promise<NoCreditDeclaration[]> {
  return parse(
    noCreditDeclarationListEnvelopeSchema,
    await getAuthenticatedJson(NO_CREDIT_DECLARATIONS_PATH, signal),
    "no-credit declaration list",
  ).data
}

/** The Program Chair confirms a student has no subject to credit, so the student may enroll. Safe to repeat. */
export async function confirmNoCreditDeclaration(
  id: number,
): Promise<NoCreditDeclaration> {
  return parse(
    noCreditDeclarationEnvelopeSchema,
    await postAuthenticatedJson(
      `${NO_CREDIT_DECLARATIONS_PATH}/${id}/confirm`,
      {},
    ),
    "confirmed no-credit declaration",
  ).data
}
