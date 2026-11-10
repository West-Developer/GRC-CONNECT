import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { toast } from "sonner"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { AdmissionProvisioningWorkspace } from "@/features/components/portal/admission-provisioning-workspace"
import { renderWithSession } from "@/tests/render-app"

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}))

const base = {
  type: "student_profile",
  user_id: 41,
  middle_initial: null,
  suffix: null,
  address: "123 Mabini Street, Caloocan City, Metro Manila",
  gender: "F",
  birth_date: "2006-05-04",
  citizenship: "Filipino",
  contact_number: "09171234567",
  street_barangay: "123 Mabini Street",
  town_city: "Caloocan City",
  province: "Metro Manila",
  generated_fields: [],
  program_id: 11,
  program_code: "BSIT",
  program_name: "Bachelor of Science in Information Technology",
  curriculum_id: 22,
  entry_year: 2026,
  curriculum_name: "BSIT 2026 Curriculum",
  curriculum_effective_school_year: "2026-2027",
  year_level: 1,
  enrollment_category: "regular",
  student_type: "freshman",
  student_type_label: "Freshman",
  admission_status: "admitted",
  admission_status_label: "Admitted",
  academic_standing: "good",
  academic_standing_label: "Good Standing",
  financial_status: null,
  financial_status_label: null,
  requirements_verified_at: "2026-10-01T08:00:00Z",
  academic_setup_editable: true,
} as const

// Has not paid: only the queue link can go out, the setup email follows the first payment.
const unpaid = {
  ...base,
  id: 101,
  student_number: "2026-10-00101",
  name: "Una Paid",
  first_name: "Una",
  last_name: "Paid",
  email: "una.paid@grc.test",
  account_setup_status: "awaiting_payment",
  invitation_delivery_status: "not_sent",
  freshman_payment_confirmed_at: null,
  freshman_status: "awaiting_payment",
  queue_ticket_number: "Q101",
  queue_link_delivery_status: "sent",
} as const

// Paid, the setup email went out, the student has not set the account up (mailbox may be full).
const paidSent = {
  ...base,
  id: 102,
  student_number: "2026-10-00102",
  name: "Pia Sent",
  first_name: "Pia",
  last_name: "Sent",
  email: "pia.sent@grc.test",
  account_setup_status: "pending",
  invitation_delivery_status: "sent",
  freshman_payment_confirmed_at: "2026-10-15T07:42:00Z",
  freshman_status: "awaiting_section",
  queue_ticket_number: "Q102",
  queue_link_delivery_status: "sent",
} as const

const paidFailed = {
  ...paidSent,
  id: 103,
  student_number: "2026-10-00103",
  name: "Fay Failed",
  first_name: "Fay",
  last_name: "Failed",
  email: "fay.failed@grc.test",
  invitation_delivery_status: "failed",
  freshman_status: "enrolling",
  queue_ticket_number: "Q103",
} as const

const active = {
  ...paidSent,
  id: 104,
  student_number: "2026-10-00104",
  name: "Ana Active",
  first_name: "Ana",
  last_name: "Active",
  email: "ana.active@grc.test",
  account_setup_status: "active",
  freshman_status: "enrolling",
  queue_ticket_number: "Q104",
} as const

const pagination = {
  links: {
    first: "http://localhost/api?page=1",
    last: "http://localhost/api?page=1",
    prev: null,
    next: null,
  },
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 4 },
}

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function renderWorkspace() {
  return renderWithSession(<AdmissionProvisioningWorkspace />, {
    session: {
      userId: "5",
      displayName: "Admission Staff",
      role: "admission_staff",
      signedInAt: "2026-10-16T00:00:00Z",
    },
  })
}

