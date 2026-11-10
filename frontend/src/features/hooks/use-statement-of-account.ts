"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { getStatementOfAccount } from "@/features/services/statement-of-account-service"

export const statementOfAccountQueryKey = (
  userId: string | null,
  studentId: number | null,
  termId: number | null,
) => ["statement-of-account", userId, studentId, termId] as const

/** `studentId` null = the signed-in Student's own; a number = Accounting Staff's served Student. */
export function useStatementOfAccountQuery(
  studentId: number | null,
  termId: number | null,
  enabled = true,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: statementOfAccountQueryKey(
      session?.userId ?? null,
      studentId,
      termId,
    ),
    queryFn: ({ signal }) => getStatementOfAccount(studentId, termId, signal),
    enabled: enabled && session !== null,
  })
}
