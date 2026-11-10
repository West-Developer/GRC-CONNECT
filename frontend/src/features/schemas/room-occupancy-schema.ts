import { z } from "zod"

export const roomOccupancyEntrySchema = z
  .object({
    type: z.literal("room_occupancy"),
    section_id: z.number().int().positive(),
    section_code: z.string().min(1),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    professor_name: z.string().nullable(),
    schedule_days: z.string().nullable(),
    starts_at_time: z.string().nullable(),
    ends_at_time: z.string().nullable(),
    modality: z.enum(["hyflex_a", "hyflex_b", "f2f"]).nullable(),
    college: z.string().nullable(),
    is_own_college: z.boolean(),
    is_lecture_component: z.boolean(),
    is_section_overlay: z.boolean().optional(),
    room: z.string().nullable().optional(),
  })
  .strict()

export const roomOccupancyEnvelopeSchema = z
  .object({ data: z.array(roomOccupancyEntrySchema) })
  .strict()

// One room's use this term, for the Rooms screen tiles (stakeholder Doc 14).
// A room nobody has a class in is simply not in the list.
export const roomOccupancySummaryEnvelopeSchema = z
  .object({
    data: z.array(
      z
        .object({
          room: z.string().min(1),
          classes_count: z.number().int().positive(),
          // ISO weekdays, 1 = Monday .. 7 = Sunday.
          days: z.array(z.number().int().min(1).max(7)),
        })
        .strict(),
    ),
  })
  .strict()

export type RoomOccupancyEntry = z.infer<typeof roomOccupancyEntrySchema>
export type RoomOccupancySummaryEntry = z.infer<
  typeof roomOccupancySummaryEnvelopeSchema
>["data"][number]
