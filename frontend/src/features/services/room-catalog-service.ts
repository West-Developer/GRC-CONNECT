import { z } from "zod"

import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const ROOM_OPTIONS_PATH = "/api/v1/room-options"

const roomOptionSchema = z
  .object({
    type: z.literal("room_option"),
    id: z.number().int().positive(),
    name: z.string().trim().min(1),
  })
  .strict()
const roomOptionsEnvelopeSchema = z
  .object({ data: z.array(roomOptionSchema) })
  .strict()

export type RoomOption = z.infer<typeof roomOptionSchema>

// Mirrors `App\Domain\Organization\RoomCatalog::forCollege()` — LAB 1-4, 2A,
// 3A-3G, 4A-4E, and 5A-5G are shared campus rooms available to every
// college; every other room keeps its narrower, pre-existing mapping.
const localRoomNamesByCollege = {
  ccs: [
    "2A",
    "3A",
    "3B",
    "3C",
    "3D",
    "3E",
    "3F",
    "3G",
    "4A",
    "4B",
    "4C",
    "4D",
    "4E",
    "5A",
    "5B",
    "5C",
    "5D",
    "5E",
    "5F",
    "5G",
    "5H",
    "LAB 1",
    "LAB 2",
    "LAB 3",
    "LAB 4",
    "PE ROOM",
    "TESDA HALL",
  ],
  coe: [
    "2A",
    "3A",
    "3B",
    "3C",
    "3D",
    "3E",
    "3F",
    "3G",
    "3H",
    "4A",
    "4B",
    "4C",
    "4D",
    "4E",
    "4F",
    "4G",
    "4H",
    "5A",
    "5B",
    "5C",
    "5D",
    "5E",
    "5F",
    "5G",
    "5H",
    "LAB 1",
    "LAB 2",
    "LAB 3",
    "LAB 4",
    "EDTECH ROOM",
    "PE ROOM",
    "PE ROOM 2",
    "ROOM 1",
    "SCI LAB",
    "STUDY HALL",
  ],
  coa: [
    "2A",
    "3A",
    "3B",
    "3C",
    "3D",
    "3E",
    "3F",
    "3G",
    "3H",
    "4A",
    "4B",
    "4C",
    "4D",
    "4E",
    "5A",
    "5B",
    "5C",
    "5D",
    "5E",
    "5F",
    "5G",
    "LAB 1",
    "LAB 2",
    "LAB 3",
    "LAB 4",
    "PE ROOM",
    "SCI LAB",
    "STUDY HALL",
  ],
  cbae: [
    "2A",
    "3A",
    "3B",
    "3C",
    "3D",
    "3E",
    "3F",
    "3G",
    "3H",
    "4A",
    "4B",
    "4C",
    "4D",
    "4E",
    "4F",
    "4G",
    "4H",
    "5A",
    "5B",
    "5C",
    "5D",
    "5E",
    "5F",
    "5G",
    "LAB 1",
    "LAB 2",
    "LAB 3",
    "LAB 4",
    "COM LAB 2",
    "COM LAB 3",
    "COM LAB 4",
    "PE ROOM",
    "ROOM 1",
    "SCI LAB",
    "STUDY HALL",
  ],
} as const

export function getLocalRoomOptions(
  college: string | null | undefined,
): readonly RoomOption[] {
  const names =
    localRoomNamesByCollege[college as keyof typeof localRoomNamesByCollege] ??
    []
  return names.map((name, index) => ({
    type: "room_option",
    id: -(index + 1),
    name,
  }))
}

export async function getRoomOptions(
  signal?: AbortSignal,
): Promise<readonly RoomOption[]> {
  const payload = await getAuthenticatedJson(ROOM_OPTIONS_PATH, signal)
  const result = roomOptionsEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its room options did not match the published v1 contract.",
    cause: result.error,
  })
}
