"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type {
  EnrollmentFilters,
  ScholarshipPercentage,
} from "@/features/schemas/enrollment-schema"
import {
  applyScholarshipDiscount,
  confirmPayment,
  adjustEnrollmentAssessment,
  getCorPreview,
  getEligibleSubjects,
  getEnrollmentBlocks,
  getEnrollments,
  getPaymentQuote,
  listEnrollments,
  removeScholarshipDiscount,
  reviseEnrollmentSubjects,
  updateEnrollment,
} from "@/features/services/enrollment-service"

export const eligibleSubjectsQueryKey = (
  userId: string | null,
  academicTermId: number | null,
  enrollmentId: number | null = null,
) =>
  enrollmentId === null
    ? (["eligible-subjects", userId, academicTermId] as const)
    : (["eligible-subjects", userId, academicTermId, enrollmentId] as const)

/**
 * Polls every 15s (Stakeholder Doc 17) so a seat another student just took
 * (or a slot that opened up after a cancellation) shows up while a student
 * is actively choosing sections, without them having to reload. Scarcity
 * here is time-sensitive enough to warrant the same tier as the role-scoped
 * staff queues (`useEnrollmentsListQuery`), but it is read-heavy browsing
 * rather than an alert, so it does not need the 3-5s alert tier used for
 * queue-call sounds. Paused in background tabs by default
 * (`refetchIntervalInBackground` is not set).
 */
export function useEligibleSubjectsQuery(
  academicTermId: number | null,
  enrollmentId: number | null = null,
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: eligibleSubjectsQueryKey(
      session?.userId ?? null,
      academicTermId,
      enrollmentId,
    ),
    queryFn: ({ signal }) =>
      getEligibleSubjects(academicTermId!, signal, enrollmentId ?? undefined),
    enabled: session !== null && academicTermId !== null,
    refetchInterval: 15_000,
    refetchIntervalInBackground: false,
  })
}

export const enrollmentBlocksQueryKey = (
  userId: string | null,
  academicTermId: number | null,
) => ["enrollment-blocks", userId, academicTermId] as const

/**
 * How often the regular-student section picker re-reads the blocks. The
 * picker shows ONE section at a time and its open-seat count must visibly
 * drop as other students enroll, so this follows the same 3s tier as the
 * student's live queue view (ADR 0029).
 */
export const ENROLLMENT_BLOCKS_POLL_MS = 3_000

/**
 * The regular-student block picker. A student's submission reserves its seats
 * immediately (and a rejection or void releases them), so the open-seat badge
 * — and which single section is on offer — must follow that quickly: polled
 * every `ENROLLMENT_BLOCKS_POLL_MS`, and re-read on returning to the tab/app
 * (a phone browser that was in the background). There is no WebSocket/SSE
 * push in this stack — short polling is the deliberate stand-in (see
 * `useEnrollmentsQuery`).
 */
export function useEnrollmentBlocksQuery(academicTermId: number | null) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentBlocksQueryKey(session?.userId ?? null, academicTermId),
    queryFn: ({ signal }) => getEnrollmentBlocks(academicTermId!, signal),
    enabled: session !== null && academicTermId !== null,
    refetchInterval: ENROLLMENT_BLOCKS_POLL_MS,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: "always",
  })
}

export const enrollmentsQueryKey = (userId: string | null) =>
  ["enrollments", userId] as const

/**
 * Polls every 10s so a Registrar Staff decision, a Cashier payment
 * confirmation, or a queue-position change shows up on the student's own
 * Enrollment page without them having to refresh — there is no
 * WebSocket/SSE push in this stack, so short polling is the deliberate
 * stand-in. TanStack Query only polls while a component actually observes
 * this query and pauses in a backgrounded tab (`refetchIntervalInBackground`
 * defaults to `false`), so this costs nothing when nobody is looking at
 * the page.
 */
export function useEnrollmentsQuery({
  enabled = true,
}: { enabled?: boolean } = {}) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getEnrollments(signal),
    enabled: enabled && session !== null,
    refetchInterval: 10_000,
  })
}

/**
 * The Registrar Head approval queue and the Accounting payment queue: a
 * role-scoped, filterable, paginated view distinct from
 * `useEnrollmentsQuery`'s own-record student list.
 */
export const enrollmentsListQueryKey = (
  userId: string | null,
  filters: EnrollmentFilters,
) => ["enrollments-list", userId, filters] as const

/**
 * Role-scoped staff lists poll every 15s by default (ADR 0029), or at the
 * caller's requested interval. Registrar Approvals uses 5s. The Cashier
 * uses this list for approved students awaiting a ticket and to hydrate
 * the exact enrollments behind visible tickets. Its waiting, serving and
 * served ticket pages poll separately through `useQueueTicketsQuery`.
 * Refetches immediately on window focus; polling pauses in hidden tabs.
 */
export function useEnrollmentsListQuery(
  filters: EnrollmentFilters,
  {
    enabled = true,
    refetchIntervalMs = 15_000,
  }: { enabled?: boolean; refetchIntervalMs?: number } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: enrollmentsListQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listEnrollments(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
    refetchInterval: refetchIntervalMs,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: "always",
  })
}

/**
 * Both Registrar decisions (`registrar_approve`/`registrar_reject`/`void`)
 * and payment confirmation invalidate the same two query families: the
 * role-scoped list this decision was made from, and the student's own view
 * (only ever populated for a Student session, but harmless to invalidate
 * unconditionally since the query is gated on session presence anyway).
 */
function useInvalidateEnrollmentQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({
        queryKey: ["enrollments-list", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: enrollmentsQueryKey(session?.userId ?? null),
        exact: true,
      }),
      queryClient.invalidateQueries({
        queryKey: ["student-account"],
      }),
      // A cancelled or voided enrollment frees its seats and makes the student's sections
      // selectable again; without this the Choose buttons stay disabled until a reload.
      queryClient.invalidateQueries({
        queryKey: ["enrollment-blocks", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["eligible-subjects", session?.userId ?? null],
      }),
      // Confirming payment marks the student's queue ticket served on the server, and a void or
      // cancel cancels it; the Cashier's queue must show that without a reload.
      queryClient.invalidateQueries({
        queryKey: ["queue-tickets", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({
        queryKey: ["cashier-payment-candidate", session?.userId ?? null],
      }),
      queryClient.invalidateQueries({ queryKey: ["payment-quote"] }),
      // The number on the Program Chair's Irregular Advising tab.
      queryClient.invalidateQueries({
        queryKey: ["program-chair-review-counts"],
      }),
    ])
}

export function useUpdateEnrollmentMutation() {
  const invalidate = useInvalidateEnrollmentQueries()

  return useMutation({
    mutationFn: ({
      id,
      action,
      reason,
      overload_acknowledged,
      requested_by_student,
      program_head_comment,
    }: {
      id: number
      action:
        | "program_head_approve"
        | "program_head_reject"
        | "registrar_approve"
        | "registrar_reject"
        | "void"
        | "student_cancel"
        | "student_accept_revision"
        | "student_decline_revision"
      reason?: string
      overload_acknowledged?: boolean
      requested_by_student?: boolean
      program_head_comment?: string
    }) =>
      updateEnrollment(id, {
        action,
        reason,
        overload_acknowledged,
        requested_by_student,
        program_head_comment,
      }),
    onSuccess: () => invalidate(),
  })
}

/**
 * Program Head adding/removing whole subjects from a student's proposed
 * schedule while it sits at their own review stage.
 */
export function useReviseEnrollmentSubjectsMutation() {
  const invalidate = useInvalidateEnrollmentQueries()

  return useMutation({
    mutationFn: ({
      id,
      sectionIds,
      note,
      overloadAcknowledged,
    }: {
      id: number
      sectionIds: number[]
      note: string
      overloadAcknowledged?: boolean
    }) =>
      reviseEnrollmentSubjects(id, {
        section_ids: sectionIds,
        note,
        overload_acknowledged: overloadAcknowledged,
      }),
    onSuccess: () => invalidate(),
  })
}

export function useConfirmPaymentMutation() {
  const invalidate = useInvalidateEnrollmentQueries()

  return useMutation({
    mutationFn: ({
      id,
      externalReference,
      amount,
      promissoryNoteOnFile,
      paymentType,
      quotedAmount,
    }: {
      id: number
      externalReference?: string
      amount?: number
      promissoryNoteOnFile?: boolean
      paymentType?: "full" | "installment"
      quotedAmount?: string
    }) =>
      confirmPayment(id, {
        external_reference: externalReference,
        amount,
        promissory_note_on_file: promissoryNoteOnFile,
        payment_type: paymentType,
        quoted_amount: quotedAmount,
      }),
    onSuccess: () => invalidate(),
  })
}

export function useAdjustEnrollmentAssessmentMutation() {
  const invalidate = useInvalidateEnrollmentQueries()

  return useMutation({
    mutationFn: ({
      id,
      reason,
      items,
    }: {
      id: number
      reason: string
      items: { id: number; amount?: string; unit_amount?: string }[]
    }) => adjustEnrollmentAssessment(id, { reason, items }),
    onSuccess: () => invalidate(),
  })
}

/**
 * The Cashier's Payee/Scholar step (ADR 0025). `mutateAsync` resolves only
 * after the enrollment lists and account have been refetched, so the payment
 * modal that opens next already reads the discounted assessment.
 */
export function useApplyScholarshipDiscountMutation() {
  const invalidate = useInvalidateEnrollmentQueries()

  return useMutation({
    mutationFn: ({
      id,
      percentage,
    }: {
      id: number
      percentage: ScholarshipPercentage
    }) => applyScholarshipDiscount(id, percentage),
    onSuccess: () => invalidate(),
  })
}

export function useRemoveScholarshipDiscountMutation() {
  const invalidate = useInvalidateEnrollmentQueries()

  return useMutation({
    mutationFn: ({ id }: { id: number }) => removeScholarshipDiscount(id),
    onSuccess: () => invalidate(),
  })
}

export function useCorPreviewQuery(enrollmentId: number | null) {
  const { session } = useAuth()

  return useQuery({
    queryKey: ["cor-preview", session?.userId ?? null, enrollmentId],
    queryFn: ({ signal }) => getCorPreview(enrollmentId!, signal),
    enabled: session !== null && enrollmentId !== null,
  })
}

/**
 * The Cashier's quote for the serving enrollment (ADR 0046). Read while the payment steps are
 * open and kept fresh, because the Registrar Head can change the discount at any moment.
 */
export function usePaymentQuoteQuery(
  enrollmentId: number | null,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: ["payment-quote", session?.userId ?? null, enrollmentId],
    queryFn: ({ signal }) => getPaymentQuote(enrollmentId!, signal),
    enabled:
      enabled && session?.role === "accounting_staff" && enrollmentId !== null,
    staleTime: 0,
    refetchInterval: 15_000,
  })
}
