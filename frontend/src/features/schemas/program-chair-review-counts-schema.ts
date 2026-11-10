import { z } from "zod"

/**
 * What is waiting for the Program Chair, for the numbers on the Enrollment page's tabs:
 * the irregular enrollments to check, and the students whose credit mapping (their credits,
 * or a "no subject to credit") is still with the Chair.
 */
export const programChairReviewCountsSchema = z
  .object({
    type: z.literal("program_chair_review_counts"),
    irregular_advising: z.number().int().nonnegative(),
    credit_mappings: z.number().int().nonnegative(),
  })
  .strict()

export const programChairReviewCountsEnvelopeSchema = z
  .object({ data: programChairReviewCountsSchema })
  .strict()

export type ProgramChairReviewCounts = z.infer<
  typeof programChairReviewCountsSchema
>
