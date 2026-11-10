import {
  paginatedQueueTicketsSchema,
  queueTicketEnvelopeSchema,
  queueTicketFiltersSchema,
  updateQueueTicketInputSchema,
  type Paginated,
  type QueueTicket,
  type QueueTicketFilters,
  type UpdateQueueTicketInput,
} from "@/features/schemas/queue-ticket-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
  postAuthenticatedJson,
} from "@/features/services/api-client"

export const QUEUE_TICKETS_PATH = "/api/v1/queue-tickets"

export interface KioskClaimCredentials {
  studentToken: string
  kioskToken: string
}

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

export async function listQueueTickets(
  filters: QueueTicketFilters,
  signal?: AbortSignal,
): Promise<Paginated<QueueTicket>> {
  const parsed = parse(queueTicketFiltersSchema, filters, "queue filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedQueueTicketsSchema,
    await getAuthenticatedJson(
      `${QUEUE_TICKETS_PATH}?${query.toString()}`,
      signal,
    ),
    "queue ticket list",
  )
}

export async function updateQueueTicket(
  id: number,
  input: UpdateQueueTicketInput,
): Promise<QueueTicket> {
  const payload = await patchAuthenticatedJson(
    `${QUEUE_TICKETS_PATH}/${id}`,
    parse(updateQueueTicketInputSchema, input, "queue ticket transition"),
  )
  return parse(queueTicketEnvelopeSchema, payload, "updated queue ticket").data
}

export async function claimQueueTicket(
  studentNumber?: string,
  kiosk?: KioskClaimCredentials,
  signal?: AbortSignal,
): Promise<QueueTicket> {
  const payload = await postAuthenticatedJson(
    QUEUE_TICKETS_PATH,
    studentNumber ? { student_number: studentNumber } : undefined,
    signal,
    kiosk
      ? {
          token: kiosk.studentToken,
          headers: { "X-Queue-Kiosk-Token": kiosk.kioskToken },
          suppressUnauthorizedHandler: true,
        }
      : undefined,
  )
  return parse(queueTicketEnvelopeSchema, payload, "claimed queue ticket").data
}
