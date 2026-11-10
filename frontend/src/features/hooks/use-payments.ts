"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type { PaymentFilters } from "@/features/schemas/payment-schema"
import { listPayments } from "@/features/services/payment-service"

export const paymentsQueryKey = (
  userId: string | null,
  filters: PaymentFilters,
) => ["payments", userId, filters] as const

export function usePaymentsQuery(
  filters: PaymentFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: paymentsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listPayments(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}
