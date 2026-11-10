import {
  queueKioskCredentialEnvelopeSchema,
  updateQueueKioskCredentialInputSchema,
  type QueueKioskCredential,
  type UpdateQueueKioskCredentialInput,
} from "@/features/schemas/queue-kiosk-credential-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  putAuthenticatedJson,
} from "@/features/services/api-client"

export const QUEUE_KIOSK_CREDENTIAL_PATH = "/api/v1/queue-kiosk-credential"

function parseCredential(value: unknown, label: string): QueueKioskCredential {
  const result = queueKioskCredentialEnvelopeSchema.safeParse(value)
  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

export async function getQueueKioskCredential(
  signal?: AbortSignal,
): Promise<QueueKioskCredential> {
  return parseCredential(
    await getAuthenticatedJson(QUEUE_KIOSK_CREDENTIAL_PATH, signal),
    "queue kiosk credential",
  )
}

export async function updateQueueKioskCredential(
  input: UpdateQueueKioskCredentialInput,
  signal?: AbortSignal,
): Promise<QueueKioskCredential> {
  return parseCredential(
    await putAuthenticatedJson(
      QUEUE_KIOSK_CREDENTIAL_PATH,
      updateQueueKioskCredentialInputSchema.parse(input),
      signal,
    ),
    "queue kiosk credential",
  )
}