describe("Admission Freshmen tab", () => {
  const fetchMock = vi.fn<typeof fetch>()
  /** What the next resend answers: the profile the server returns after sending. */
  let resendAnswer: Record<string, unknown> = paidSent

  beforeEach(() => {
    vi.mocked(toast.success).mockClear()
    vi.mocked(toast.error).mockClear()
    resendAnswer = paidSent
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockReset()
    fetchMock.mockImplementation((input, init) => {
      const url = urlOf(input)
      if (
        url.includes("/account-setup-invitations") &&
        init?.method === "POST"
      ) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: resendAnswer })),
        )
      }
      if (url.includes("/api/v1/student-profiles")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [unpaid, paidSent, paidFailed, active],
              ...pagination,
            }),
          ),
        )
      }
      if (url.includes("/api/v1/student-profile-change-requests")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [], ...pagination })),
        )
      }
      if (url.includes("/api/v1/programs")) {
        return Promise.resolve(new Response(JSON.stringify({ data: [] })))
      }
      return Promise.reject(new Error(`Unexpected request: ${url}`))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  async function openFreshmenTab() {
    const user = userEvent.setup()
    renderWorkspace()
    await user.click(screen.getByRole("tab", { name: "Freshmen" }))
    const table = await screen.findByRole("table", { name: "Freshmen" })
    return { user, table }
  }

  function directoryRequests(): URL[] {
    return fetchMock.mock.calls
      .map(([input]) => urlOf(input))
      .filter((url) => url.includes("/api/v1/student-profiles?"))
      .map((url) => new URL(url, "http://localhost"))
  }

  function rowOf(table: HTMLElement, name: string): HTMLElement {
    return within(table)
      .getByRole("button", { name })
      .closest("tr") as HTMLElement
  }

  it("asks only for freshmen and shows who has paid, when, and where each one is", async () => {
    const { table } = await openFreshmenTab()

    expect(directoryRequests().at(-1)?.searchParams.get("student_type")).toBe(
      "freshman",
    )
    // Only the freshmen Admission is processing, never the imported historical records.
    expect(directoryRequests().at(-1)?.searchParams.get("intake")).toBe(
      "pay_first",
    )
    expect(
      directoryRequests().at(-1)?.searchParams.get("account_setup_status"),
    ).toBeNull()

    const unpaidRow = rowOf(table, "Una Paid")
    expect(within(unpaidRow).getByText("Q101")).toBeInTheDocument()
    expect(within(unpaidRow).getByText("Not paid yet")).toBeInTheDocument()
    expect(
      within(unpaidRow).getByText("Waiting for the Cashier"),
    ).toBeInTheDocument()

    const paidRow = rowOf(table, "Pia Sent")
    expect(within(paidRow).getByText("Paid")).toBeInTheDocument()
    // 2026-10-15 07:42 UTC is 3:42 PM at the school (Asia/Manila), whatever the viewer's timezone.
    expect(
      within(paidRow).getByText("Oct 15, 2026, 3:42 PM"),
    ).toBeInTheDocument()
    expect(
      within(paidRow).getByText("Paid · waiting for a section"),
    ).toBeInTheDocument()
    expect(within(paidRow).getByText("Awaiting setup")).toBeInTheDocument()

    const failedRow = rowOf(table, "Fay Failed")
    expect(within(failedRow).getByText("Delivery failed")).toBeInTheDocument()
    expect(within(failedRow).getByText("Paid · enrolling")).toBeInTheDocument()

    expect(
      within(rowOf(table, "Ana Active")).getByText("Activated"),
    ).toBeInTheDocument()
  })

  it("offers the setup email only once the freshman has paid and not yet set the account up", async () => {
    const { table } = await openFreshmenTab()

    const unpaidRow = rowOf(table, "Una Paid")
    expect(
      within(unpaidRow).queryByRole("button", { name: "Resend setup email" }),
    ).not.toBeInTheDocument()
    expect(
      within(unpaidRow).getByRole("button", { name: "Resend queue link" }),
    ).toBeInTheDocument()
    expect(
      within(unpaidRow).getByText(/sent after the Cashier confirms/),
    ).toBeInTheDocument()

    for (const name of ["Pia Sent", "Fay Failed"]) {
      expect(
        within(rowOf(table, name)).getByRole("button", {
          name: "Resend setup email",
        }),
      ).toBeInTheDocument()
    }

    const activeRow = rowOf(table, "Ana Active")
    expect(
      within(activeRow).queryByRole("button", { name: "Resend setup email" }),
    ).not.toBeInTheDocument()
    expect(
      within(activeRow).getByText("Account already set up."),
    ).toBeInTheDocument()
  })

  it("resends the setup email for that student and says it was sent", async () => {
    const { user, table } = await openFreshmenTab()

    await user.click(
      within(rowOf(table, "Pia Sent")).getByRole("button", {
        name: "Resend setup email",
      }),
    )

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "Setup email sent to pia.sent@grc.test.",
      ),
    )
    const post = fetchMock.mock.calls.find(
      ([input, init]) =>
        urlOf(input).endsWith(
          "/student-profiles/102/account-setup-invitations",
        ) && init?.method === "POST",
    )
    expect(post).toBeDefined()
    expect(toast.error).not.toHaveBeenCalled()
  })

  it("does not call it a success when the mail server rejected the email", async () => {
    resendAnswer = { ...paidFailed, id: 103 }
    const { user, table } = await openFreshmenTab()

    await user.click(
      within(rowOf(table, "Fay Failed")).getByRole("button", {
        name: "Resend setup email",
      }),
    )

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "The setup email could not be sent to fay.failed@grc.test. Check the address, then try again.",
      ),
    )
    expect(toast.success).not.toHaveBeenCalled()
  })

  it("narrows the list with the account filters and the search box", async () => {
    const { user } = await openFreshmenTab()
    expect(
      screen.getByRole("button", { name: "All freshmen" }),
    ).toHaveAttribute("aria-pressed", "true")

    await user.click(
      screen.getByRole("button", { name: "Paid · account not set up" }),
    )
    await waitFor(() =>
      expect(
        directoryRequests().at(-1)?.searchParams.get("account_setup_status"),
      ).toBe("pending"),
    )
    expect(
      screen.getByRole("button", { name: "Paid · account not set up" }),
    ).toHaveAttribute("aria-pressed", "true")
    expect(directoryRequests().at(-1)?.searchParams.get("student_type")).toBe(
      "freshman",
    )

    await user.click(screen.getByRole("button", { name: "Awaiting payment" }))
    await waitFor(() =>
      expect(
        directoryRequests().at(-1)?.searchParams.get("account_setup_status"),
      ).toBe("awaiting_payment"),
    )

    await user.type(screen.getByLabelText("Search freshmen"), "una")
    await waitFor(() =>
      expect(directoryRequests().at(-1)?.searchParams.get("search")).toBe(
        "una",
      ),
    )
    // A new filter or search always starts again from the first page.
    expect(directoryRequests().at(-1)?.searchParams.get("page")).toBe("1")
  })

  it("opens the student's record from the name, where the email address can be corrected", async () => {
    const { user, table } = await openFreshmenTab()

    await user.click(within(table).getByRole("button", { name: "Pia Sent" }))

    expect(
      await screen.findByRole("dialog", { name: "Pia Sent" }),
    ).toBeInTheDocument()
  })
})
