import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { axe } from "vitest-axe"

import { ChedReportsWorkspace } from "@/features/components/portal/ched-reports-workspace"
import { renderWithSession } from "@/tests/render-app"

const toastError = vi.hoisted(() => vi.fn())
vi.mock("sonner", () => ({
  toast: { error: toastError, success: vi.fn() },
}))

const registrarHead = {
  userId: "head-1",
  displayName: "Registrar Head",
  role: "registrar_head" as const,
  signedInAt: "2026-10-15T00:00:00Z",
}

const terms = {
  data: [termRow(6, "2025-2026", "2nd", "semester_ongoing")],
}

function termRow(
  id: number,
  schoolYear: string,
  semester: "1st" | "2nd",
  status: "semester_ongoing" | "archived" = "archived",
) {
  return {
    type: "academic-term",
    id,
    school_year: schoolYear,
    semester,
    starts_at: null,
    ends_at: null,
    enrollment_opens_at: null,
    enrollment_closes_at: null,
    add_drop_deadline_at: null,
    grading_deadline_at: null,
    status,
    status_label: status === "archived" ? "Archived" : "Semester Ongoing",
  }
}

const CHED_COLUMNS = [
  { key: "no", label: "No." },
  { key: "program", label: "Programs" },
  { key: "major", label: "Major" },
  { key: "student_number", label: "STUDENT NUMBER" },
  { key: "first_name", label: "FIRST NAME" },
  { key: "middle_name", label: "MIDDLE NAME" },
  { key: "surname", label: "SURNAME" },
  { key: "name_extension", label: "Name Extension" },
  { key: "citizenship", label: "Citizenship" },
  { key: "gender", label: "GENDER" },
  { key: "year_level", label: "Year Level" },
  { key: "subjects", label: "SUBJECTS ENROLLED FOLLOWED BY UNITS" },
  { key: "units", label: "NO. OF UNITS" },
  { key: "remarks", label: "REMARKS" },
]

const NSTP_COLUMNS = [
  { key: "no", label: "No." },
  { key: "serial_no", label: "Serial No." },
  { key: "surname", label: "Surname" },
  { key: "first_name", label: "First Name" },
  { key: "middle_name", label: "Middle Name" },
  { key: "course", label: "Course/Program (Write in full)" },
  { key: "gender", label: "Gender" },
  { key: "birthdate", label: "Birthdate (ex. 11/25/1992)" },
  { key: "street_barangay", label: "Street/Barangay Address" },
  { key: "town_city", label: "Town/City Address" },
  { key: "province", label: "Provincial Address" },
  { key: "contact_number", label: "Contact Number Telephone/Mobile" },
  { key: "email", label: "Email Address" },
]

const SANTOS = {
  no: 1,
  program: "Bachelor of Science in Information Technology",
  major: "",
  student_number: "2025-08-00001",
  first_name: "Ana",
  middle_name: "D.",
  surname: "Santos",
  name_extension: "",
  citizenship: "Filipino",
  gender: "F",
  year_level: 2,
  subjects:
    "Intro to Computing (3), Science, Technology, and Society (3), Fitness (2)",
  units: "8",
  remarks: "",
}

const REYES = {
  no: 1,
  serial_no: "",
  surname: "Reyes",
  first_name: "Maria",
  middle_name: "L.",
  course: "Bachelor of Science in Information Technology",
  gender: "FEMALE",
  birthdate: "11/25/1992",
  street_barangay: "12 Rizal St., Brgy. San Jose",
  town_city: "Caloocan City",
  province: "Metro Manila",
  contact_number: "09171234567",
  email: "maria.reyes@grc.test",
}

function listPayload(
  key: string,
  overrides: {
    total?: number
    lastPage?: number
    page?: number
    missingGender?: number
    demoPopulated?: number
    rows?: Record<string, string | number>[]
  } = {},
) {
  const isChed = key === "enrollment-list"
  const rows = overrides.rows ?? [isChed ? SANTOS : REYES]

  return {
    data: rows,
    meta: {
      current_page: overrides.page ?? 1,
      last_page: overrides.lastPage ?? 1,
      per_page: 25,
      total: overrides.total ?? rows.length,
    },
    report: {
      key,
      title: isChed ? "CHED Enrollment List" : "NSTP Form 2-B — Enrollment",
      term_label: "2nd Term, AY 2025-2026",
      school_year: "2025-2026",
      columns: isChed ? CHED_COLUMNS : NSTP_COLUMNS,
      missing_gender: overrides.missingGender ?? 0,
      demo_populated: overrides.demoPopulated ?? 0,
    },
  }
}

