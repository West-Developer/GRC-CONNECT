import {
  roomOccupancyEnvelopeSchema,
  roomOccupancySummaryEnvelopeSchema,
  type RoomOccupancyEntry,
  type RoomOccupancySummaryEntry,
} from "@/features/schemas/room-occupancy-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const ROOM_OCCUPANCY_PATH = "/api/v1/room-occupancy"

export async function getRoomOccupancy(
  room: string,
  academicTermId: number,
  signal?: AbortSignal,
): Promise<readonly RoomOccupancyEntry[]> {
  const query = new URLSearchParams({
    room,
    academic_term_id: String(academicTermId),
  })
  const payload = await getAuthenticatedJson(
    `${ROOM_OCCUPANCY_PATH}?${query.toString()}`,
    signal,
  )
  const result = roomOccupancyEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its room occupancy did not match the published v1 contract.",
    cause: result.error,
  })
}

/** Every room's class count and days used this term, in one request. */
export async function getRoomOccupancySummary(
  academicTermId: number,
  signal?: AbortSignal,
): Promise<readonly RoomOccupancySummaryEntry[]> {
  const payload = await getAuthenticatedJson(
    `/api/v1/room-occupancy-summary?academic_term_id=${academicTermId}`,
    signal,
  )
  const result = roomOccupancySummaryEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its room occupancy summary did not match the published v1 contract.",
    cause: result.error,
  })
}
