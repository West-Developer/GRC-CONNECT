import { screen, waitFor } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { afterEach, describe, expect, it, vi } from "vitest"

import { StudentInformationWorkspace } from "@/features/components/portal/student-information-workspace"
import { renderWithSession } from "@/tests/render-app"

const profile = {
  type: "student_profile",
  id: 31,
  user_id: 41,
  student_number: "2027-08-01001",
  name: "Amina Santos",
  first_name: "Amina",
  middle_name: null,
  middle_initial: null,
  last_name: "Santos",
  suffix: null,
  email: "amina.santos@grc.test",
  address: "Official Address, Caloocan City",
  program_id: 11,
  program_code: "BSIT",
  program_name: "Bachelor of Science in Information Technology",
  curriculum_id: 22,
  entry_year: 2027,
  curriculum_name: "BSIT 2027 Curriculum",
  curriculum_effective_school_year: "2027-2028",
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
  requirements_verified_at: "2026-08-26T08:00:00Z",
  academic_setup_editable: false,
  account_setup_status: "active",
  invitation_delivery_status: "sent",
} as const

const pending = {
  type: "student_profile_change_request",
  id: 7,
  student_id: 31,
  student_number: profile.student_number,
  student_name: profile.name,
  status: "pending",
  status_label: "Pending",
  official: {
    name: profile.name,
    first_name: profile.first_name,
    middle_name: profile.middle_name,
    middle_initial: profile.middle_initial,
    last_name: profile.last_name,
    suffix: profile.suffix,
    email: profile.email,
    address: profile.address,
  },
  requested: {
    name: profile.name,
    first_name: profile.first_name,
    middle_name: profile.middle_name,
    middle_initial: profile.middle_initial,
    last_name: profile.last_name,
    suffix: profile.suffix,
    email: "new.amina@grc.test",
    address: "Proposed Address, Caloocan City",
  },
  reason: "Correct my email and address.",
  decision_notes: null,
  identity_verified_at: null,
  requested_at: "2026-08-26T08:00:00Z",
  decided_at: null,
} as const