const none = { m: 0, f: 0 }
const summaryPayload = {
  data: {
    campus: "Global Reciprocal Colleges",
    classification: "Private",
    academic_year: "2025-2026",
    semester: "2nd",
    enrollment: {
      "1st": { rotc: none, cwts: { m: 426, f: 406 }, lts: none },
      "2nd": { rotc: none, cwts: { m: 388, f: 377 }, lts: none },
      summer: { rotc: none, cwts: none, lts: none },
    },
    graduates: { rotc: none, cwts: { m: 489, f: 465 }, lts: none },
    missing_gender: 0,
  },
  report: {
    key: "nstp-summary",
    title: "NSTP Form 2-A — Summary",
    term_label: "2nd Term, AY 2025-2026",
    school_year: "2025-2026",
    columns: [],
    missing_gender: 0,
    demo_populated: 0,
  },
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input
  return input instanceof URL ? input.toString() : input.url
}

const VIEW_SANTOS = "View details of Santos, Ana D."

describe("ChedReportsWorkspace", () => {
  const fetchMock = vi.fn<typeof fetch>()
  let list = listPayload("enrollment-list")
  let termsResponse: { data: ReturnType<typeof termRow>[] } = terms
  const urls = () =>
    fetchMock.mock.calls.map(([input]) => requestUrl(input as RequestInfo))

  beforeEach(() => {
    toastError.mockReset()
    list = listPayload("enrollment-list")
    termsResponse = terms
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/export")) {
        return Promise.resolve(new Response("file", { status: 200 }))
      }
      if (url.includes("/ched-reports/nstp-summary")) {
        return Promise.resolve(new Response(JSON.stringify(summaryPayload)))
      }
      if (url.includes("/ched-reports/nstp-enrollment")) {
        return Promise.resolve(
          new Response(JSON.stringify(listPayload("nstp-enrollment"))),
        )
      }
      if (url.includes("/ched-reports/enrollment-list")) {
        return Promise.resolve(new Response(JSON.stringify(list)))
      }
      if (url.endsWith("/academic-terms")) {
        return Promise.resolve(new Response(JSON.stringify(termsResponse)))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
  })
  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it("lists the students in a table that fits the page, with the long fields left to the details", async () => {
    const view = renderWithSession(<ChedReportsWorkspace />, {
      session: registrarHead,
    })

    expect(
      view.getByRole("region", { name: "CHED Reports" }),
    ).toBeInTheDocument()
    expect(
      await screen.findByRole("button", { name: VIEW_SANTOS }),
    ).toBeInTheDocument()

    const table = screen.getByRole("table", { name: /CHED Enrollment List/ })
    const headers = within(table)
      .getAllByRole("columnheader")
      .map((header) => header.textContent)
    expect(headers).toEqual([
      "No.",
      "STUDENT NUMBER",
      "Name",
      "Programs",
      "Year Level",
      "GENDER",
      "NO. OF UNITS",
    ])
    expect(within(table).getByText("2025-08-00001")).toBeInTheDocument()
    expect(
      within(table).queryByText(/Intro to Computing/),
    ).not.toBeInTheDocument()
    expect(screen.getByText(/2nd Term, AY 2025-2026/)).toBeInTheDocument()
    expect(screen.getByText("1 student")).toBeInTheDocument()
    expect(
      screen.getByText(/Click a student to see every field/),
    ).toBeInTheDocument()
    expect(
      urls().some((u) =>
        u.includes("/ched-reports/enrollment-list?academic_term_id=6&page=1"),
      ),
    ).toBe(true)
    expect(await axe(view.container)).toHaveNoViolations()
  })

  it("opens every field of a student in a modal when the row is clicked", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    await user.click(screen.getByText("2025-08-00001"))

    const dialog = await screen.findByRole("dialog", {
      name: "Santos, Ana D.",
    })
    expect(
      within(dialog).getByText("CHED Enrollment List · 2nd Term, AY 2025-2026"),
    ).toBeInTheDocument()
    // Every field of the file, under the template's own labels.
    for (const [label, value] of [
      ["Programs", "Bachelor of Science in Information Technology"],
      ["STUDENT NUMBER", "2025-08-00001"],
      ["FIRST NAME", "Ana"],
      ["MIDDLE NAME", "D."],
      ["SURNAME", "Santos"],
      ["Citizenship", "Filipino"],
      ["GENDER", "F"],
      ["Year Level", "2"],
      ["NO. OF UNITS", "8"],
    ] as const) {
      const term = within(dialog).getByText(label, { selector: "dt" })
      expect(term.nextElementSibling).toHaveTextContent(value)
    }
    // What is empty reads as a dash, like the table.
    const major = within(dialog).getByText("Major", { selector: "dt" })
    expect(major.nextElementSibling).toHaveTextContent("—")
    expect(dialog.className).toContain("sm:max-w-5xl")
  })

  it("lists the subjects one by one, with their units, and keeps a title with commas whole", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })

    await user.click(await screen.findByRole("button", { name: VIEW_SANTOS }))

    const dialog = await screen.findByRole("dialog")
    const subjects = within(dialog).getByRole("list", {
      name: "SUBJECTS ENROLLED FOLLOWED BY UNITS",
    })
    const items = within(subjects).getAllByRole("listitem")
    expect(items.map((item) => item.textContent)).toEqual([
      "Intro to Computing3",
      "Science, Technology, and Society3",
      "Fitness2",
    ])
  })

  it("opens from the keyboard, closes with Escape and hands focus back", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    const opener = await screen.findByRole("button", { name: VIEW_SANTOS })

    opener.focus()
    await user.keyboard("{Enter}")
    expect(await screen.findByRole("dialog")).toBeInTheDocument()

    await user.keyboard("{Escape}")
    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    )
    await waitFor(() => expect(opener).toHaveFocus())
  })

  it("closes with the Close button", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await user.click(await screen.findByRole("button", { name: VIEW_SANTOS }))

    await user.click(await screen.findByRole("button", { name: "Close" }))

    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    )
  })

  it("has no accessibility violations while the modal is open", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await user.click(await screen.findByRole("button", { name: VIEW_SANTOS }))
    await screen.findByRole("dialog")

    expect(await axe(document.body)).toHaveNoViolations()
  })

  it("warns about students without a gender, and no longer shows a note about generated details", async () => {
    list = listPayload("enrollment-list", {
      total: 40,
      missingGender: 3,
      demoPopulated: 40,
    })
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })

    expect(
      await screen.findByText(/3 listed students have no gender on file/),
    ).toBeInTheDocument()
    // The owner knows the details are not real (2026-10-15): the API still reports the count, the page does not.
    expect(
      screen.queryByText(/Some details are generated, not real/),
    ).not.toBeInTheDocument()
    expect(screen.queryByText(/carry generated/)).not.toBeInTheDocument()
  })

  it("says so when no student is listed for the term", async () => {
    list = listPayload("enrollment-list", { rows: [], total: 0 })
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })

    expect(
      await screen.findByText("No students are listed for this term."),
    ).toBeInTheDocument()
  })

  it("pages through a long list", async () => {
    list = listPayload("enrollment-list", { total: 60, lastPage: 3 })
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })

    await screen.findByRole("button", { name: VIEW_SANTOS })
    expect(screen.getByRole("button", { name: "Previous" })).toBeDisabled()
    await user.click(screen.getByRole("button", { name: "Next" }))

    await waitFor(() =>
      expect(urls().some((u) => u.includes("page=2"))).toBe(true),
    )
  })

  it("shows an NSTP form's students with their own columns and the same details modal", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    await user.click(screen.getByRole("tab", { name: "NSTP 2 Enrollment" }))

    const opener = await screen.findByRole("button", {
      name: "View details of Reyes, Maria L.",
    })
    expect(
      screen.getByRole("columnheader", { name: "Birthdate (ex. 11/25/1992)" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("columnheader", { name: "Email Address" }),
    ).not.toBeInTheDocument()
    expect(
      urls().some((u) =>
        u.includes("/ched-reports/nstp-enrollment?academic_term_id=6"),
      ),
    ).toBe(true)

    await user.click(opener)

    const dialog = await screen.findByRole("dialog", {
      name: "Reyes, Maria L.",
    })
    for (const [label, value] of [
      ["Gender", "FEMALE"],
      ["Birthdate (ex. 11/25/1992)", "11/25/1992"],
      ["Street/Barangay Address", "12 Rizal St., Brgy. San Jose"],
      ["Town/City Address", "Caloocan City"],
      ["Provincial Address", "Metro Manila"],
      ["Contact Number Telephone/Mobile", "09171234567"],
      ["Email Address", "maria.reyes@grc.test"],
      ["Serial No.", "—"],
    ] as const) {
      const term = within(dialog).getByText(label, { selector: "dt" })
      expect(term.nextElementSibling).toHaveTextContent(value)
    }
    expect(
      within(dialog).queryByRole("list", { name: /SUBJECTS/ }),
    ).not.toBeInTheDocument()
  })

  it("names the NSTP tabs after the semester: NSTP 1 in the first, NSTP 2 in the second", async () => {
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    expect(
      screen.getByRole("tab", { name: "NSTP 2 Enrollment" }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("tab", { name: /NSTP 2-B/ }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole("tab", { name: "NSTP 2 Graduates" }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole("tab", { name: "Form 2-A Summary" }),
    ).toBeInTheDocument()
  })

  it("says NSTP 1 Enrollment, never NSTP 2, when the term is a first semester", async () => {
    termsResponse = {
      data: [
        termRow(6, "2025-2026", "2nd"),
        termRow(5, "2025-2026", "1st", "semester_ongoing"),
      ],
    }
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })

    const tab = await screen.findByRole("tab", { name: "NSTP 1 Enrollment" })
    expect(
      screen.queryByRole("tab", { name: "NSTP 2 Enrollment" }),
    ).not.toBeInTheDocument()

    await user.click(tab)

    expect(
      await screen.findByRole("heading", { name: /NSTP 1 Enrollment/ }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/Students officially enrolled in NSTP 1 in the term/),
    ).toBeInTheDocument()
    expect(
      urls().some((u) =>
        u.includes("/ched-reports/nstp-enrollment?academic_term_id=5"),
      ),
    ).toBe(true)
  })

  it("lists the school years and semesters newest first, not in the order they were created", async () => {
    termsResponse = {
      data: [
        termRow(19, "2017-2018", "1st"),
        termRow(6, "2025-2026", "2nd", "semester_ongoing"),
        termRow(20, "2017-2018", "2nd"),
        termRow(5, "2025-2026", "1st"),
        termRow(21, "2022-2023", "2nd"),
        termRow(3, "2024-2025", "2nd"),
        termRow(2, "2024-2025", "1st"),
      ],
    }
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    const picker = screen.getByRole("combobox", { name: "Academic term" })

    expect(
      within(picker)
        .getAllByRole("option")
        .map((option) => option.textContent?.replace(/ \(.*\)$/, "")),
    ).toEqual([
      "2025-2026 · 2nd",
      "2025-2026 · 1st",
      "2024-2025 · 2nd",
      "2024-2025 · 1st",
      "2022-2023 · 2nd",
      "2017-2018 · 2nd",
      "2017-2018 · 1st",
    ])
  })

  it("searches the students, from the first page, and finds nothing kindly", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    const box = screen.getByRole("searchbox", { name: "Search students" })
    expect(
      screen.getByText(/download always has every student of the term/),
    ).toBeInTheDocument()

    list = listPayload("enrollment-list", { rows: [], total: 0 })
    await user.type(box, "zzz")

    expect(
      await screen.findByText("No student matches “zzz”."),
    ).toBeInTheDocument()
    expect(
      urls()
        .filter((u) => u.includes("search="))
        .at(-1),
    ).toContain("page=1&per_page=25&search=zzz")
  })

  it("sends one search request after typing stops, not one per key", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    await user.type(
      screen.getByRole("searchbox", { name: "Search students" }),
      "santos",
    )

    await waitFor(() =>
      expect(urls().some((u) => u.includes("search=santos"))).toBe(true),
    )
    expect(
      urls().filter(
        (u) =>
          u.includes("/ched-reports/enrollment-list?") && u.includes("search="),
      ),
    ).toHaveLength(1)
  })

  it("goes back to the first page when a search changes", async () => {
    list = listPayload("enrollment-list", { total: 60, lastPage: 3 })
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })
    await user.click(screen.getByRole("button", { name: "Next" }))
    await waitFor(() =>
      expect(urls().some((u) => u.includes("page=2"))).toBe(true),
    )

    await user.type(
      screen.getByRole("searchbox", { name: "Search students" }),
      "ana",
    )

    await waitFor(() =>
      expect(
        urls().some((u) => u.includes("page=1") && u.includes("search=ana")),
      ).toBe(true),
    )
  })

  it("shows Form 2-A as the school's one-row grid", async () => {
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    await user.click(screen.getByRole("tab", { name: "Form 2-A Summary" }))

    const grid = await screen.findByRole("table", {
      name: /NSTP Form 2-A summary/i,
    })
    expect(
      within(grid).getByText("Global Reciprocal Colleges"),
    ).toBeInTheDocument()
    expect(within(grid).getByText("Private")).toBeInTheDocument()
    // Each count shows in the school's row and again in the TOTAL row.
    expect(within(grid).getAllByText("426")).toHaveLength(2)
    expect(within(grid).getAllByText("377")).toHaveLength(2)
    expect(within(grid).getAllByText("489")).toHaveLength(2)
    expect(within(grid).getByText("TOTAL")).toBeInTheDocument()
    expect(
      screen.getByText(/Academic Year 2025-2026 · 2nd Semester/),
    ).toBeInTheDocument()
  })

  it("downloads the Excel file with the template's name, and CSV too", async () => {
    const names: string[] = []
    vi.stubGlobal(
      "URL",
      Object.assign(URL, {
        createObjectURL: vi.fn(() => "blob:ched"),
        revokeObjectURL: vi.fn(),
      }),
    )
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(function (this: HTMLAnchorElement) {
        names.push(this.download)
      })
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    await user.click(screen.getByRole("button", { name: "Download Excel" }))
    await waitFor(() => expect(names).toHaveLength(1))
    await user.click(screen.getByRole("button", { name: "Download CSV" }))
    await waitFor(() => expect(names).toHaveLength(2))

    expect(names).toEqual([
      "CHED-Enrollment-List_2025-2026-2nd.xlsx",
      "CHED-Enrollment-List_2025-2026-2nd.csv",
    ])
    expect(
      urls().some((u) =>
        u.includes(
          "/ched-reports/enrollment-list/export?academic_term_id=6&format=xlsx",
        ),
      ),
    ).toBe(true)
    click.mockRestore()
  })

  it("tells the Registrar Head when a download fails", async () => {
    fetchMock.mockImplementation((input) => {
      const url = requestUrl(input)
      if (url.includes("/export")) {
        return Promise.resolve(new Response("{}", { status: 500 }))
      }
      if (url.includes("/ched-reports/enrollment-list")) {
        return Promise.resolve(new Response(JSON.stringify(list)))
      }
      if (url.endsWith("/academic-terms")) {
        return Promise.resolve(new Response(JSON.stringify(terms)))
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    const user = userEvent.setup()
    renderWithSession(<ChedReportsWorkspace />, { session: registrarHead })
    await screen.findByRole("button", { name: VIEW_SANTOS })

    await user.click(screen.getByRole("button", { name: "Download CSV" }))

    await waitFor(() => expect(toastError).toHaveBeenCalledTimes(1))
    expect(toastError.mock.calls[0][0]).toMatch(/could not be downloaded/)
  })

  it("is not available to any other role", () => {
    renderWithSession(<ChedReportsWorkspace />, {
      session: { ...registrarHead, role: "registrar_staff" },
    })

    expect(
      screen.getByText("This workspace is not available for your role."),
    ).toBeInTheDocument()
    expect(urls().some((u) => u.includes("/ched-reports"))).toBe(false)
  })
})
