import {
  studentQueueViewEnvelopeSchema,
  type StudentQueueView,
} from "@/features/schemas/student-queue-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
} from "@/features/services/api-client"

export const STUDENT_QUEUE_PATH = "/api/v1/queue-status"

export async function getStudentQueueView(
  signal?: AbortSignal,
  token?: string,
): Promise<StudentQueueView> {
  const payload = await getAuthenticatedJson(STUDENT_QUEUE_PATH, signal, {
    token,
    suppressUnauthorizedHandler: token !== undefined,
  })
  const result = studentQueueViewEnvelopeSchema.safeParse(payload)

  if (result.success) return result.data.data

  throw new ApiClientError({
    kind: "contract",
    message:
      "The API responded, but its Student queue view did not match the published v1 contract.",
    cause: result.error,
  })
}
