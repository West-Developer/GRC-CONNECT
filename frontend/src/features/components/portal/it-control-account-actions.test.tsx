import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { toast } from "sonner"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import {
  ItControlAccountActions,
  type ManagedAccount,
} from "@/features/components/portal/it-control-account-actions"
import { renderWithSession } from "@/tests/render-app"

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}))

const account: ManagedAccount = {
  id: 32,
  name: "Avery Student",
  firstName: "Avery",
  lastName: "Student",
  suffix: null,
  email: "avery.student@grc.test",
  isArchived: false,
}

function accountResponse(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      type: "it-control-account",
      id: 32,
      role: "student",
      name: "Avery Student",
      first_name: "Avery",
      last_name: "Student",
      suffix: null,
      email: "avery.student@grc.test",
      status: "active",
      is_archived: false,
      archived_at: null,
      ...overrides,
    },
  }
}

function errorResponse(
  status: number,
  message: string,
  errors?: Record<string, string[]>,
) {
  return new Response(
    JSON.stringify({
      error: {
        code: status === 409 ? "CONFLICT" : "VALIDATION_FAILED",
        message,
        errors: errors ?? {},
        request_id: "r1",
      },
    }),
    { status },
  )
}

function urlOf(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

function bodyOf(init: RequestInit | undefined): Record<string, unknown> {
  return typeof init?.body === "string"
    ? (JSON.parse(init.body) as Record<string, unknown>)
    : {}
}

function renderActions(managed: ManagedAccount = account) {
  return renderWithSession(<ItControlAccountActions account={managed} />, {
    session: {
      userId: "it-1",
      displayName: "IT Control",
      role: "it_admin",
      signedInAt: "2026-10-16T00:00:00Z",
    },
  })
}

describe("ItControlAccountActions", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.mocked(toast.success).mockClear()
    vi.mocked(toast.error).mockClear()
    fetchMock.mockReset()
    vi.stubGlobal("fetch", fetchMock)
  })

  afterEach(() => vi.unstubAllGlobals())

  function requests(method: string) {
    return fetchMock.mock.calls.filter(([, init]) => init?.method === method)
  }

  it("changes the managed account password with confirmation and clears the form on close", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify(accountResponse())))
    const user = userEvent.setup()
    renderActions()
    await user.click(
      screen.getByRole("button", { name: "Change password for Avery Student" }),
    )
    const dialog = await screen.findByRole("dialog", {
      name: "Change password",
    })
    const password = within(dialog).getByLabelText("New password")
    expect(password).toHaveAttribute("type", "password")
    await user.type(password, "A-strong-password-2026!")
    await user.type(
      within(dialog).getByLabelText("Confirm new password"),
      "A-strong-password-2026!",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Change password" }),
    )
    await waitFor(() => expect(requests("POST")).toHaveLength(1))
    expect(urlOf(requests("POST")[0][0])).toContain(
      "/api/v1/it-control/accounts/32/password",
    )
    expect(bodyOf(requests("POST")[0][1])).toEqual({
      password: "A-strong-password-2026!",
      password_confirmation: "A-strong-password-2026!",
    })
    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    )
    expect(toast.success).toHaveBeenCalledWith(
      expect.stringContaining("All existing sessions were signed out"),
    )
    await user.click(
      screen.getByRole("button", { name: "Change password for Avery Student" }),
    )
    expect(
      within(await screen.findByRole("dialog")).getByLabelText("New password"),
    ).toHaveValue("")
  })

  it("blocks weak or mismatched passwords before sending a request", async () => {
    const user = userEvent.setup()
    renderActions()
    await user.click(
      screen.getByRole("button", { name: "Change password for Avery Student" }),
    )
    const dialog = await screen.findByRole("dialog")
    await user.type(within(dialog).getByLabelText("New password"), "weak")
    await user.type(
      within(dialog).getByLabelText("Confirm new password"),
      "different",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Change password" }),
    )
    expect(
      await within(dialog).findByText("Use at least 8 characters."),
    ).toBeInTheDocument()
    expect(
      within(dialog).getByText("Passwords do not match."),
    ).toBeInTheDocument()
    expect(requests("POST")).toHaveLength(0)
  })

  it("keeps the dialog open and displays account setup refusal without claiming success", async () => {
    fetchMock.mockResolvedValue(
      errorResponse(
        409,
        "Only an active account that has completed setup can have its password changed.",
      ),
    )
    const user = userEvent.setup()
    renderActions()
    await user.click(
      screen.getByRole("button", { name: "Change password for Avery Student" }),
    )
    const dialog = await screen.findByRole("dialog")
    await user.type(
      within(dialog).getByLabelText("New password"),
      "A-strong-password-2026!",
    )
    await user.type(
      within(dialog).getByLabelText("Confirm new password"),
      "A-strong-password-2026!",
    )
    await user.click(
      within(dialog).getByRole("button", { name: "Change password" }),
    )
    expect(await within(dialog).findByRole("alert")).toHaveTextContent(
      "Only an active account",
    )
    expect(toast.success).not.toHaveBeenCalled()
    await user.click(within(dialog).getByRole("button", { name: "Cancel" }))
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument()
  })

  it("does not offer password changes on an archived account", () => {
    renderActions({ ...account, isArchived: true })
    expect(
      screen.queryByRole("button", {
        name: "Change password for Avery Student",
      }),
    ).not.toBeInTheDocument()
  })

  it("sends only the fields that changed when an account is edited", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(accountResponse({ last_name: "Santos" }))),
    )
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole("button", { name: "Edit Avery Student" }))
    const dialog = await screen.findByRole("dialog", { name: "Edit account" })
    // The middle name is not editable here: only Admission and the student ever see it.
    expect(within(dialog).queryByLabelText(/middle/i)).not.toBeInTheDocument()
    const lastName = within(dialog).getByLabelText("Last name")
    expect(lastName).toHaveValue("Student")
    await user.clear(lastName)
    await user.type(lastName, "Santos")
    await user.click(
      within(dialog).getByRole("button", { name: "Save changes" }),
    )

    await waitFor(() => expect(requests("PATCH")).toHaveLength(1))
    const [url, init] = requests("PATCH")[0]
    expect(urlOf(url)).toContain("/api/v1/it-control/accounts/32")
    expect(bodyOf(init)).toEqual({ last_name: "Santos" })
    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    )
    expect(toast.success).toHaveBeenCalled()
  })

  it("clears the suffix by sending null", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify(accountResponse())))
    const user = userEvent.setup()
    renderActions({ ...account, suffix: "Jr." })

    await user.click(screen.getByRole("button", { name: "Edit Avery Student" }))
    const dialog = await screen.findByRole("dialog", { name: "Edit account" })
    await user.clear(within(dialog).getByLabelText("Suffix"))
    await user.click(
      within(dialog).getByRole("button", { name: "Save changes" }),
    )

    await waitFor(() => expect(requests("PATCH")).toHaveLength(1))
    expect(bodyOf(requests("PATCH")[0][1])).toEqual({ suffix: null })
  })

  it("does not send an edit that changes nothing", async () => {
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole("button", { name: "Edit Avery Student" }))
    const dialog = await screen.findByRole("dialog", { name: "Edit account" })
    await user.click(
      within(dialog).getByRole("button", { name: "Save changes" }),
    )

    expect(
      await within(dialog).findByText("Change at least one field to save."),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it("shows the server's message beside the field and keeps the dialog open", async () => {
    fetchMock.mockResolvedValue(
      errorResponse(422, "The submitted data is invalid.", {
        email: ["The email has already been taken."],
      }),
    )
    const user = userEvent.setup()
    renderActions()

    await user.click(screen.getByRole("button", { name: "Edit Avery Student" }))
    const dialog = await screen.findByRole("dialog", { name: "Edit account" })
    const email = within(dialog).getByLabelText("Email address")
    await user.clear(email)
    await user.type(email, "taken@grc.test")
    await user.click(
      within(dialog).getByRole("button", { name: "Save changes" }),
    )

    expect(
      await within(dialog).findByText("The email has already been taken."),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("dialog", { name: "Edit account" }),
    ).toBeInTheDocument()
    expect(toast.success).not.toHaveBeenCalled()
  })

  it("archives an account only after it is confirmed", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify(
          accountResponse({
            status: "disabled",
            is_archived: true,
            archived_at: "2026-10-16T08:00:00Z",
          }),
        ),
      ),
    )
    const user = userEvent.setup()
    renderActions()

    await user.click(
      screen.getByRole("button", { name: "Archive Avery Student" }),
    )
    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText("Archive Avery Student?"),
    ).toBeInTheDocument()
    expect(fetchMock).not.toHaveBeenCalled()

    // Backing out sends nothing.
    await user.click(within(dialog).getByRole("button", { name: "Cancel" }))
    expect(fetchMock).not.toHaveBeenCalled()

    await user.click(
      screen.getByRole("button", { name: "Archive Avery Student" }),
    )
    await user.click(
      within(await screen.findByRole("alertdialog")).getByRole("button", {
        name: "Archive account",
      }),
    )

    await waitFor(() => expect(requests("POST")).toHaveLength(1))
    expect(urlOf(requests("POST")[0][0])).toContain(
      "/api/v1/it-control/accounts/32/archive",
    )
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Archived Avery Student."),
    )
  })

  it("restores an archived account and offers no edit while it is archived", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify(accountResponse())))
    const user = userEvent.setup()
    renderActions({ ...account, isArchived: true })

    expect(
      screen.queryByRole("button", { name: "Edit Avery Student" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Archive Avery Student" }),
    ).not.toBeInTheDocument()
    await user.click(
      screen.getByRole("button", { name: "Restore Avery Student" }),
    )
    await user.click(
      within(await screen.findByRole("alertdialog")).getByRole("button", {
        name: "Restore account",
      }),
    )

    await waitFor(() => expect(requests("POST")).toHaveLength(1))
    expect(urlOf(requests("POST")[0][0])).toContain(
      "/api/v1/it-control/accounts/32/restore",
    )
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Restored Avery Student."),
    )
  })

  it("deletes an unused account after confirmation", async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ data: { id: 32, deleted: true } })),
    )
    const user = userEvent.setup()
    renderActions()

    await user.click(
      screen.getByRole("button", { name: "Delete Avery Student" }),
    )
    const dialog = await screen.findByRole("alertdialog")
    expect(
      within(dialog).getByText("Delete Avery Student permanently?"),
    ).toBeInTheDocument()
    await user.click(
      within(dialog).getByRole("button", { name: "Delete account" }),
    )

    await waitFor(() => expect(requests("DELETE")).toHaveLength(1))
    expect(urlOf(requests("DELETE")[0][0])).toContain(
      "/api/v1/it-control/accounts/32",
    )
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Deleted Avery Student."),
    )
  })

  it("tells IT Control to archive when the server refuses to delete an account that has records", async () => {
    fetchMock.mockResolvedValue(
      errorResponse(
        409,
        "This account has records, so it cannot be deleted. Archive it instead.",
      ),
    )
    const user = userEvent.setup()
    renderActions()

    await user.click(
      screen.getByRole("button", { name: "Delete Avery Student" }),
    )
    await user.click(
      within(await screen.findByRole("alertdialog")).getByRole("button", {
        name: "Delete account",
      }),
    )

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "This account has records, so it cannot be deleted. Archive it instead.",
      ),
    )
    expect(toast.success).not.toHaveBeenCalled()
    // The dialog stays up so the answer is not lost; Cancel closes it.
    expect(screen.getByRole("alertdialog")).toBeInTheDocument()
  })
})
