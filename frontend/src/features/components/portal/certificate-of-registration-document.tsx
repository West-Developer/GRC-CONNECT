import { formatGeneratedAt } from "@/features/lib/format-generated-at"
import { formatYearLevel } from "@/features/lib/format-year-level"
import type {
  CertificateOfRegistration,
  CorSnapshot,
} from "@/features/schemas/enrollment-document-schema"

/**
 * Issued snapshots keep the raw "Year N" wording (the COR endpoint re-saves a
 * row whenever a rebuilt snapshot differs), so the institution-approved
 * "1st Year" form is applied here when rendering, legacy snapshots included.
 */
function ordinalizeYearLevels(sentence: string): string {
  return sentence.replace(/\bYear (\d+)\b/g, (_match, level: string) =>
    formatYearLevel(level),
  )
}

type RenderableCor = CertificateOfRegistration & { snapshot: CorSnapshot }
type CorFeeItem = CorSnapshot["fees"]["other_fees"][number]

const otherFeeLabels = [
  "Registration",
  "Guidance and Counseling and Student Affair",
  "Medical and Dental",
  "Student Information System Fee",
  "Energy/Water/Communication Fees",
  "Community Extension Fee",
  "Research & Publication",
  "Computer Lab Fee 1 (All Students)",
  "Student I.D.",
  "Development Fee",
  "Postal",
  "Computer Lab Fee 2 (BSIT)",
  "Sports Development Fee",
  "Hand Book",
  "Library Fee",
] as const

function canonicalOtherFeeLabel(label: string): string | null {
  const normalized = label.toLowerCase().replace(/[^a-z0-9]/g, "")
  const aliases: Record<string, (typeof otherFeeLabels)[number]> = {
    registration: "Registration",
    guidanceandcounselingandstudentaffair:
      "Guidance and Counseling and Student Affair",
    guidanceandcounsellingandstudentaffair:
      "Guidance and Counseling and Student Affair",
    medicalanddental: "Medical and Dental",
    studentinformationsystemfee: "Student Information System Fee",
    sisfee: "Student Information System Fee",
    energywatercommunicationfees: "Energy/Water/Communication Fees",
    energywatercommunicationfee: "Energy/Water/Communication Fees",
    communityextensionfee: "Community Extension Fee",
    researchpublication: "Research & Publication",
    researchandpublication: "Research & Publication",
    computerlabfee1allstudents: "Computer Lab Fee 1 (All Students)",
    computerlabfee1: "Computer Lab Fee 1 (All Students)",
    laboratory: "Computer Lab Fee 1 (All Students)",
    studentid: "Student I.D.",
    studentidentification: "Student I.D.",
    developmentfee: "Development Fee",
    postal: "Postal",
    computerlabfee2bsit: "Computer Lab Fee 2 (BSIT)",
    computerlabfee2: "Computer Lab Fee 2 (BSIT)",
    sportsdevelopmentfee: "Sports Development Fee",
    handbook: "Hand Book",
    library: "Library Fee",
    libraryfee: "Library Fee",
  }
  return aliases[normalized] ?? null
}

/** Maintains the reference COR's complete fee schedule for older snapshots. */
function otherFeesForDisplay(items: readonly CorFeeItem[]): CorFeeItem[] {
  const remaining = [...items]
  const scheduled = otherFeeLabels.map((label) => {
    const index = remaining.findIndex(
      (item) => canonicalOtherFeeLabel(item.label) === label,
    )
    if (index === -1) {
      return { label, quantity: null, unit_amount: null, amount: "0.00" }
    }
    const [item] = remaining.splice(index, 1)
    return { ...item, label }
  })
  return [...scheduled, ...remaining]
}

function money(amount: string, currency: string): string {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
  }).format(Number(amount))
}

function FeeRows({
  items,
  currency,
}: {
  items: readonly CorSnapshot["fees"]["tuition"][number][]
  currency: string
}) {
  return items.map((item) => (
    <div key={`${item.label}-${item.amount}`} className="cor-document__fee-row">
      <span>
        {item.label}
        {item.quantity && item.unit_amount && Number(item.unit_amount) > 0 ? (
          <small className="ml-1 opacity-80">
            ({item.quantity} units @ {money(item.unit_amount, currency)})
          </small>
        ) : null}
      </span>
      <span>{money(item.amount, currency)}</span>
    </div>
  ))
}

