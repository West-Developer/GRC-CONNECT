import type { TransfereeCreditStatus } from "@/features/schemas/transferee-credit-schema"

/**
 * Whether a transferee's credit mapping still blocks enrollment. It mirrors the
 * API's `EvaluateCreditMappingStatus`: a draft is the student's own unsent list,
 * so it counts for nothing until it is submitted. With no sent credit the
 * mapping has not started; while any sent credit is still `pending` (with the
 * Program Chair) it is in progress. A credit the Chair has passed to the
 * Registrar Head (`endorsed`) no longer blocks (ADR 0050): the student may enroll
 * while the Head's approval is still to come.
 */
export function isCreditMappingIncomplete(
  credits: readonly { status: TransfereeCreditStatus }[],
): boolean {
  const sent = credits.filter((credit) => credit.status !== "draft")

  if (sent.length === 0) return true

  return sent.some((credit) => credit.status === "pending")
}

/**
 * The subject the Program Chair's screen pre-selects for each row (ADR 0026,
 * amended). It is only a pre-selection: nothing is saved until the Chair clicks
 * Confirm, so a reload shows the rows unconfirmed again.
 *
 * A row that already has a saved subject keeps it and gets no pre-selection. For
 * every other row, in order, the first suggestion is taken whose subject is not
 * already saved on another credit and was not already picked for an earlier row,
 * so two rows never pre-select the same subject. A row with nothing left to pick
 * is left out of the result.
 */
export function preselectSuggestions(
  credits: readonly { id: number; subject_id: number | null }[],
  groups: readonly {
    credit_id: number
    suggestions: readonly { subject_id: number }[]
  }[],
): Record<number, number> {
  const taken = new Set<number>()

  for (const credit of credits) {
    if (credit.subject_id !== null) taken.add(credit.subject_id)
  }

  const picked: Record<number, number> = {}

  for (const credit of credits) {
    if (credit.subject_id !== null) continue

    const group = groups.find((candidate) => candidate.credit_id === credit.id)
    const choice = group?.suggestions.find(
      (suggestion) => !taken.has(suggestion.subject_id),
    )

    if (choice !== undefined) {
      picked[credit.id] = choice.subject_id
      taken.add(choice.subject_id)
    }
  }

  return picked
}

/** Credits grouped by student, in the order the students first appear. */
export function groupCreditsByStudent<
  T extends {
    student_id: number
    student_name: string
    student_number: string
  },
>(
  credits: readonly T[],
): {
  student_id: number
  student_name: string
  student_number: string
  credits: T[]
}[] {
  const groups = new Map<
    number,
    {
      student_id: number
      student_name: string
      student_number: string
      credits: T[]
    }
  >()

  for (const credit of credits) {
    const group = groups.get(credit.student_id)

    if (group === undefined) {
      groups.set(credit.student_id, {
        student_id: credit.student_id,
        student_name: credit.student_name,
        student_number: credit.student_number,
        credits: [credit],
      })
    } else {
      group.credits.push(credit)
    }
  }

  return [...groups.values()]
}
