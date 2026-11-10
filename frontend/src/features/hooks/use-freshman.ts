"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import type {
  ConfirmFreshmanPaymentInput,
} from "@/features/schemas/freshman-schema"
import {
  confirmFreshmanPayment,
  getFreshmanPaymentPreview,
} from "@/features/services/freshman-service"

export const freshmanPaymentPreviewQueryKey = (
  userId: string | null,
  studentId: number | null,
) => ["freshman-payment-preview", userId, studentId] as const

/** What the Cashier is about to do for this freshman; read when the payment dialog opens. */
export function useFreshmanPaymentPreviewQuery(
  studentId: number | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: freshmanPaymentPreviewQueryKey(
      session?.userId ?? null,
      studentId,
    ),
    queryFn: ({ signal }) => getFreshmanPaymentPreview(studentId!, signal),
    enabled:
      enabled && studentId !== null && session?.role === "accounting_staff",
    // The seats can change while the Cashier talks to the freshman, so the preview is re-read
    // each time the dialog opens and kept fresh while it stays open.
    staleTime: 0,
    refetchInterval: 5_000,
  })
}

export function useConfirmFreshmanPaymentMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const userId = session?.userId ?? null

  return useMutation({
    mutationFn: ({
      studentId,
      input,
    }: {
      studentId: number
      input: ConfirmFreshmanPaymentInput
    }) => confirmFreshmanPayment(studentId, input),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: ["queue-tickets", userId] }),
        queryClient.invalidateQueries({
          queryKey: ["freshman-payment-preview", userId],
        }),
        queryClient.invalidateQueries({ queryKey: ["enrollments", userId] }),
        queryClient.invalidateQueries({ queryKey: ["cashier-transactions"] }),
      ]),
  })
}
