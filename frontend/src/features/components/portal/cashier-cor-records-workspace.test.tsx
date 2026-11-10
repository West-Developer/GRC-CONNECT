import { screen, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { CashierCorRecordsWorkspace } from "@/features/components/portal/cashier-cor-records-workspace"
import { renderWithSession } from "@/tests/render-app"

const paginationLinks = {
  first: "https://api.test/enrollment-documents?page=1",
  last: "https://api.test/enrollment-documents?page=1",
  prev: null,
  next: null,
}
const paginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 2,
}
const firstCor = {
  type: "enrollment_document",
  id: 1,
  enrollment_id: 9,
  student_number: "2026-0001",
  student_name: "Aurora S. Lopez",
  document_type: "cor",
  document_type_label: "Certificate of Registration",
  document_number: "COR000009",
  generated_at: "2026-07-30T00:00:00Z",
} as const
const secondCor = {
  ...firstCor,
  id: 2,
  enrollment_id: 10,
  document_number: "COR000010",
} as const

function documentResponse(documents: readonly unknown[]) {
  return new Response(
    JSON.stringify({
      data: documents,
      links: paginationLinks,
      meta: paginationMeta,
    }),
  )
}

describe("CashierCorRecordsWorkspace", () => {
  afterEach(() => vi.unstubAllGlobals())

  it("lets Accounting Staff search by name and open a student's full COR history", async () => {
    const user = userEvent.setup()
    vi.stubGlobal(
      "fetch",
      vi.fn<typeof fetch>((input) => {
        const url = String(input)
        return Promise.resolve(
          documentResponse(
            url.includes("student_number=2026-0001")
              ? [firstCor, secondCor]
              : [firstCor],
          ),
        )
      }),
    )
    renderWithSession(<CashierCorRecordsWorkspace />, {
      session: {
        userId: "5",
        displayName: "Accounting Staff",
        role: "accounting_staff",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    await user.type(await screen.findByLabelText("Student name"), "Aurora")
    const [studentName] = await screen.findAllByRole("button", {
      name: "Aurora S. Lopez",
    })
    await user.click(studentName)

    const historyModal = await screen.findByRole("dialog", {
      name: "Aurora S. Lopez — COR history",
    })
    expect(within(historyModal).getAllByText("COR000009")).not.toHaveLength(0)
    expect(
      await within(historyModal).findAllByText("COR000010"),
    ).not.toHaveLength(0)
  })

  it("also authorizes the COR Records workspace for Registrar Head", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn<typeof fetch>(() => Promise.resolve(documentResponse([]))),
    )
    renderWithSession(<CashierCorRecordsWorkspace />, {
      session: {
        userId: "6",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(await screen.findByText("Find a student's COR")).toBeInTheDocument()
    expect(
      screen.queryByText("This workspace is not available for your role."),
    ).not.toBeInTheDocument()
  })

  it("authorizes the COR Records workspace for Registrar Staff", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn<typeof fetch>(() => Promise.resolve(documentResponse([]))),
    )
    renderWithSession(<CashierCorRecordsWorkspace />, {
      session: {
        userId: "9",
        displayName: "Registrar Staff",
        role: "registrar_staff",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })

    expect(await screen.findByText("Find a student's COR")).toBeInTheDocument()
    expect(
      screen.queryByText("This workspace is not available for your role."),
    ).not.toBeInTheDocument()
  })
})
