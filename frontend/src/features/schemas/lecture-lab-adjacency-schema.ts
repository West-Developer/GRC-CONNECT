import { z } from "zod"

const sectionRefSchema = z
  .object({
    section_id: z.number().int().positive(),
    subject_code: z.string().min(1),
  })
  .strict()

export const lectureLabAdjacencyViolationSchema = z
  .object({
    section_code: z.string().min(1),
    first: sectionRefSchema,
    second: sectionRefSchema,
    reason: z.enum(["no_shared_day", "not_back_to_back"]),
    days: z.array(z.string()),
    message: z.string().min(1),
  })
  .strict()

export const lectureLabAdjacencyResponseSchema = z
  .object({ data: z.array(lectureLabAdjacencyViolationSchema) })
  .strict()

export type LectureLabAdjacencyViolation = z.infer<
  typeof lectureLabAdjacencyViolationSchema
>
