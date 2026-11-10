import {
  lectureLabAdjacencyResponseSchema,
  type LectureLabAdjacencyViolation,
} from "@/features/schemas/lecture-lab-adjacency-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

/** The Program Head's lecture/laboratory pairs that are not back-to-back. */
export async function getLectureLabAdjacency(
  termId: number,
  signal?: AbortSignal,
): Promise<LectureLabAdjacencyViolation[]> {
  const result = lectureLabAdjacencyResponseSchema.safeParse(
    await getAuthenticatedJson(
      `/api/v1/academic-terms/${termId}/lecture-lab-adjacency`,
      signal,
    ),
  )

  if (!result.success) {
    throw new ApiClientError({
      kind: "contract",
      message:
        "The API responded, but its lecture and laboratory check did not match the published v1 contract.",
      cause: result.error,
    })
  }

  return result.data.data
}
