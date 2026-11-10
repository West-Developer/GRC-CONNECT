import { render, screen, within } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { CertificateOfRegistrationDocument } from "@/features/components/portal/certificate-of-registration-document"
import type {
  CertificateOfRegistration,
  CorSnapshot,
} from "@/features/schemas/enrollment-document-schema"

const cor = {
  type: "certificate_of_registration",
  id: 1,
  enrollment_id: 9,
  document_number: "COR000009",
  generated_at: "2026-07-30T00:00:00Z",
  content_hash: "abc123",
  snapshot: {
    document_title: "Certificate of Registration",
    institution: {
      name: "Global Reciprocal Colleges",
      address:
        "GRC Building, 454, 1400 Rizal Ave Ext, East Grace Park, Caloocan, Metro Manila",
    },
    student: {
      student_number: "2026-0001",
      name: "Test Student",
      address: "123 Test Drive, Caloocan City",
      course: "BS Information Technology",
      level: "Year 4",
      platform: "Not provided",
    },
    term: { school_year: "2026-2027", semester: "1st" },
    subjects: [
      {
        code: "IT401",
        title: "Business Analytics",
        units: "3.00",
        section: "IV-BLOCK",
        schedule_id: "40882",
        schedule: "10:30 AM - 01:30 PM Mon",
        room: "Hybrid Flexible Learning (HyFlex)",
      },
    ],
    total_units: "3.00",
    admission_certification:
      "This is to certify that Test Student is cleared and enrolled.",
    // The COR prints the assessment (the bill: tuition, other fees, discount, grand
    // total) but never what was paid or the balance; those stay in the Statement of Account.
    fees: {
      currency: "PHP",
      tuition: [
        {
          label: "Tuition fee",
          quantity: "3.00",
          unit_amount: "900.00",
          amount: "2700.00",
        },
      ],
      other_fees: [
        {
          label: "Registration",
          quantity: null,
          unit_amount: null,
          amount: "200.00",
        },
      ],
      total_tuition: "2700.00",
      total_other_fees: "200.00",
      grand_total: "2900.00",
      payment_amount: "2900.00",
    },
    signatories: { cashier: "Cashier Test", registrar: "Registrar" },
    withdrawal_terms: [
      "1. Period of Withdrawal. Withdrawal may be validly effected only within the approved period.",
    ],
  },
} satisfies CertificateOfRegistration & { snapshot: CorSnapshot }

describe("CertificateOfRegistrationDocument", () => {
  it("renders the official two-page COR record with subjects and terms", () => {
    render(<CertificateOfRegistrationDocument cor={cor} />)

    expect(screen.getAllByText("CERTIFICATE OF REGISTRATION")).toHaveLength(2)
    expect(screen.getByText("Business Analytics")).toBeInTheDocument()
    expect(
      screen.queryByRole("columnheader", { name: "Room" }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByText("TERMS AND CONDITIONS GOVERNING WITHDRAWAL"),
    ).toBeInTheDocument()
    expect(screen.getByText("Cashier Test")).toBeInTheDocument()
    expect(screen.getByText("COR000009")).toBeInTheDocument()
  })

  it("shows the assessment of fees but never what was paid, the balance or the payment status", () => {
    const withPayment = {
      ...cor,
      snapshot: {
        ...cor.snapshot,
        fees: {
          ...cor.snapshot.fees,
          amount_paid: "1000.00",
          remaining_balance: "1900.00",
          promissory_note_on_file: true,
          payment_reference: "OR-EP000001",
        },
      },
    }
    render(<CertificateOfRegistrationDocument cor={withPayment} />)

    expect(screen.getByText("ASSESSMENT OF FEES")).toBeInTheDocument()
    expect(screen.getByText("Tuition fee")).toBeInTheDocument()
    expect(screen.getByText("Registration")).toBeInTheDocument()
    expect(screen.getByText("GRAND TOTAL")).toBeInTheDocument()
    expect(screen.getAllByText("₱2,900.00").length).toBeGreaterThan(0)
    expect(screen.queryByText(/1,000\.00/)).not.toBeInTheDocument()
    expect(screen.queryByText(/1,900\.00/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Amount Paid/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Remaining Balance/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Payment Status|PAID IN FULL/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Promissory/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/OR-EP000001/)).not.toBeInTheDocument()
  })

  it("lists the full-payment discount above the grand total, and omits it when there is none", () => {
    const withDiscount = {
      ...cor,
      snapshot: {
        ...cor.snapshot,
        fees: {
          ...cor.snapshot.fees,
          full_payment_discount: [
            {
              label: "Full-payment discount (10%)",
              quantity: "10.0",
              unit_amount: null,
              amount: "-290.00",
            },
          ],
          total_full_payment_discount: "-290.00",
          grand_total: "2610.00",
        },
      },
    } satisfies CertificateOfRegistration & { snapshot: CorSnapshot }
    const { rerender } = render(
      <CertificateOfRegistrationDocument cor={withDiscount} />,
    )

    const region = screen.getByLabelText("Full-payment discount")
    expect(
      within(region).getByText("Full-payment discount (10%)"),
    ).toBeInTheDocument()
    expect(within(region).getByText(/290\.00/)).toBeInTheDocument()
    // The discount line prints above the grand total, never after it.
    expect(
      region.compareDocumentPosition(screen.getByText("GRAND TOTAL")) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(screen.getByText("₱2,610.00")).toBeInTheDocument()

    rerender(<CertificateOfRegistrationDocument cor={cor} />)
    expect(
      screen.queryByLabelText("Full-payment discount"),
    ).not.toBeInTheDocument()
  })

  it("shows the Generated timestamp in Asia/Manila time regardless of the viewer's own timezone", () => {
    render(<CertificateOfRegistrationDocument cor={cor} />)

    // 2026-07-30T00:00:00Z is 8:00 AM in Asia/Manila (UTC+8).
    expect(
      screen.getByText(/Generated 7\/30\/2026, 8:00:00 AM/),
    ).toBeInTheDocument()
  })

  it("uses the approved labels and the ordinal year level, even for a legacy 'Year N' snapshot", () => {
    const legacy = {
      ...cor,
      snapshot: {
        ...cor.snapshot,
        admission_certification:
          "This is to certify that Test Student is cleared and enrolled for SY 2026-2027, 1st for BS Information Technology, Year 4.",
      },
    } satisfies CertificateOfRegistration & { snapshot: CorSnapshot }

    render(<CertificateOfRegistrationDocument cor={legacy} />)

    for (const label of ["Name", "Degree", "Academic Year", "Year Level"]) {
      expect(screen.getByText(label, { selector: "dt" })).toBeInTheDocument()
    }
    for (const oldLabel of ["Student", "Course", "School Year", "Level"]) {
      expect(
        screen.queryByText(oldLabel, { selector: "dt" }),
      ).not.toBeInTheDocument()
    }
    expect(
      screen.getByText("Test Student", { selector: "dd" }),
    ).toBeInTheDocument()
    expect(screen.getByText("4th Year", { selector: "dd" })).toBeInTheDocument()
    expect(
      screen.queryByText("Year 4", { selector: "dd" }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByText(/BS Information Technology, 4th Year\./),
    ).toBeInTheDocument()
  })
})
