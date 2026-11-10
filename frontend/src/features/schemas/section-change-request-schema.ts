import { z } from "zod"

/** Mirrors `SectionChangeRequestResource` (ADR 0032). */
export const sectionChangeValueSchema = z
  .union([z.string(), z.number(), z.null()])
  .nullable()

export const sectionChangeRequestStatusSchema = z.enum([
  "pending",
  "approved",
  "rejected",
  "cancelled",
])

export const sectionChangeRequestSchema = z
  .object({
    type: z.literal("section_change_request"),
    id: z.number().int().positive(),
    section_id: z.number().int().positive(),
    section_code: z.string().min(1),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    academic_term_id: z.number().int().positive(),
    status: sectionChangeRequestStatusSchema,
    status_label: z.string().min(1),
    reason: z.string().min(1),
    requested_by_name: z.string().min(1),
    changes: z.array(
      z
        .object({
          field: z.string().min(1),
          label: z.string().min(1),
          old: sectionChangeValueSchema,
          new: sectionChangeValueSchema,
        })
        .strict(),
    ),
    decided_by_name: z.string().nullable(),
    decided_at: z.iso.datetime().nullable(),
    decision_reason: z.string().nullable(),
    created_at: z.iso.datetime().nullable(),
  })
  .strict()

export const sectionChangeRequestEnvelopeSchema = z
  .object({ data: sectionChangeRequestSchema })
  .strict()

export const sectionChangeRequestsEnvelopeSchema = z
  .object({ data: z.array(sectionChangeRequestSchema) })
  .strict()

/** The fields a change request may carry; anything else is refused. */
export const sectionChangeFieldsSchema = z
  .object({
    schedule_days: z.string().nullable().optional(),
    starts_at_time: z.string().nullable().optional(),
    ends_at_time: z.string().nullable().optional(),
    room: z.string().nullable().optional(),
    modality: z.string().nullable().optional(),
    capacity: z.number().int().min(1).optional(),
    viability_threshold: z.number().int().min(1).nullable().optional(),
  })
  .strict()

export const createSectionChangeRequestInputSchema = z
  .object({
    reason: z
      .string()
      .trim()
      .min(
        3,
        "Explain why the schedule needs to change (at least 3 characters).",
      )
      .max(1000),
    changes: sectionChangeFieldsSchema.refine(
      (changes) => Object.keys(changes).length > 0,
      "Change at least one schedule detail.",
    ),
  })
  .strict()

export const decideSectionChangeRequestInputSchema = z
  .object({
    action: z.enum(["approve", "reject", "cancel"]),
    decision_reason: z.string().trim().max(1000).optional(),
  })
  .strict()
  .refine(
    (input) =>
      input.action !== "reject" || (input.decision_reason ?? "").length >= 3,
    {
      message: "Give the Program Head a reason for turning it down.",
      path: ["decision_reason"],
    },
  )

export type SectionChangeRequest = z.infer<typeof sectionChangeRequestSchema>
export type SectionChangeRequestStatus = z.infer<
  typeof sectionChangeRequestStatusSchema
>
export type SectionChangeFields = z.infer<typeof sectionChangeFieldsSchema>
export type CreateSectionChangeRequestInput = z.infer<
  typeof createSectionChangeRequestInputSchema
>
export type DecideSectionChangeRequestInput = z.infer<
  typeof decideSectionChangeRequestInputSchema
>
