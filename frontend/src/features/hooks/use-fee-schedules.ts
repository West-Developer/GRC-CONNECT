"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { useAuth } from "@/features/auth/use-auth"
import {
  getFeeSchedules,
  updateFeeSchedules,
} from "@/features/services/fee-schedule-service"
import type { UpdateFeeSchedulePayload } from "@/features/schemas/fee-schedule-schema"

export const feeSchedulesQueryKey = (userId: string | null) =>
  ["fee-schedules", userId] as const

export function useFeeSchedulesQuery(enabled = true) {
  const { session } = useAuth()
  return useQuery({
    queryKey: feeSchedulesQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getFeeSchedules(signal),
    enabled: enabled && session !== null,
  })
}

export function useUpdateFeeSchedulesMutation() {
  const queryClient = useQueryClient()
  const { session } = useAuth()

  return useMutation({
    mutationFn: (payload: UpdateFeeSchedulePayload) => updateFeeSchedules(payload),
    onSuccess: (data) => {
      queryClient.setQueryData(
        feeSchedulesQueryKey(session?.userId ?? null),
        data.data,
      )
      void queryClient.invalidateQueries({ queryKey: ["fee-schedules"] })
      void queryClient.invalidateQueries({ queryKey: ["enrollment-documents"] })
      void queryClient.invalidateQueries({ queryKey: ["certificate-of-registration"] })
    },
  })
}
