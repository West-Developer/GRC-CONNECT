"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  type FacultyAccountFilters,
  type ChangeItControlPasswordInput,
  type StudentAccountFilters,
  type UpdateItControlAccountInput,
} from "@/features/schemas/it-control-schema"
import {
  archiveItControlAccount,
  changeItControlPassword,
  deleteItControlAccount,
  getItControlFacultyAccounts,
  getItControlStudentAccounts,
  restoreItControlAccount,
  updateItControlAccount,
} from "@/features/services/it-control-service"

export const itControlStudentAccountsQueryKey = (
  userId: string | null,
  filters: StudentAccountFilters,
) => ["it-control", "students", userId, filters] as const

export const itControlFacultyAccountsQueryKey = (
  userId: string | null,
  filters: FacultyAccountFilters,
) => ["it-control", "faculty", userId, filters] as const

export function useItControlStudentAccountsQuery(
  filters: StudentAccountFilters,
  enabled = true,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: itControlStudentAccountsQueryKey(
      session?.userId ?? null,
      filters,
    ),
    queryFn: ({ signal }) => getItControlStudentAccounts(filters, signal),
    enabled: enabled && session !== null,
  })
}

export function useItControlFacultyAccountsQuery(
  filters: FacultyAccountFilters,
  enabled = true,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: itControlFacultyAccountsQueryKey(
      session?.userId ?? null,
      filters,
    ),
    queryFn: ({ signal }) => getItControlFacultyAccounts(filters, signal),
    enabled: enabled && session !== null,
  })
}

/** Every change to an account re-reads both lists: an edit, archive, restore or delete moves rows between views. */
function useInvalidateItControlAccounts() {
  const queryClient = useQueryClient()

  return () => queryClient.invalidateQueries({ queryKey: ["it-control"] })
}

export function useUpdateItControlAccountMutation() {
  const invalidate = useInvalidateItControlAccounts()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: UpdateItControlAccountInput
    }) => updateItControlAccount(id, input),
    onSuccess: invalidate,
  })
}

export function useChangeItControlPasswordMutation() {
  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: ChangeItControlPasswordInput
    }) => changeItControlPassword(id, input),
    // Password-bearing mutation variables must not linger after the dialog unmounts.
    gcTime: 0,
  })
}

export function useArchiveItControlAccountMutation() {
  const invalidate = useInvalidateItControlAccounts()

  return useMutation({
    mutationFn: archiveItControlAccount,
    onSuccess: invalidate,
  })
}

export function useRestoreItControlAccountMutation() {
  const invalidate = useInvalidateItControlAccounts()

  return useMutation({
    mutationFn: restoreItControlAccount,
    onSuccess: invalidate,
  })
}

export function useDeleteItControlAccountMutation() {
  const invalidate = useInvalidateItControlAccounts()

  return useMutation({
    mutationFn: deleteItControlAccount,
    onSuccess: invalidate,
  })
}
