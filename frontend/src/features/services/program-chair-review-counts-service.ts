import {
  programChairReviewCountsEnvelopeSchema,
  type ProgramChairReviewCounts,
} from "@/features/schemas/program-chair-review-counts-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const PROGRAM_CHAIR_REVIEW_COUNTS_PATH =
  "/api/v1/program-chair/review-counts"

/** How many irregular enrollments and credit mappings are waiting for the signed-in Program Chair. */
export async function getProgramChairReviewCounts(
  signal?: AbortSignal,
): Promise<ProgramChairReviewCounts> {
  const result = programChairReviewCountsEnvelopeSchema.safeParse(
    await getAuthenticatedJson(PROGRAM_CHAIR_REVIEW_COUNTS_PATH, signal),
  )
  if (result.success) return result.data.data
  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its review counts did not match the published v1 contract.",
    cause: result.error,
  })
}
