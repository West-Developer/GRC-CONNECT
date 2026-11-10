import { healthEnvelopeSchema } from "@/features/schemas/health-schema"
import { ApiClientError, getJson } from "@/features/services/api-client"
import type { ServiceHealth } from "@/features/types/health"

export const PUBLIC_API_HEALTH_PATH = "/api/v1/health"

interface GetPublicApiHealthOptions {
  signal?: AbortSignal
}

export async function getPublicApiHealth({
  signal,
}: GetPublicApiHealthOptions = {}): Promise<ServiceHealth> {
  const payload = await getJson(PUBLIC_API_HEALTH_PATH, signal)
  const parsedHealth = healthEnvelopeSchema.safeParse(payload)

  if (!parsedHealth.success) {
    throw new ApiClientError({
      kind: "contract",
      message:
        "The API responded, but its health payload did not match the published v1 contract.",
      cause: parsedHealth.error,
    })
  }

  return parsedHealth.data.data
}
