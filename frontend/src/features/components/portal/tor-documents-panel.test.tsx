import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import {
  StudentTorUpload,
  TorUploadsCard,
} from "@/features/components/portal/tor-documents-panel"
import { renderWithSession } from "@/tests/render-app"

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const studentSession = {
  userId: "4",
  displayName: "Test Student",
  role: "student" as const,
  signedInAt: "2026-10-02T00:00:00Z",
}

const chairSession = {
  userId: "12",
  displayName: "Prof. Chair",
  role: "program_chair" as const,
  signedInAt: "2026-10-02T00:00:00Z",
}

function tor(overrides: Record<string, unknown> = {}) {
  return {
    type: "tor_document",
    id: 1,
    student_id: 30,
    student_number: "2026-0002",
    student_name: "Maria Santos",
    original_name: "maria-tor.pdf",
    mime_type: "application/pdf",
    size_bytes: 2_621_440,
    uploaded_at: "2026-10-01T04:00:00Z",
    ...overrides,
  }
}

function paginated(entries: unknown[]) {
  return {
    data: entries,
    links: { first: "http://x/1", last: "http://x/1", prev: null, next: null },
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 50,
      total: entries.length,
    },
  }
}

describe("StudentTorUpload", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("lists the student's uploaded files and says so when there are none", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(paginated([])))),
    )
    renderWithSession(<StudentTorUpload />, { session: studentSession })

    expect(
      await screen.findByText("You have not uploaded a TOR yet."),
    ).toBeInTheDocument()
  })

  it("uploads a PDF as a multipart file and shows it in the list", async () => {
    const user = userEvent.setup()
    let uploaded: RequestInit | undefined
    let files = [] as unknown[]
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/tor-documents") && init?.method === "POST") {
        uploaded = init
        files = [tor({ student_id: 4, original_name: "my-tor.pdf" })]
        return Promise.resolve(
          new Response(JSON.stringify({ data: files[0] }), { status: 201 }),
        )
      }
      return Promise.resolve(new Response(JSON.stringify(paginated(files))))
    })
    renderWithSession(<StudentTorUpload />, { session: studentSession })
    await screen.findByText("You have not uploaded a TOR yet.")

    await user.upload(
      screen.getByLabelText("Choose a TOR file"),
      new File(["%PDF-1.4"], "my-tor.pdf", { type: "application/pdf" }),
    )

    expect(await screen.findByText(/TOR uploaded/)).toBeInTheDocument()
    expect(await screen.findByText("my-tor.pdf")).toBeInTheDocument()
    // A multipart body: the browser sets the boundary, so no JSON content type is forced.
    expect(uploaded?.body).toBeInstanceOf(FormData)
    expect((uploaded?.body as FormData).get("file")).toBeInstanceOf(File)
    expect(
      (uploaded?.headers as Record<string, string>)["Content-Type"],
    ).toBeUndefined()
  })

  it("refuses a file that is not a PDF, JPG or PNG without calling the server", async () => {
    const user = userEvent.setup({ applyAccept: false })
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(paginated([])))),
    )
    renderWithSession(<StudentTorUpload />, { session: studentSession })
    await screen.findByText("You have not uploaded a TOR yet.")

    await user.upload(
      screen.getByLabelText("Choose a TOR file"),
      new File(["x"], "tor.docx", { type: "application/msword" }),
    )

    expect(
      await screen.findByText("Upload the TOR as a PDF, JPG or PNG file."),
    ).toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(([, init]) => init?.method === "POST"),
    ).toBe(false)
  })

  it("shows the server's reason when the upload is refused", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      if (requestUrl(input).includes("/tor-documents") && init?.method === "POST")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "The submitted data is invalid.",
                errors: { file: ["Only transferees and returnees upload a Transcript of Records."] },
                request_id: "r-1",
              },
            }),
            { status: 422 },
          ),
        )
      return Promise.resolve(new Response(JSON.stringify(paginated([]))))
    })
    renderWithSession(<StudentTorUpload />, { session: studentSession })
    await screen.findByText("You have not uploaded a TOR yet.")

    await user.upload(
      screen.getByLabelText("Choose a TOR file"),
      new File(["%PDF"], "tor.pdf", { type: "application/pdf" }),
    )

    expect(
      await screen.findByText(
        "Only transferees and returnees upload a Transcript of Records.",
      ),
    ).toBeInTheDocument()
  })

  it("removes one of the student's own files", async () => {
    const user = userEvent.setup()
    let deleted = false
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/tor-documents/1") && init?.method === "DELETE") {
        deleted = true
        return Promise.resolve(new Response(null, { status: 204 }))
      }
      return Promise.resolve(
        new Response(
          JSON.stringify(
            paginated(deleted ? [] : [tor({ student_id: 4, original_name: "old.pdf" })]),
          ),
        ),
      )
    })
    renderWithSession(<StudentTorUpload />, { session: studentSession })

    await user.click(await screen.findByRole("button", { name: "Remove old.pdf" }))

    expect(
      await screen.findByText("You have not uploaded a TOR yet."),
    ).toBeInTheDocument()
    expect(deleted).toBe(true)
  })

  it("has no detectable accessibility violations", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(JSON.stringify(paginated([tor({ student_id: 4 })]))),
      ),
    )
    const { container } = renderWithSession(<StudentTorUpload />, {
      session: studentSession,
    })
    await screen.findByText("maria-tor.pdf")

    expect(await axe(container)).toHaveNoViolations()
  })
})

