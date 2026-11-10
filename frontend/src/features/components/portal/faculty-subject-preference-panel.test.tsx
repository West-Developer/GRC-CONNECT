import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { toast } from "sonner"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { FacultyInputWorkspace } from "@/features/components/portal/faculty-input-workspace"
import { renderWithSession } from "@/tests/render-app"

vi.mock("sonner", () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

const session = {
  userId: "5",
  displayName: "Faculty",
  role: "faculty" as const,
  signedInAt: "2026-07-29T12:00:00Z",
}

const catalog = {
  data: [
    {
      curriculum_id: 11,
      program_id: 2,
      program_code: "BSIT",
      program_name: "Information Technology",
      curriculum_name: "2024–2029",
      effective_school_year: "2024-2029",
      version_label: "new",
      semesters: [
        {
          semester: "1st",
          subjects: [
            {
              id: 501,
              code: "LEAD 1",
              title: "Leadership Seminar 1",
              units: 1.5,
            },
          ],
        },
        { semester: "2nd", subjects: [] },
      ],
    },
  ],
} as const

const pairedCatalog = {
  data: [
    {
      curriculum_id: 11,
      program_id: 2,
      program_code: "BSIT",
      program_name: "Information Technology",
      curriculum_name: "2024–2029",
      effective_school_year: "2024-2029",
      version_label: "new",
      semesters: [
        {
          semester: "1st",
          subjects: [
            {
              id: 501,
              code: "ITC",
              title: "Intro to Computing",
              units: 2,
              paired_subject_id: 502,
            },
            {
              id: 502,
              code: "ITCL",
              title: "Intro to Computing Lab",
              units: 1,
              paired_subject_id: 501,
            },
            {
              id: 503,
              code: "PROG 1",
              title: "Programming 1",
              units: 3,
              paired_subject_id: null,
            },
          ],
        },
        { semester: "2nd", subjects: [] },
      ],
    },
  ],
} as const

const specialization = {
  type: "faculty-specialization",
  id: 9,
  professor_id: 5,
  subject_id: 501,
  proficiency: "primary",
  proficiency_label: "Primary",
  source: "declared",
  notes: null,
  status: "approved",
  status_label: "Approved",
  decided_at: null,
  decision_reason: null,
} as const

function url(input: RequestInfo | URL): string {
  return typeof input === "string"
    ? input
    : input instanceof URL
      ? input.toString()
      : input.url
}

describe("FacultySubjectPreferencePanel", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => vi.stubGlobal("fetch", fetchMock))
  afterEach(() => vi.unstubAllGlobals())

  it("shows the subject picker, optional rank, and specialization proficiency", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input) => {
      const requestUrl = url(input)
      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(catalog)))
      if (requestUrl.endsWith("/faculty-specializations"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: [specialization] })),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<FacultyInputWorkspace />, { session })

    await user.click(
      await screen.findByRole("tab", { name: "Subject preferences" }),
    )

    expect(await screen.findByLabelText("Preferred subject")).toBeEnabled()
    expect(screen.getByLabelText("Preference rank")).not.toBeRequired()
    expect(
      screen.getByRole("combobox", { name: "Proficiency" }),
    ).toBeInTheDocument()
  })

  it("appends a preference without a rank and records its specialization", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)

      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(catalog)))
      if (
        requestUrl.endsWith("/faculty-curriculum-subject-preferences") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "faculty_curriculum_subject_preference",
                id: 6,
                professor_id: 5,
                curriculum_id: 11,
                semester: "1st",
                subject_id: 501,
                rank: 4,
                origin: "declared",
              },
            }),
          ),
        )
      if (
        requestUrl.endsWith("/faculty-specializations") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(JSON.stringify({ data: specialization })),
        )

      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<FacultyInputWorkspace />, { session })
    await user.click(
      await screen.findByRole("tab", { name: "Subject preferences" }),
    )
    await user.click(await screen.findByLabelText("Preferred subject"))
    await user.click(await screen.findByText("LEAD 1 — Leadership Seminar 1"))
    await user.click(
      screen.getByRole("button", { name: "Save subject preference" }),
    )

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining(
          "/api/v1/faculty-curriculum-subject-preferences",
        ),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({
            curriculum_id: 11,
            semester: "1st",
            subject_id: 501,
          }),
        }),
      )
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-specializations"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ subject_id: 501, proficiency: "secondary" }),
        }),
      )
    })
  })

  it("keeps the preference saved and shows a distinct message when only the specialization write fails", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)

      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(catalog)))
      if (
        requestUrl.endsWith("/faculty-curriculum-subject-preferences") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "faculty_curriculum_subject_preference",
                id: 6,
                professor_id: 5,
                curriculum_id: 11,
                semester: "1st",
                subject_id: 501,
                rank: 4,
                origin: "declared",
              },
            }),
          ),
        )
      if (
        requestUrl.endsWith("/faculty-specializations") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Invalid",
                errors: {
                  subject_id: ["This subject is outside your college."],
                },
                request_id: "request-9",
              },
            }),
            { status: 422 },
          ),
        )

      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<FacultyInputWorkspace />, { session })
    await user.click(
      await screen.findByRole("tab", { name: "Subject preferences" }),
    )
    await user.click(await screen.findByLabelText("Preferred subject"))
    await user.click(await screen.findByText("LEAD 1 — Leadership Seminar 1"))
    await user.click(
      screen.getByRole("button", { name: "Save subject preference" }),
    )

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-specializations"),
        expect.objectContaining({ method: "POST" }),
      )
    })

    // The preference write succeeded, so its success path always runs: the
    // form resets (the subject picker clears) regardless of the
    // specialization write's outcome.
    expect(screen.getByLabelText("Preferred subject")).toHaveValue("")
    expect(
      await screen.findByText(
        "Preference saved, but the proficiency could not be recorded. Try setting it again.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByText(
        "Subject preference could not be saved. Check the connection and try again.",
      ),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByText("This subject is outside your college."),
    ).not.toBeInTheDocument()
  })

  it("maps a 422 validation error to a FieldError on the form, not a generic banner", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(catalog)))
      if (
        requestUrl.endsWith("/faculty-curriculum-subject-preferences") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "Invalid",
                errors: {
                  rank: [
                    "This rank is already in use for this curriculum semester.",
                  ],
                },
                request_id: "request-5",
              },
            }),
            { status: 422 },
          ),
        )
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    renderWithSession(<FacultyInputWorkspace />, { session })

    await user.click(
      await screen.findByRole("tab", { name: "Subject preferences" }),
    )
    const subjectPicker = await screen.findByLabelText("Preferred subject")
    await user.click(subjectPicker)
    await user.click(await screen.findByText("LEAD 1 — Leadership Seminar 1"))
    await user.click(
      screen.getByRole("button", { name: "Save subject preference" }),
    )

    expect(
      await screen.findByText(
        "This rank is already in use for this curriculum semester.",
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByText(
        "Subject preference could not be saved. Check the connection and try again.",
      ),
    ).not.toBeInTheDocument()
  })

  const savedPreference = (id: number, subjectId: number, rank: number) => ({
    type: "faculty_curriculum_subject_preference",
    id,
    professor_id: 5,
    curriculum_id: 11,
    semester: "1st",
    subject_id: subjectId,
    rank,
    origin: "declared",
  })

  function mockSavedPreferences(
    preferences: unknown[],
    specializations: unknown[],
    catalogBody: unknown = pairedCatalog,
  ) {
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(catalogBody)))
      if (requestUrl.endsWith("/faculty-curriculum-subject-preferences"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: preferences })),
        )
      if (
        requestUrl.endsWith("/faculty-specializations") &&
        init?.method === "POST"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...specialization, id: 77, subject_id: 503 },
            }),
            { status: 201 },
          ),
        )
      if (requestUrl.endsWith("/faculty-specializations"))
        return Promise.resolve(
          new Response(JSON.stringify({ data: specializations })),
        )
      if (requestUrl.endsWith("/proficiency") && init?.method === "PATCH")
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...specialization,
                proficiency: "secondary",
                proficiency_label: "Secondary",
                status: "pending",
                status_label: "Pending",
              },
            }),
          ),
        )

      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  }

  it("shows one table: no separate Declared specializations table, and the proficiency is a dropdown", async () => {
    const user = userEvent.setup()
    mockSavedPreferences([savedPreference(101, 501, 1)], [specialization])
    renderWithSession(<FacultyInputWorkspace />, { session })

    await user.click(await screen.findByRole("tab", { name: "Subject preferences" }))

    const saved = await screen.findByRole("table", {
      name: "Saved curriculum subject preferences",
    })
    expect(
      screen.queryByRole("table", { name: "Declared specializations" }),
    ).not.toBeInTheDocument()
    expect(screen.queryByText("Declared specializations")).not.toBeInTheDocument()
    expect(
      await within(saved).findByRole("combobox", {
        name: "Proficiency for ITC",
      }),
    ).toHaveTextContent("Primary")
  })

  it("changes the proficiency of a declared specialization from the table dropdown", async () => {
    const user = userEvent.setup()
    mockSavedPreferences([savedPreference(101, 501, 1)], [specialization])
    renderWithSession(<FacultyInputWorkspace />, { session })

    await user.click(await screen.findByRole("tab", { name: "Subject preferences" }))
    await user.click(
      await screen.findByRole("combobox", { name: "Proficiency for ITC" }),
    )
    await user.click(await screen.findByRole("option", { name: "Secondary" }))

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-specializations/9/proficiency"),
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({ proficiency: "secondary" }),
        }),
      )
      expect(toast.success).toHaveBeenCalledWith("Proficiency updated.")
    })
  })

  it("lets a subject with no proficiency yet be given one, and leaves a seeded proficiency read-only", async () => {
    const user = userEvent.setup()
    mockSavedPreferences(
      [savedPreference(101, 501, 1), savedPreference(103, 503, 2)],
      [{ ...specialization, source: "seeded" }],
    )
    renderWithSession(<FacultyInputWorkspace />, { session })

    await user.click(await screen.findByRole("tab", { name: "Subject preferences" }))
    // ITC's proficiency is seeded: plain text, no dropdown.
    await screen.findByRole("combobox", { name: "Proficiency for PROG 1" })
    expect(
      screen.queryByRole("combobox", { name: "Proficiency for ITC" }),
    ).not.toBeInTheDocument()

    await user.click(
      screen.getByRole("combobox", { name: "Proficiency for PROG 1" }),
    )
    await user.click(await screen.findByRole("option", { name: "Primary" }))

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-specializations"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ subject_id: 503, proficiency: "primary" }),
        }),
      )
    })
  })

  it("automatically includes and saves paired laboratory subject with sequential rank and matching proficiency", async () => {
    const user = userEvent.setup()
    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(pairedCatalog)))
      if (
        requestUrl.endsWith("/faculty-curriculum-subject-preferences") &&
        init?.method === "POST"
      ) {
        const body = JSON.parse(String(init.body))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "faculty_curriculum_subject_preference",
                id: body.subject_id === 501 ? 101 : 102,
                professor_id: 5,
                curriculum_id: body.curriculum_id,
                semester: body.semester,
                subject_id: body.subject_id,
                rank: body.rank ?? 1,
                origin: "declared",
              },
            }),
          ),
        )
      }
      if (
        requestUrl.endsWith("/faculty-specializations") &&
        init?.method === "POST"
      ) {
        const body = JSON.parse(String(init.body))
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...specialization,
                id: body.subject_id === 501 ? 201 : 202,
                subject_id: body.subject_id,
                proficiency: body.proficiency,
              },
            }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(<FacultyInputWorkspace />, { session })
    await user.click(await screen.findByRole("tab", { name: "Subject preferences" }))

    // Select ITC (paired with ITCL)
    await user.click(await screen.findByLabelText("Preferred subject"))
    await user.click(await screen.findByText("ITC — Intro to Computing"))

    // Banner indicates auto-joint pairing
    expect(
      await screen.findByText(/ITCL — Intro to Computing Lab will automatically be included\./),
    ).toBeInTheDocument()

    // Save
    await user.click(screen.getByRole("button", { name: "Save subject preference" }))

    await waitFor(() => {
      // Primary ITC preference
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-curriculum-subject-preferences"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ curriculum_id: 11, semester: "1st", subject_id: 501 }),
        }),
      )
      // Paired ITCL preference
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-curriculum-subject-preferences"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ curriculum_id: 11, semester: "1st", subject_id: 502 }),
        }),
      )
      // Both specializations created with matching proficiency
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-specializations"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ subject_id: 501, proficiency: "secondary" }),
        }),
      )
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-specializations"),
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ subject_id: 502, proficiency: "secondary" }),
        }),
      )
      expect(toast.success).toHaveBeenCalledWith(
        "Subject preferences saved successfully (Lecture & Laboratory paired).",
      )
    })
  })

  it("supports edit mode, selecting multiple preferences, and batch deleting with confirmation modal", async () => {
    const user = userEvent.setup()
    const savedPreferences = [
      {
        type: "faculty_curriculum_subject_preference",
        id: 101,
        professor_id: 5,
        curriculum_id: 11,
        semester: "1st",
        subject_id: 501,
        rank: 1,
        origin: "declared",
      },
      {
        type: "faculty_curriculum_subject_preference",
        id: 102,
        professor_id: 5,
        curriculum_id: 11,
        semester: "1st",
        subject_id: 502,
        rank: 2,
        origin: "declared",
      },
    ]

    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(pairedCatalog)))
      if (
        requestUrl.endsWith("/faculty-curriculum-subject-preferences") &&
        (!init || init.method === "GET")
      )
        return Promise.resolve(new Response(JSON.stringify({ data: savedPreferences })))
      if (
        requestUrl.includes("/faculty-curriculum-subject-preferences/") &&
        init?.method === "DELETE"
      )
        return Promise.resolve(new Response(null, { status: 204 }))

      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(<FacultyInputWorkspace />, { session })
    await user.click(await screen.findByRole("tab", { name: "Subject preferences" }))

    // Initially "Edit" button is present
    const editToggle = await screen.findByRole("button", { name: "Edit" })

    // The saved table has no per-row Actions column: editing and removing go
    // through the Edit toggle above it.
    const savedTable = await screen.findByRole("table", {
      name: "Saved curriculum subject preferences",
    })
    expect(
      within(savedTable).queryByRole("columnheader", { name: "Actions" }),
    ).not.toBeInTheDocument()
    expect(
      within(savedTable).queryByRole("columnheader", { name: "Source" }),
    ).not.toBeInTheDocument()
    expect(within(savedTable).queryByText("Declared")).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Edit subject preference" }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole("button", { name: "Remove subject preference" }),
    ).not.toBeInTheDocument()

    await user.click(editToggle)
    const specializationReadsBeforeDelete = fetchMock.mock.calls.filter(
      ([input]) => url(input).includes("/faculty-specializations"),
    ).length

    // Now in edit mode, toggle changes to "Done"
    expect(screen.getByRole("button", { name: "Done" })).toBeInTheDocument()

    // Select row checkboxes
    const checkboxes = screen.getAllByRole("checkbox")
    expect(checkboxes.length).toBeGreaterThanOrEqual(2)
    await user.click(checkboxes[1]) // first row checkbox

    // "Delete Selected (1)" button should appear
    const deleteSelectedBtn = await screen.findByRole("button", { name: /Delete Selected \(1\)/ })
    expect(deleteSelectedBtn).toBeInTheDocument()

    // Click delete selected
    await user.click(deleteSelectedBtn)

    // Modal with Filipino text should appear
    expect(
      await screen.findByText("Sigurado ka bang gusto mong idelete ang mga napiling subject?"),
    ).toBeInTheDocument()

    // Click confirmation delete
    const confirmDeleteBtn = screen.getByRole("button", { name: "Delete" })
    await user.click(confirmDeleteBtn)

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-curriculum-subject-preferences/101"),
        expect.objectContaining({ method: "DELETE" }),
      )
      expect(toast.success).toHaveBeenCalledWith(
        "Selected subject preferences deleted successfully.",
      )
    })
    // The server drops the declared specialization along with the preference,
    // so the specializations list is read again.
    await waitFor(() => {
      expect(
        fetchMock.mock.calls.filter(([input]) =>
          url(input).includes("/faculty-specializations"),
        ).length,
      ).toBeGreaterThan(specializationReadsBeforeDelete)
    })
  })

  it("supports inline subject replacement in edit mode", async () => {
    const user = userEvent.setup()
    const savedPreferences = [
      {
        type: "faculty_curriculum_subject_preference",
        id: 101,
        professor_id: 5,
        curriculum_id: 11,
        semester: "1st",
        subject_id: 501,
        rank: 1,
        origin: "declared",
      },
    ]

    fetchMock.mockImplementation((input, init) => {
      const requestUrl = url(input)
      if (requestUrl.endsWith("/faculty-preference-catalog"))
        return Promise.resolve(new Response(JSON.stringify(pairedCatalog)))
      if (
        requestUrl.endsWith("/faculty-curriculum-subject-preferences") &&
        (!init || init.method === "GET")
      )
        return Promise.resolve(new Response(JSON.stringify({ data: savedPreferences })))
      if (
        requestUrl.includes("/faculty-curriculum-subject-preferences/101") &&
        init?.method === "PATCH"
      )
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...savedPreferences[0],
                subject_id: 503,
              },
            }),
          ),
        )

      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })

    renderWithSession(<FacultyInputWorkspace />, { session })
    await user.click(await screen.findByRole("tab", { name: "Subject preferences" }))

    // Enter edit mode
    await user.click(await screen.findByRole("button", { name: "Edit" }))

    // Click on the subject to replace
    const replaceSubjectBtn = await screen.findByTitle("Click to replace subject")
    await user.click(replaceSubjectBtn)

    // Replacement dialog opens
    expect(await screen.findByText("Replace Subject Preference")).toBeInTheDocument()

    // Pick PROG 1
    const picker = screen.getByLabelText("Replacement Subject")
    await user.click(picker)
    await user.click(await screen.findByText("PROG 1 — Programming 1"))

    // Confirm
    await user.click(screen.getByRole("button", { name: "Confirm Replacement" }))

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        expect.stringContaining("/api/v1/faculty-curriculum-subject-preferences/101"),
        expect.objectContaining({
          method: "PATCH",
          body: JSON.stringify({
            curriculum_id: 11,
            semester: "1st",
            subject_id: 503,
            rank: 1,
          }),
        }),
      )
      expect(toast.success).toHaveBeenCalledWith(
        "Subject preference replaced successfully.",
      )
    })
  })
})
