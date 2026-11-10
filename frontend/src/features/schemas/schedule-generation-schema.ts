import { z } from "zod"

const facultyLoadAssignmentSchema = z
  .object({
    section_id: z.number().int().positive(),
    section_code: z.string().min(1),
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    units: z.number().nonnegative(),
    professor_id: z.number().int().positive().nullable(),
    professor_name: z.string().nullable(),
    professor_employment_type: z
      .enum(["full_time", "part_time"])
      .nullable()
      .optional(),
    recommended_professor_id: z.number().int().positive().nullable(),
    rationale: z.array(z.string()),
    override_reason: z.string().nullable(),
    schedule_days: z.string().nullable(),
    starts_at_time: z.string().nullable(),
    ends_at_time: z.string().nullable(),
    room: z.string().nullable(),
    modality: z.enum(["hyflex_a", "hyflex_b", "f2f"]).nullable(),
  })
  .strict()

export const facultyEmploymentTypeSchema = z.enum(["full_time", "part_time"])

/** Which rule set a professor's maximum load (ADR 0033), highest first. */
export const facultyLoadLimitSourceSchema = z.enum([
  "override",
  "employment_type",
  "college_default",
])

const facultyLoadLimitSchema = z
  .object({
    employment_type: facultyEmploymentTypeSchema,
    label: z.string().min(1),
    max_units: z.number().positive().nullable(),
  })
  .strict()

const idleFacultySchema = z
  .object({
    professor_id: z.number().int().positive(),
    professor_name: z.string().min(1),
    employment_type: facultyEmploymentTypeSchema.nullable(),
    employment_type_label: z.string().nullable(),
    max_units: z.number().positive().nullable(),
    limit_source: facultyLoadLimitSourceSchema.nullable(),
    override: z
      .object({
        max_units: z.number().positive(),
        reason: z.string().min(1),
      })
      .strict()
      .nullable(),
  })
  .strict()

export const facultyLoadReportSchema = z
  .object({
    academic_term_id: z.number().int().positive(),
    college: z.string().min(1),
    threshold_units: z.number().positive().nullable(),
    // The maximum per employment type; a type with no number has no limit.
    limits: z.array(facultyLoadLimitSchema).optional(),
    required_teaching_units: z.number().nonnegative(),
    required_assignments: z.number().int().nonnegative(),
    equivalent_faculty_loads: z.number().int().nonnegative().nullable(),
    assigned_count: z.number().int().nonnegative(),
    unassigned_count: z.number().int().nonnegative(),
    overloaded_count: z.number().int().nonnegative(),
    faculty: z.array(
      z
        .object({
          professor_id: z.number().int().positive(),
          professor_name: z.string().nullable(),
          employment_type: facultyEmploymentTypeSchema.nullable(),
          employment_type_label: z.string().nullable(),
          total_units: z.number().nonnegative(),
          // The maximum that applies to this professor and where it came from.
          max_units: z.number().positive().nullable(),
          limit_source: facultyLoadLimitSourceSchema.nullable(),
          override: z
            .object({
              max_units: z.number().positive(),
              reason: z.string().min(1),
            })
            .strict()
            .nullable(),
          overloaded: z.boolean(),
          assignments: z.array(facultyLoadAssignmentSchema),
        })
        .strict(),
    ),
    unassigned: z.array(facultyLoadAssignmentSchema),
    // Professors of the college with no section this term, so a Dean can see
    // who still has room.
    idle_faculty: z.array(idleFacultySchema).optional(),
  })
  .strict()

const forecastSchema = z
  .object({
    subject_id: z.number().int().positive(),
    subject_code: z.string().min(1),
    subject_title: z.string().min(1),
    curriculum_id: z.number().int().positive().nullable().optional(),
    curriculum_name: z.string().min(1).nullable().optional(),
    curriculum_effective_school_year: z.string().min(1).nullable().optional(),
    year_level: z.number().int().positive().nullable(),
    predicted_demand: z.number().nonnegative(),
    suggested_section_count: z.number().int().nonnegative(),
    confidence_lower: z.number().nonnegative().nullable(),
    confidence_upper: z.number().nonnegative().nullable(),
    historical_basis: z
      .object({
        school_year: z.string().nullable(),
        semester: z.string().nullable(),
        year_level: z.number().int().positive().nullable(),
      })
      .strict(),
    rationale: z.record(z.string(), z.unknown()),
  })
  .strict()

const scheduleGenerationWarningSchema = z
  .object({
    type: z.string().min(1),
    message: z.string().min(1),
    entity_id: z.number().int().positive().nullable(),
  })
  .strict()

export const scheduleGenerationRunSchema = z
  .object({
    type: z.literal("schedule_generation_run"),
    id: z.number().int().positive(),
    academic_term_id: z.number().int().positive(),
    prediction_run_id: z.number().int().positive().nullable(),
    college: z.string().min(1),
    status: z.enum(["queued", "running", "succeeded", "partial", "failed"]),
    warnings: z.array(scheduleGenerationWarningSchema),
    error_summary: z.string().nullable(),
    started_at: z.string().nullable(),
    completed_at: z.string().nullable(),
    created_at: z.string().nullable(),
    model: z
      .object({
        strategy: z.string().min(1).nullable(),
        model_version: z.string().nullable(),
        training_observation_count: z.number().int().nonnegative(),
        mae: z.number().nonnegative().nullable(),
        rmse: z.number().nonnegative().nullable(),
      })
      .strict()
      .optional(),
    forecasts: z.array(forecastSchema).optional(),
    section_block_recommendations: z
      .array(
        z
          .object({
            program_code: z.string().min(1),
            program_name: z.string().min(1),
            curriculum_id: z.number().int().positive().optional(),
            curriculum_name: z.string().min(1).optional(),
            curriculum_effective_school_year: z.string().min(1).optional(),
            year_level: z.number().int().positive(),
            recommended_block_sections: z.number().int().nonnegative(),
            students_per_block: z.number().int().positive(),
            is_overridden: z.boolean(),
          })
          .strict(),
      )
      .optional(),
    faculty_load: facultyLoadReportSchema.optional(),
  })
  .strict()

export const scheduleGenerationRunEnvelopeSchema = z
  .object({ data: scheduleGenerationRunSchema })
  .strict()

export type ScheduleGenerationRun = z.infer<typeof scheduleGenerationRunSchema>
export type FacultyLoadReport = z.infer<typeof facultyLoadReportSchema>
export type FacultyLoadMember = FacultyLoadReport["faculty"][number]
export type IdleFacultyMember = NonNullable<
  FacultyLoadReport["idle_faculty"]
>[number]
export type FacultyEmploymentType = z.infer<typeof facultyEmploymentTypeSchema>
export type FacultyLoadLimitSource = z.infer<
  typeof facultyLoadLimitSourceSchema
>
export type ScheduleGenerationWarning = z.infer<
  typeof scheduleGenerationWarningSchema
>
