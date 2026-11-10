import type { TransfereeCredit } from "@/features/schemas/transferee-credit-schema"

/** "CS 101 — Intro to Computing", or just the title when the student gave no code. */
export function creditEntryTitle(credit: TransfereeCredit): string {
  return credit.source_subject_code
    ? `${credit.source_subject_code} — ${credit.source_subject_title}`
    : credit.source_subject_title
}

/**
 * The left-hand side of the side-by-side credit mapping screens (the Program
 * Chair's and the Registrar Head's): what the student entered, exactly as they
 * entered it. It carries no mapping and no decision.
 */
export function CreditEntryCell({ credit }: { credit: TransfereeCredit }) {
  const when = [credit.source_school_year, credit.source_semester]
    .filter(Boolean)
    .join(" ")

  return (
    <div className="grid gap-0.5 text-sm">
      <span className="font-medium">{creditEntryTitle(credit)}</span>
      <span className="text-xs text-muted-foreground">
        {when
          ? `${credit.source_institution} · ${when}`
          : credit.source_institution}
      </span>
      <span className="text-xs text-muted-foreground">
        {credit.credited_units} units
        {credit.source_grade ? ` · Grade ${credit.source_grade}` : ""}
      </span>
    </div>
  )
}
