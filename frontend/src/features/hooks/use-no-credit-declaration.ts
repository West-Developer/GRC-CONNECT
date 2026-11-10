"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  confirmNoCreditDeclaration,
  declareNoCredit,
  getMyNoCreditDeclaration,
  listNoCreditDeclarations,
} from "@/features/services/no-credit-declaration-service"

export const myNoCreditDeclarationQueryKey = (userId: string | null) =>
  ["no-credit-declaration", userId] as const

export const noCreditDeclarationsQueryKey = (userId: string | null) =>
  ["no-credit-declarations", userId] as const

/** The student's own "no subject to credit" (null when they have made none). Only a Student reads it. */
export function useMyNoCreditDeclarationQuery({
  enabled = true,
}: { enabled?: boolean } = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: myNoCreditDeclarationQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getMyNoCreditDeclaration(signal),
    enabled: enabled && session?.role === "student",
  })
}

/** The declarations waiting for the Program Chair. */
export function useNoCreditDeclarationsQuery({
  enabled = true,
}: { enabled?: boolean } = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: noCreditDeclarationsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => listNoCreditDeclarations(signal),
    enabled: enabled && session?.role === "program_chair",
    refetchInterval: 30_000,
  })
}

export function useDeclareNoCreditMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => declareNoCredit(),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({
          queryKey: myNoCreditDeclarationQueryKey(session?.userId ?? null),
        }),
        // The student's eligible subjects carry the reason they are held back.
        queryClient.invalidateQueries({
          queryKey: ["eligible-subjects", session?.userId ?? null],
        }),
      ]),
  })
}

export function useConfirmNoCreditDeclarationMutation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => confirmNoCreditDeclaration(id),
    onSuccess: () =>
      Promise.all([
        queryClient.invalidateQueries({ queryKey: ["no-credit-declarations"] }),
        // The number on the Program Chair's Credit Mappings tab.
        queryClient.invalidateQueries({
          queryKey: ["program-chair-review-counts"],
        }),
      ]),
  })
}
