import {
  nullableQueueCycleEnvelopeSchema,
  queueCycleEnvelopeSchema,
  type QueueCycle,
} from "@/features/schemas/queue-cycle-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const QUEUE_CYCLE_PATH = "/api/v1/queue-cycle"

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

export async function getQueueCycle(
  signal?: AbortSignal,
): Promise<QueueCycle | null> {
  const envelope = parse(
    nullableQueueCycleEnvelopeSchema,
    await getAuthenticatedJson(QUEUE_CYCLE_PATH, signal),
    "queue cycle",
  )
  return envelope.data
}

export async function cutOffQueueCycle(): Promise<QueueCycle> {
  const envelope = parse(
    queueCycleEnvelopeSchema,
    await postAuthenticatedJson(`${QUEUE_CYCLE_PATH}/cut-off`),
    "cut-off queue cycle",
  )
  return envelope.data
}

export async function resumeQueueCycle(): Promise<QueueCycle> {
  const envelope = parse(
    queueCycleEnvelopeSchema,
    await postAuthenticatedJson(`${QUEUE_CYCLE_PATH}/resume`),
    "resume queue cycle",
  )
  return envelope.data
}
