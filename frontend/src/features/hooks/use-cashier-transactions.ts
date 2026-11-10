"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type { CashierTransactionFilters } from "@/features/schemas/cashier-transaction-schema"
import {
  findCashierPaymentCandidate,
  listCashierTransactions,
  searchCashierStudents,
} from "@/features/services/cashier-transaction-service"

export const cashierTransactionsQueryKey = (
  userId: string | null,
  filters: CashierTransactionFilters,
) => ["cashier-transactions", userId, filters] as const

export const cashierPaymentCandidateQueryKey = (
  userId: string | null,
  studentNumber: string | null,
) => ["cashier-payment-candidate", userId, studentNumber] as const

export function useCashierTransactionsQuery(
  filters: CashierTransactionFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: cashierTransactionsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listCashierTransactions(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

export function useCashierPaymentCandidateQuery(
  studentNumber: string | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()
  const hasStudentNumber = studentNumber !== null && studentNumber.trim() !== ""

  return useQuery({
    queryKey: cashierPaymentCandidateQueryKey(
      session?.userId ?? null,
      studentNumber,
    ),
    queryFn: ({ signal }) =>
      findCashierPaymentCandidate(studentNumber ?? "", signal),
    enabled: enabled && session !== null && hasStudentNumber,
  })
}

export const cashierStudentSearchQueryKey = (
  userId: string | null,
  search: string | null,
) => ["cashier-student-search", userId, search] as const

/** The Cashier's general student search; enabled only once a search is submitted. */
export function useCashierStudentSearchQuery(
  search: string | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()
  const hasSearch = search !== null && search.trim().length >= 2

  return useQuery({
    queryKey: cashierStudentSearchQueryKey(session?.userId ?? null, search),
    queryFn: ({ signal }) => searchCashierStudents(search ?? "", signal),
    enabled: enabled && session !== null && hasSearch,
  })
}
