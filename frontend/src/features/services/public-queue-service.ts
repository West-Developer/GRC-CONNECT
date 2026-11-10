import {
  studentQueueViewEnvelopeSchema,
  type StudentQueueView,
} from "@/features/schemas/student-queue-schema"
import { ApiClientError, getJson } from "@/features/services/api-client"

export const PUBLIC_QUEUE_VIEW_PATH = "/api/v1/public/queue-view"

/**
 * The live queue behind a freshman's emailed link (ADR 0044). Unauthenticated on
 * purpose — the freshman has no account yet — so it uses the public `getJson`,
 * which never attaches a portal token. The token in the URL is the credential.
 */
export async function getPublicQueueView(
  token: string,
  signal?: AbortSignal,
): Promise<StudentQueueView> {
  const payload = await getJson(
    `${PUBLIC_QUEUE_VIEW_PATH}/${encodeURIComponent(token)}`,
    signal,
  )
  const result = studentQueueViewEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its live queue view did not match the published v1 contract.",
    cause: result.error,
  })
}