/** Official immutable record rendered solely from the payment-time COR snapshot. */
export function CertificateOfRegistrationDocument({
  cor,
  watermark,
}: {
  cor: RenderableCor
  /** Set for an unofficial preview: shown as a banner above the document. */
  watermark?: string
}) {
  const { snapshot } = cor
  const { student, institution, term, fees } = snapshot
  const displayedOtherFees = otherFeesForDisplay(fees.other_fees)
  const scholarshipDiscount = fees.scholarship_discount ?? []
  const fullPaymentDiscount = fees.full_payment_discount ?? []

  return (
    <article
      className="cor-document"
      aria-label={`Certificate of Registration ${cor.document_number}`}
    >
      <section className="cor-document__page">
        {watermark && (
          <p
            role="note"
            className="mb-2 rounded border border-dashed border-destructive/60 bg-destructive/5 p-2 text-center text-xs font-semibold uppercase tracking-wide text-destructive"
          >
            {watermark}
          </p>
        )}
        <header className="cor-document__header">
          <p>{institution.name}</p>
          <small>{institution.address}</small>
          <h1>CERTIFICATE OF REGISTRATION</h1>
        </header>

        <div className="cor-document__facts">
          <dl>
            <dt>Student No.</dt>
            <dd>{student.student_number}</dd>
            <dt>Name</dt>
            <dd>{student.name}</dd>
            <dt>Degree</dt>
            <dd>{student.course}</dd>
            <dt>Classification</dt>
            <dd className="font-semibold text-primary">
              {student.classification ?? "Payee"}
            </dd>
            <dt>Address</dt>
            <dd>{student.address}</dd>
          </dl>
          <dl>
            <dt>Academic Year</dt>
            <dd>{term.school_year}</dd>
            <dt>Semester</dt>
            <dd>{term.semester}</dd>
            <dt>Year Level</dt>
            <dd>{formatYearLevel(student.level)}</dd>
            <dt>Platform</dt>
            <dd>{student.platform}</dd>
          </dl>
        </div>

        <table>
          <caption>Registered subjects for {cor.document_number}</caption>
          <thead>
            <tr>
              <th>Code</th>
              <th>Subject</th>
              <th>Unit</th>
              <th>Section</th>
              <th>Schedule ID</th>
              <th>Schedule</th>
            </tr>
          </thead>
          <tbody>
            {snapshot.subjects.map((subject) => (
              <tr key={`${subject.code}-${subject.schedule_id}`}>
                <td>{subject.code}</td>
                <td>{subject.title}</td>
                <td>{subject.units}</td>
                <td>{subject.section}</td>
                <td>{subject.schedule_id}</td>
                <td>{subject.schedule}</td>
              </tr>
            ))}
            <tr className="cor-document__total-row">
              <td colSpan={2}>TOTAL</td>
              <td>{snapshot.total_units}</td>
              <td colSpan={3} />
            </tr>
          </tbody>
        </table>

        <section className="cor-document__admission">
          <h2>ADMISSION FORM</h2>
          <p>{ordinalizeYearLevels(snapshot.admission_certification)}</p>
        </section>

        <section className="cor-document__assessment">
          <h2>ASSESSMENT OF FEES</h2>
          <div className="cor-document__assessment-grid">
            <div>
              <h3>Tuition fees</h3>
              <FeeRows items={fees.tuition} currency={fees.currency} />
              <div className="cor-document__fee-total">
                <span>Total tuition fees</span>
                <strong>{money(fees.total_tuition, fees.currency)}</strong>
              </div>
            </div>
            <div>
              <h3>Other fees</h3>
              <FeeRows items={displayedOtherFees} currency={fees.currency} />
              <div className="cor-document__fee-total">
                <span>Total other fees</span>
                <strong>{money(fees.total_other_fees, fees.currency)}</strong>
              </div>
            </div>
          </div>
          {scholarshipDiscount.length > 0 && (
            <div
              className="cor-document__fee-total"
              aria-label="Scholarship discount"
            >
              <FeeRows items={scholarshipDiscount} currency={fees.currency} />
            </div>
          )}
          {fullPaymentDiscount.length > 0 && (
            <div
              className="cor-document__fee-total"
              aria-label="Full-payment discount"
            >
              <FeeRows items={fullPaymentDiscount} currency={fees.currency} />
            </div>
          )}
          <div className="cor-document__grand-total">
            <span>GRAND TOTAL</span>
            <strong>{money(fees.grand_total, fees.currency)}</strong>
          </div>
        </section>
      </section>

      <section className="cor-document__page cor-document__page--terms">
        <header className="cor-document__header cor-document__header--compact">
          <p>{institution.name}</p>
          <small>{institution.address}</small>
          <h1>CERTIFICATE OF REGISTRATION</h1>
        </header>
        <div className="cor-document__reference">
          <span>{student.name}</span>
          <span>{student.student_number}</span>
          <span>
            {term.school_year} · {term.semester}
          </span>
          <span>{cor.document_number}</span>
        </div>
        <section className="cor-document__terms">
          <h2>TERMS AND CONDITIONS GOVERNING WITHDRAWAL</h2>
          <ol>
            {snapshot.withdrawal_terms.map((termText, index) => {
              const cleanedText = termText.replace(/^\d+\.\s*/, "")
              return (
                <li key={`${index}-${cleanedText.slice(0, 15)}`}>
                  <strong>{index + 1}. </strong>
                  {cleanedText}
                </li>
              )
            })}
          </ol>
        </section>
        <footer className="cor-document__signatures">
          <div>
            <span>{snapshot.signatories.cashier}</span>
            <strong>CASHIER</strong>
          </div>
          <div>
            <span>{student.name}</span>
            <strong>STUDENT'S SIGNATURE OVER PRINTED NAME</strong>
          </div>
          <div>
            <span>{snapshot.signatories.registrar}</span>
            <strong>REGISTRAR</strong>
          </div>
        </footer>
        <p className="cor-document__footer">
          Generated {formatGeneratedAt(cor.generated_at)} ·{" "}
          {cor.document_number}
        </p>
      </section>
    </article>
  )
}
