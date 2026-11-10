import { screen } from "@testing-library/react"
import { afterEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { StudentDigitalComWorkspace } from "@/features/components/portal/student-digital-com-workspace"
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
  total: 1,
}

const document = {
  type: "enrollment_document",
  id: 1,
  enrollment_id: 9,
  student_number: "2026-0001",
  document_type: "cor",
  document_type_label: "Certificate of Registration",
  document_number: "COR000009",
  generated_at: "2026-07-30T00:00:00Z",
} as const

const studentSession = {
  userId: "4",
  displayName: "Student",
  role: "student",
  signedInAt: "2026-07-29T12:00:00Z",
} as const

function mockFetch(documents: unknown[] = [document]) {
  return vi.fn<typeof fetch>().mockResolvedValue(
    new Response(
      JSON.stringify({ data: documents, links: paginationLinks, meta: paginationMeta }),
    ),
  )
}

describe("StudentDigitalComWorkspace", () => {
  afterEach(() => vi.unstubAllGlobals())

  it("does not render for an unauthorized role", () => {
    vi.stubGlobal("fetch", mockFetch([]))
    renderWithSession(<StudentDigitalComWorkspace />, {
      session: {
        userId: "5",
        displayName: "Registrar Head",
        role: "registrar_head",
        signedInAt: "2026-07-29T12:00:00Z",
      },
    })
    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
  })

  it("shows the student's generated Certificate of Registration", async () => {
    vi.stubGlobal("fetch", mockFetch())
    renderWithSession(<StudentDigitalComWorkspace />, {
      session: studentSession,
    })

    expect(await screen.findByText(/COR000009/)).toBeInTheDocument()
    expect(
      screen.getAllByText("Certificate of Registration").length,
    ).toBeGreaterThan(0)
  })

  it("keeps payments off the COR page and points to the Statement of Account", async () => {
    vi.stubGlobal("fetch", mockFetch())
    renderWithSession(<StudentDigitalComWorkspace />, {
      session: studentSession,
    })

    await screen.findByText(/COR000009/)
    expect(screen.queryByText(/Payment & Account Summary/)).not.toBeInTheDocument()
    expect(screen.queryByText(/^Remaining Balance/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/^Amount Paid/i)).not.toBeInTheDocument()
    expect(
      screen.queryByRole("table", { name: "Student payment transactions" }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole("link", { name: "Statement of Account" }),
    ).toHaveAttribute("href", "/portal/statement-of-account")
    // Only the Certificate of Registration is requested, never the student's account.
    const fetchMock = vi.mocked(fetch)
    expect(
      fetchMock.mock.calls.some(([input]) => String(input).includes("student-account")),
    ).toBe(false)
  })

  it("shows an empty message when no Certificate of Registration has been generated", async () => {
    vi.stubGlobal("fetch", mockFetch([]))
    renderWithSession(<StudentDigitalComWorkspace />, {
      session: studentSession,
    })

    expect(
      await screen.findByText("No Certificate of Registration has been generated yet."),
    ).toBeInTheDocument()
  })

  it("has no detectable accessibility violations once loaded", async () => {
    vi.stubGlobal("fetch", mockFetch())
    const { container } = renderWithSession(<StudentDigitalComWorkspace />, {
      session: studentSession,
    })

    await screen.findByText(/COR000009/)
    expect(await axe(container)).toHaveNoViolations()
  })
})
