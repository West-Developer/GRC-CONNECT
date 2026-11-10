import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FacultyInputWorkspace } from "@/features/components/portal/faculty-input-workspace"
import { renderWithSession } from "@/tests/render-app"

const session = {
  userId: "5",
  displayName: "Faculty",
  role: "faculty" as const,
  signedInAt: "2026-07-29T12:00:00Z",
}

const availability = {
  type: "faculty_availability",
  id: 4,
  professor_id: 5,
  day_of_week: 1,
  starts_at_time: "08:00:00",
  ends_at_time: "10:00:00",
} as const

function url(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("FacultyAvailabilityPanel", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("renders recurring Monday through Saturday availability from the faculty API", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (
        requestUrl.endsWith("/faculty-availabilities") &&
        (!init?.method || init.method === "GET")
      )
        return Promise.resolve(
          new Response(JSON.stringify({ data: [availability] })),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<FacultyInputWorkspace />, { session })

    expect(
      await screen.findByRole("tab", { name: "Availability window" }),
    ).toHaveAttribute("data-state", "active")
    expect(
      await screen.findByRole("table", { name: /availability windows/i }),
    ).toBeInTheDocument()
    await user.click(screen.getByRole("combobox", { name: "Day" }))
    expect(
      screen.queryByRole("option", { name: "Sunday" }),
    ).not.toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining("/api/v1/faculty-availabilities"),
      expect.objectContaining({ method: "GET" }),
    )
  })

  it("edits and confirms removal of an availability before deleting it", async () => {
    const user = userEvent.setup()
    let patchBody: unknown = null
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (
        requestUrl.endsWith("/faculty-availabilities") &&
        (!init?.method || init.method === "GET")
      )
        return Promise.resolve(
          new Response(JSON.stringify({ data: [availability] })),
        )
      if (
        requestUrl.endsWith("/faculty-availabilities/4") &&
        init?.method === "PATCH"
      ) {
        patchBody = typeof init.body === "string" ? JSON.parse(init.body) : null
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...availability, starts_at_time: "09:00:00" },
            }),
          ),
        )
      }
      if (
        requestUrl.endsWith("/faculty-availabilities/4") &&
        init?.method === "DELETE"
      )
        return Promise.resolve(new Response(null, { status: 204 }))
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<FacultyInputWorkspace />, { session })

    await user.click(
      await screen.findByRole("button", { name: "Edit availability" }),
    )
    expect(
      screen.getByRole("dialog", { name: "Edit availability window" }),
    ).toBeInTheDocument()
    await user.clear(screen.getByLabelText("Start time"))
    await user.type(screen.getByLabelText("Start time"), "0900")
    await user.click(
      screen.getByRole("button", { name: "Update availability" }),
    )

    await waitFor(() => {
      expect(patchBody).toMatchObject({ starts_at_time: "09:00:00" })
    })

    await user.click(
      await screen.findByRole("button", { name: "Remove availability" }),
    )
    expect(screen.getByRole("alertdialog")).toHaveTextContent(
      "Remove availability",
    )
    await user.click(screen.getByRole("button", { name: "Confirm removal" }))

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/faculty-availabilities/4"),
        expect.objectContaining({ method: "DELETE" }),
      )
    })
  })
})