describe("TorUploadsCard", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it("groups the uploads by student and hands the chosen student to the form", async () => {
    const user = userEvent.setup()
    const onRecordCredit = vi.fn()
    fetchMock.mockImplementation(() =>
      Promise.resolve(
        new Response(
          JSON.stringify(
            paginated([
              tor({ id: 3, original_name: "page-2.pdf" }),
              tor({ id: 2, original_name: "page-1.pdf" }),
              tor({
                id: 1,
                student_id: 31,
                student_number: "2026-0003",
                student_name: "Juan Dela Cruz",
                original_name: "juan.png",
                mime_type: "image/png",
              }),
            ]),
          ),
        ),
      ),
    )
    renderWithSession(<TorUploadsCard onRecordCredit={onRecordCredit} />, {
      session: chairSession,
    })

    const students = await screen.findByRole("list", {
      name: "Students with an uploaded TOR",
    })
    // Two students, Maria's two files together under her.
    expect(
      within(students).getAllByRole("button", { name: /^Record credits for/ }),
    ).toHaveLength(2)
    expect(within(students).getByText("page-1.pdf")).toBeInTheDocument()
    expect(within(students).getByText("page-2.pdf")).toBeInTheDocument()

    await user.click(
      within(students).getByRole("button", {
        name: "Record credits for Maria Santos",
      }),
    )

    expect(onRecordCredit).toHaveBeenCalledWith({
      student_id: 30,
      student_number: "2026-0002",
      student_name: "Maria Santos",
    })
  })

  it("opens a TOR from the authorized file endpoint, not a public link", async () => {
    const user = userEvent.setup()
    const open = vi.spyOn(window, "open").mockReturnValue({} as Window)
    URL.createObjectURL = vi.fn(() => "blob:tor")
    URL.revokeObjectURL = vi.fn()
    let fileRequest: RequestInit | undefined
    fetchMock.mockImplementation((input, init) => {
      const url = requestUrl(input)
      if (url.includes("/tor-documents/2/file")) {
        fileRequest = init
        return Promise.resolve(
          new Response(new Blob(["%PDF"], { type: "application/pdf" })),
        )
      }
      return Promise.resolve(
        new Response(JSON.stringify(paginated([tor({ id: 2 })]))),
      )
    })
    renderWithSession(<TorUploadsCard onRecordCredit={vi.fn()} />, {
      session: chairSession,
    })

    await user.click(await screen.findByRole("button", { name: "View maria-tor.pdf" }))

    await waitFor(() =>
      expect(open).toHaveBeenCalledWith("blob:tor", "_blank", "noopener"),
    )
    expect(fileRequest?.method).toBe("GET")
  })

  it("says so when nobody has uploaded a TOR", async () => {
    fetchMock.mockImplementation(() =>
      Promise.resolve(new Response(JSON.stringify(paginated([])))),
    )
    renderWithSession(<TorUploadsCard onRecordCredit={vi.fn()} />, {
      session: chairSession,
    })

    expect(
      await screen.findByText("No student has uploaded a TOR yet."),
    ).toBeInTheDocument()
  })
})