const page = {
  data: [pending],
  links: {
    first: "http://localhost/api?page=1",
    last: "http://localhost/api?page=1",
    prev: null,
    next: null,
  },
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
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

describe("StudentInformationWorkspace", () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it("separates official and pending values and lets the student revise the single pending request", async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockImplementation((input, init) => {
        const url = urlOf(input)
        if (url.endsWith("/api/v1/student-profile")) {
          return Promise.resolve(
            new Response(JSON.stringify({ data: profile })),
          )
        }
        if (
          url.includes("student-profile-change-requests/7") &&
          init?.method === "PATCH"
        ) {
          const body = bodyOf(init) as typeof pending.requested & {
            reason: string
          }
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: {
                  ...pending,
                  requested: {
                    name: `${body.first_name} ${body.last_name}`,
                    first_name: body.first_name,
                    middle_name: body.middle_name ?? null,
                    middle_initial: null,
                    last_name: body.last_name,
                    suffix: body.suffix,
                    email: body.email,
                    address: body.address,
                  },
                  reason: body.reason,
                },
              }),
            ),
          )
        }
        if (url.includes("student-profile-change-requests")) {
          return Promise.resolve(new Response(JSON.stringify(page)))
        }
        return Promise.reject(new Error(`Unexpected request: ${url}`))
      })
    vi.stubGlobal("fetch", fetchMock)
    const user = userEvent.setup()
    renderWithSession(<StudentInformationWorkspace />, {
      session: {
        userId: "41",
        displayName: profile.name,
        role: "student",
        signedInAt: "2026-08-26T00:00:00Z",
      },
    })

    expect(
      await screen.findByText(/go to the Admission Office/i),
    ).toBeInTheDocument()
    expect(screen.getByText(profile.address)).toBeInTheDocument()
    expect(screen.getByText(pending.requested.address)).toBeInTheDocument()
    expect(screen.getByText("Awaiting Admission")).toBeInTheDocument()

    const address = screen.getByLabelText("Proposed complete address")
    await user.clear(address)
    await user.type(address, "Revised Address, Caloocan City")
    await user.click(
      screen.getByRole("button", { name: "Save revised request" }),
    )

    await waitFor(() => {
      const patchCall = fetchMock.mock.calls.find(
        ([input, init]) =>
          urlOf(input).includes("student-profile-change-requests/7") &&
          init?.method === "PATCH",
      )
      expect(bodyOf(patchCall?.[1])).toMatchObject({
        address: "Revised Address, Caloocan City",
      })
    })
    expect(
      screen.getByRole("button", { name: "Cancel pending request" }),
    ).toBeInTheDocument()
  })

  describe("the middle name", () => {
    const noRequests = {
      ...page,
      data: [],
      meta: { ...page.meta, total: 0 },
    }

    function mockOwnProfile(own: Record<string, unknown>) {
      const fetchMock = vi
        .fn<typeof fetch>()
        .mockImplementation((input, init) => {
          const url = urlOf(input)
          if (url.endsWith("/api/v1/student-profile")) {
            return Promise.resolve(new Response(JSON.stringify({ data: own })))
          }
          if (
            url.includes("student-profile-change-requests") &&
            init?.method === "POST"
          ) {
            return Promise.resolve(
              new Response(JSON.stringify({ data: pending }), { status: 201 }),
            )
          }
          if (url.includes("student-profile-change-requests")) {
            return Promise.resolve(new Response(JSON.stringify(noRequests)))
          }
          return Promise.reject(new Error(`Unexpected request: ${url}`))
        })
      vi.stubGlobal("fetch", fetchMock)
      renderWithSession(<StudentInformationWorkspace />, {
        session: {
          userId: "41",
          displayName: String(own.name),
          role: "student",
          signedInAt: "2026-08-26T00:00:00Z",
        },
      })
      return fetchMock
    }

    it("shows the student the full middle name on their own profile, while the name itself stays an initial", async () => {
      mockOwnProfile({
        ...profile,
        name: "West R. Ragma",
        first_name: "West",
        last_name: "Ragma",
        middle_name: "Ramga",
        middle_initial: "R",
      })

      await screen.findByText(/go to the Admission Office/i)

      expect(screen.getByText("West R. Ragma")).toBeInTheDocument()
      expect(screen.getByText("Middle name")).toBeInTheDocument()
      expect(screen.getByText("Ramga")).toBeInTheDocument()
      expect(screen.getByLabelText("Proposed middle name")).toHaveValue("Ramga")
      expect(screen.queryByLabelText(/middle initial/i)).not.toBeInTheDocument()
    })

    it("shows an older account's initial where the full name is not known", async () => {
      mockOwnProfile({ ...profile, middle_name: null, middle_initial: "S" })

      await screen.findByText(/go to the Admission Office/i)

      expect(screen.getByText("S.")).toBeInTheDocument()
      expect(screen.getByLabelText("Proposed middle name")).toHaveValue("S")
    })

    it("asks for the full middle name in a change request", async () => {
      const fetchMock = mockOwnProfile({
        ...profile,
        name: "West R. Ragma",
        first_name: "West",
        last_name: "Ragma",
        middle_name: "Ramga",
        middle_initial: "R",
      })
      const user = userEvent.setup()
      await screen.findByText(/go to the Admission Office/i)

      const middle = screen.getByLabelText("Proposed middle name")
      await user.clear(middle)
      await user.type(middle, "Santos")
      await user.type(
        screen.getByLabelText("Reason for change"),
        "My middle name was misspelled.",
      )
      await user.click(
        screen.getByRole("button", { name: "Submit change request" }),
      )

      await waitFor(() => {
        const post = fetchMock.mock.calls.find(
          ([input, init]) =>
            urlOf(input).includes("student-profile-change-requests") &&
            init?.method === "POST",
        )
        expect(bodyOf(post?.[1])).toMatchObject({ middle_name: "Santos" })
        expect(bodyOf(post?.[1])).not.toHaveProperty("middle_initial")
      })
    })
  })

  it("keeps the long request form collapsed on a phone until it is opened", async () => {
    // Stakeholder Doc 13: not everything at once on a phone.
    vi.spyOn(window, "matchMedia").mockImplementation(
      (query: string) =>
        ({
          matches: query.includes("max-width: 47.99rem"),
          media: query,
          onchange: null,
          addListener: () => undefined,
          removeListener: () => undefined,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          dispatchEvent: () => false,
        }) as MediaQueryList,
    )
    vi.stubGlobal(
      "fetch",
      vi.fn<typeof fetch>().mockImplementation((input) => {
        const url = urlOf(input)
        if (url.endsWith("/api/v1/student-profile")) {
          return Promise.resolve(
            new Response(JSON.stringify({ data: profile })),
          )
        }
        if (url.includes("student-profile-change-requests")) {
          return Promise.resolve(new Response(JSON.stringify(page)))
        }
        return Promise.reject(new Error(`Unexpected request: ${url}`))
      }),
    )
    const user = userEvent.setup()
    renderWithSession(<StudentInformationWorkspace />, {
      session: {
        userId: "41",
        displayName: profile.name,
        role: "student",
        signedInAt: "2026-08-26T00:00:00Z",
      },
    })

    await screen.findByText(/go to the Admission Office/i)
    // The essentials are visible straight away...
    expect(screen.getByText(profile.address)).toBeInTheDocument()
    expect(screen.getByText("Awaiting Admission")).toBeInTheDocument()
    // ...the 7-field form and the history are one tap away.
    const form = screen.getByRole("button", { name: "Revise pending request" })
    expect(form).toHaveAttribute("aria-expanded", "false")
    expect(
      screen.queryByLabelText("Proposed complete address"),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole("button", { name: "Request history" }),
    ).toHaveAttribute("aria-expanded", "false")

    await user.click(form)

    expect(
      await screen.findByLabelText("Proposed complete address"),
    ).toBeInTheDocument()
  })
})
