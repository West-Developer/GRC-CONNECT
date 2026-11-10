import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FeeSettingsWorkspace } from "@/features/components/portal/fee-settings-workspace"
import { renderWithSession } from "@/tests/render-app"

const accountingStaff = {
  userId: "acct-1",
  displayName: "Accounting Staff",
  role: "accounting_staff" as const,
  signedInAt: "2026-09-28T00:00:00Z",
}

const feeSchedules = {
  data: [
    {
      id: 1,
      category: "tuition",
      semester: null,
      label: "Tuition Rate Per Unit",
      amount: "200.00",
      program_codes: null,
      is_active: true,
      sort_order: 1,
    },
    {
      id: 2,
      category: "miscellaneous",
      semester: null,
      label: "Registration",
      amount: "200.00",
      program_codes: null,
      is_active: true,
      sort_order: 2,
    },
    {
      id: 3,
      category: "miscellaneous",
      semester: "2nd",
      label: "Graduation Fee",
      amount: "500.00",
      program_codes: null,
      is_active: true,
      sort_order: 3,
    },
  ],
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

describe("FeeSettingsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  const bodyOf = (init: RequestInit | undefined) =>
    typeof init?.body === "string" ? init.body : ""
  const calls = (method: string, part: string) =>
    (
      fetchMock.mock.calls as [RequestInfo | URL, RequestInit | undefined][]
    ).filter(
      ([input, init]) =>
        requestUrl(input).includes(part) && init?.method === method,
    )

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.endsWith("/fee-schedules") && init?.method === "PUT") {
        return Promise.resolve(
          new Response(
            JSON.stringify({ message: "Saved.", data: feeSchedules.data }),
          ),
        )
      }
      const body = url.includes("/fee-schedules")
        ? feeSchedules
        : url.includes("/programs")
          ? { data: [] }
          : { data: [] }
      return Promise.resolve(new Response(JSON.stringify(body)))
    })
  })
  afterEach(() => vi.unstubAllGlobals())

  it("shows each miscellaneous fee's semester scope and lets it be changed", async () => {
    const user = userEvent.setup()
    renderWithSession(<FeeSettingsWorkspace />, { session: accountingStaff })

    const registrationRow = (
      await screen.findByDisplayValue("Registration")
    ).closest("tr")!
    const graduationRow = screen.getByDisplayValue("Graduation Fee").closest("tr")!
    expect(within(registrationRow).getByText("Every Semester")).toBeInTheDocument()
    expect(within(graduationRow).getByText("2nd Semester Only")).toBeInTheDocument()

    const registrationSemesterSelect =
      within(registrationRow).getAllByRole("combobox")[1]
    await user.click(registrationSemesterSelect)
    await user.click(await screen.findByRole("option", { name: "1st Semester Only" }))

    await user.click(screen.getByRole("button", { name: "Save Fee Settings" }))

    await waitFor(() =>
      expect(calls("PUT", "/fee-schedules")).toHaveLength(1),
    )
    const payload = JSON.parse(bodyOf(calls("PUT", "/fee-schedules")[0][1])) as {
      miscellaneous_fees: { label: string; semester: string | null }[]
    }
    expect(payload.miscellaneous_fees).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ label: "Registration", semester: "1st" }),
        expect.objectContaining({ label: "Graduation Fee", semester: "2nd" }),
      ]),
    )
  })

  it("is only for Accounting Staff and makes no request for another role", () => {
    renderWithSession(<FeeSettingsWorkspace />, {
      session: { ...accountingStaff, role: "registrar_head" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(calls("GET", "/fee-schedules")).toHaveLength(0)
  })
})
