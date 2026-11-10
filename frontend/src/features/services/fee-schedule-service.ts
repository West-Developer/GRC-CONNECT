import {
  getAuthenticatedJson,
  putAuthenticatedJson,
} from "@/features/services/api-client"
import {
  feeSchedulesEnvelopeSchema,
  updateFeeScheduleResponseSchema,
  type FeeSchedule,
  type UpdateFeeSchedulePayload,
} from "@/features/schemas/fee-schedule-schema"

export async function getFeeSchedules(signal?: AbortSignal): Promise<FeeSchedule[]> {
  const response = await getAuthenticatedJson("/api/v1/fee-schedules", signal)
  const parsed = feeSchedulesEnvelopeSchema.parse(response)
  return parsed.data
}

export async function updateFeeSchedules(
  payload: UpdateFeeSchedulePayload,
): Promise<{ message: string; data: FeeSchedule[] }> {
  const response = await putAuthenticatedJson("/api/v1/fee-schedules", payload)
  return updateFeeScheduleResponseSchema.parse(response)
}
