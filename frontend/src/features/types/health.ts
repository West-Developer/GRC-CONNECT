import type { z } from "zod"

import type { healthEnvelopeSchema } from "@/features/schemas/health-schema"

export type HealthEnvelope = z.infer<typeof healthEnvelopeSchema>
export type ServiceHealth = HealthEnvelope["data"]
