"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type {
  CreateTransfereeCreditInput,
  DecideTransfereeCreditInput,
  TransfereeCredit,
  TransfereeCreditActionInput,
  TransfereeCreditFilters,
  UpdateTransfereeCreditInput,
} from "@/features/schemas/transferee-credit-schema"
import {
  actOnTransfereeCredit,
  approveAllStudentCredits,
  createTransfereeCredit,
  decideTransfereeCredit,
  deleteTransfereeCredit,
  getCreditMappingWorkspace,
  getTransfereeCreditSuggestions,
  listTransfereeCredits,
  submitCreditsToRegistrarHead,
  submitTransfereeCredits,
  updateTransfereeCredit,
} from "@/features/services/transferee-credit-service"

export const transfereeCreditsQueryKey = (
  userId: string | null,
  filters: TransfereeCreditFilters,
) => ["transferee-credits", userId, filters] as const

export const transfereeCreditSuggestionsQueryKey = (
  userId: string | null,
  creditId: number | null,
) => ["transferee-credit-suggestions", userId, creditId] as const

export function useTransfereeCreditsQuery(
  filters: TransfereeCreditFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: transfereeCreditsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listTransfereeCredits(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

// The API serves at most 100 rows a page (`IndexTransfereeCreditRequest`). A
// transferee with a full transcript has dozens of rows, so a queue read once
// would hide students. 50 pages (5,000 rows) is a stop against a runaway loop,
// far beyond any real queue.
const CREDIT_PAGE_SIZE = 100
const MAX_CREDIT_PAGES = 50

/**
 * Every credit that matches `filters`, read page by page until the last one.
 * The Program Chair and Registrar Head queues group these by student, so they
 * need all the rows, not one page of them. Shares the `transferee-credits`
 * prefix, so every credit mutation refreshes it.
 */
export function useAllTransfereeCreditsQuery(
  filters: Omit<TransfereeCreditFilters, "page" | "per_page">,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: transfereeCreditsQueryKey(session?.userId ?? null, {
      ...filters,
      all_pages: true,
    } as TransfereeCreditFilters),
    queryFn: async ({ signal }): Promise<TransfereeCredit[]> => {
      const rows: TransfereeCredit[] = []
      for (let page = 1; page <= MAX_CREDIT_PAGES; page += 1) {
        const result = await listTransfereeCredits(
          { ...filters, page, per_page: CREDIT_PAGE_SIZE },
          signal,
        )
        rows.push(...result.data)
        if (page >= result.meta.last_page) break
      }
      return rows
    },
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

/** Suggested GRC subjects for one credit; fetched only while it is being reviewed. */
export function useTransfereeCreditSuggestionsQuery(
  creditId: number | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: transfereeCreditSuggestionsQueryKey(
      session?.userId ?? null,
      creditId,
    ),
    queryFn: ({ signal }) => getTransfereeCreditSuggestions(creditId!, signal),
    enabled: enabled && session !== null && creditId !== null,
  })
}

function useInvalidateTransfereeCreditQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({
        queryKey: ["transferee-credits", session?.userId ?? null],
      }),
      // The number on the Program Chair's Credit Mappings tab.
      queryClient.invalidateQueries({
        queryKey: ["program-chair-review-counts"],
      }),
      // Saving a credit replaces a "no subject to credit" that was not confirmed yet.
      queryClient.invalidateQueries({ queryKey: ["no-credit-declaration"] }),
    ])
}

export function useCreateTransfereeCreditMutation() {
  const invalidate = useInvalidateTransfereeCreditQueries()

  return useMutation({
    mutationFn: (input: CreateTransfereeCreditInput) =>
      createTransfereeCredit(input),
    onSuccess: () => invalidate(),
  })
}

/** The Program Chair's corrections and mapping of a pending credit. */
export function useUpdateTransfereeCreditMutation() {
  const invalidate = useInvalidateTransfereeCreditQueries()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: UpdateTransfereeCreditInput
    }) => updateTransfereeCredit(id, input),
    onSuccess: () => invalidate(),
  })
}

/** Endorse / decline (Program Chair) or approve / reject / return (Registrar Head). */
export function useTransfereeCreditActionMutation() {
  const invalidate = useInvalidateTransfereeCreditQueries()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: TransfereeCreditActionInput
    }) => actOnTransfereeCredit(id, input),
    onSuccess: () => invalidate(),
  })
}

/** The Registrar Head's decision on an endorsed credit. */
export function useDecideTransfereeCreditMutation() {
  const invalidate = useInvalidateTransfereeCreditQueries()

  return useMutation({
    mutationFn: ({
      id,
      input,
    }: {
      id: number
      input: DecideTransfereeCreditInput
    }) => decideTransfereeCredit(id, input),
    onSuccess: () => invalidate(),
  })
}

/** The student sends their whole saved list to the Program Chair. */
export function useSubmitTransfereeCreditsMutation() {
  const invalidate = useInvalidateTransfereeCreditQueries()

  return useMutation({
    mutationFn: () => submitTransfereeCredits(),
    onSuccess: () => invalidate(),
  })
}

/** The student removes one subject from their own saved list. */
export function useDeleteTransfereeCreditMutation() {
  const invalidate = useInvalidateTransfereeCreditQueries()

  return useMutation({
    mutationFn: (id: number) => deleteTransfereeCredit(id),
    onSuccess: () => invalidate(),
  })
}

export const creditMappingWorkspaceQueryKey = (
  userId: string | null,
  studentId: number | null,
) => ["credit-mapping-workspace", userId, studentId] as const

/**
 * The Program Chair's side-by-side data for one student (suggestions per
 * pending credit, and the subjects of the student's curriculum). Fetched only
 * while that student is open. Single-row saves do not refetch it on purpose:
 * the suggestions of the other rows would shift while the Chair is working.
 */
export function useCreditMappingWorkspaceQuery(
  studentId: number | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: creditMappingWorkspaceQueryKey(
      session?.userId ?? null,
      studentId,
    ),
    queryFn: ({ signal }) => getCreditMappingWorkspace(studentId!, signal),
    enabled: enabled && session !== null && studentId !== null,
  })
}

function useInvalidateStudentCreditQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({
        queryKey: ["transferee-credits", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["credit-mapping-workspace", session?.userId ?? null],
      }),
      // The number on the Program Chair's Credit Mappings tab.
      queryClient.invalidateQueries({
        queryKey: ["program-chair-review-counts"],
      }),
    ])
}

/** The Program Chair sends a whole student to the Registrar Head. */
export function useSubmitCreditsToRegistrarHeadMutation() {
  const invalidate = useInvalidateStudentCreditQueries()

  return useMutation({
    mutationFn: (studentId: number) => submitCreditsToRegistrarHead(studentId),
    onSuccess: () => invalidate(),
  })
}

/** The Registrar Head approves every endorsed credit of one student. */
export function useApproveAllStudentCreditsMutation() {
  const invalidate = useInvalidateStudentCreditQueries()

  return useMutation({
    mutationFn: (studentId: number) => approveAllStudentCredits(studentId),
    onSuccess: () => invalidate(),
  })
}
