"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type { UpdatePaymentDiscountInput } from "@/features/schemas/payment-discount-schema"
import {
  getPaymentDiscountSetting,
  updatePaymentDiscountSetting,
} from "@/features/services/payment-discount-service"

export const paymentDiscountQueryKey = (userId: string | null) =>
  ["payment-discount", userId] as const

export function usePaymentDiscountQuery(enabled = true) {
  const { session } = useAuth()

  return useQuery({
    queryKey: paymentDiscountQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getPaymentDiscountSetting(signal),
    enabled: enabled && session !== null,
  })
}

export function useUpdatePaymentDiscountMutation() {
  const queryClient = useQueryClient()
  const { session } = useAuth()

  return useMutation({
    mutationFn: (input: UpdatePaymentDiscountInput) =>
      updatePaymentDiscountSetting(input),
    onSuccess: (saved) => {
      queryClient.setQueryData(
        paymentDiscountQueryKey(session?.userId ?? null),
        saved,
      )
      // The Cashier's quote depends on this setting.
      void queryClient.invalidateQueries({ queryKey: ["payment-quote"] })
    },
  })
}
