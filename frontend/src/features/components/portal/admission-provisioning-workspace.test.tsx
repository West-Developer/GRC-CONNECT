import { screen, waitFor, within } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { toast } from "sonner"
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"

import { AdmissionProvisioningWorkspace } from "@/features/components/portal/admission-provisioning-workspace"
import { renderWithSession } from "@/tests/render-app"

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}))

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
  academic_setup_editable: true,
  account_setup_status: "pending",
  invitation_delivery_status: "sent",
} as const

// A freshman who took a queue number at Admission: no account yet, the setup email follows payment.
const freshmanProfile = {
  ...profile,
  id: 33,
  student_number: "2027-08-01002",
  name: "Juan Dela Cruz",
  first_name: "Juan",
  last_name: "Dela Cruz",
  email: "juan.delacruz@grc.test",
  account_setup_status: "awaiting_payment",
  invitation_delivery_status: "not_sent",
  freshman_payment_confirmed_at: null,
  queue_ticket_number: "Q012",
  queue_link_delivery_status: "sent",
} as const

const enrolledProfile = {
  ...profile,
  id: 32,
  student_number: "2026-08-01099",
  name: "Marco Dela Cruz",
  first_name: "Marco",
  last_name: "Dela Cruz",
  email: "marco.delacruz@grc.test",
  // Admission keeps the full middle name; the name above only ever shows its initial.
  middle_name: "Ramga",
  middle_initial: "R",
  academic_setup_editable: false,
  account_setup_status: "active",
} as const

const pagination = {
  links: {
    first: "http://localhost/api?page=1",
    last: "http://localhost/api?page=1",
    prev: null,
    next: null,
  },
  meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
}

const programs = {
  data: [
    {
      type: "program",
      id: 11,
      code: "BSIT",
      name: "Bachelor of Science in Information Technology",
      status: "active",
      status_label: "Active",
    },
    {
      type: "program",
      id: 13,
      code: "BSCRIM",
      name: "BS Criminology",
      status: "inactive",
      status_label: "Inactive",
    },
  ],
}

const changeRequest = {
  type: "student_profile_change_request",
  id: 7,
  student_id: profile.id,
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
    name: "Amina Reyes Santos",
    first_name: profile.first_name,
    middle_name: "Reyes",
    middle_initial: "R",
    last_name: profile.last_name,
    suffix: null,
    email: "amina.reyes@grc.test",
    address: "Proposed Address, Caloocan City",
  },
  reason: "Correct my official personal information.",
  decision_notes: null,
  identity_verified_at: null,
  requested_at: "2026-08-26T08:00:00Z",
  decided_at: null,
} as const

// What the Create Account form lists for a Year 1 (Freshman) student.
const intakeRequirements = {
  data: {
    type: "admission_requirement_selection",
    student_type: "freshman",
    student_type_label: "Freshman",
    categories: [
      {
        category: "freshman",
        label: "Freshman requirements",
        items: [
          { requirement_type_id: 1, name: "Form 137", is_system: true },
          { requirement_type_id: 2, name: "Form 138", is_system: true },
        ],
      },
      {
        category: "additional",
        label: "Additional requirements",
        items: [
          {
            requirement_type_id: 7,
            name: "Original Birth Certificate (PSA)",
            is_system: true,
          },
        ],
      },
    ],
  },
} as const

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

function renderWorkspace() {
  return renderWithSession(<AdmissionProvisioningWorkspace />, {
    session: {
      userId: "5",
      displayName: "Admission Staff",
      role: "admission_staff",
      signedInAt: "2026-08-26T00:00:00Z",
    },
  })
}

/** The details the CHED forms ask for; citizenship starts as "Filipino" and is left alone. */
async function fillStudentDetails(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByLabelText("Gender"))
  await user.click(await screen.findByRole("option", { name: "Female" }))
  await user.type(screen.getByLabelText("Birth date"), profile.birth_date)
  await user.type(
    screen.getByLabelText("Contact number"),
    profile.contact_number,
  )
  await user.type(
    screen.getByLabelText("Street / barangay"),
    profile.street_barangay,
  )
  await user.type(screen.getByLabelText("Town / city"), profile.town_city)
  await user.type(screen.getByLabelText("Province"), profile.province)
}

async function fillAccountBasics(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("First name"), profile.first_name)
  await user.type(screen.getByLabelText("Last name"), profile.last_name)
  await user.type(screen.getByLabelText("Email address"), profile.email)
  await fillStudentDetails(user)
  await user.click(screen.getByLabelText("Program"))
  await user.click(
    await screen.findByRole("option", {
      name: "BSIT — Bachelor of Science in Information Technology",
    }),
  )
  await agreeToPrivacyNotice(user)
}

/** Admission confirms the student was shown the Data Privacy Notice before saving (ADR 0055). */
async function agreeToPrivacyNotice(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    screen.getByRole("checkbox", { name: /I showed this Data Privacy Notice/ }),
  )
}

async function chooseStudentType(
  user: ReturnType<typeof userEvent.setup>,
  name: string,
) {
  await user.click(screen.getByLabelText("Student type"))
  await user.click(await screen.findByRole("option", { name }))
}

describe("Student Records workspace", () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock)
    fetchMock.mockImplementation((input, init) => {
      const url = urlOf(input)
      if (url.includes("/api/v1/programs")) {
        return Promise.resolve(new Response(JSON.stringify(programs)))
      }
      if (url.includes("/api/v1/admission-requirement-types")) {
        return Promise.resolve(new Response(JSON.stringify(intakeRequirements)))
      }
      if (url.includes("/api/v1/student-profiles") && init?.method === "POST") {
        return Promise.resolve(
          new Response(JSON.stringify({ data: profile }), { status: 201 }),
        )
      }
      if (
        url.includes(
          `/api/v1/student-profiles/${profile.id}/admission-requirements`,
        )
      ) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "admission_requirements",
                student: {
                  student_profile_id: profile.id,
                  student_number: profile.student_number,
                  name: profile.name,
                  student_type: "freshman",
                  student_type_label: "Freshman",
                  admission_status: "admitted",
                },
                categories: [
                  {
                    category: "freshman",
                    label: "Freshman requirements",
                    items: [
                      {
                        requirement_type_id: 1,
                        name: "Form 137",
                        is_system: true,
                        is_submitted: false,
                        submitted_at: null,
                      },
                    ],
                  },
                ],
                summary: {
                  required_count: 1,
                  submitted_count: 0,
                  missing_count: 1,
                  complete: false,
                },
              },
            }),
          ),
        )
      }
      if (url.includes("/api/v1/student-profiles/next-student-number")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                type: "next_student_number",
                student_number: "2026-10-01936",
              },
            }),
          ),
        )
      }
      if (url.includes("/api/v1/student-profiles")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [profile], ...pagination })),
        )
      }
      if (url.includes("/api/v1/student-profile-change-requests")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [], ...pagination })),
        )
      }
      return Promise.reject(new Error(`Unexpected request: ${url}`))
    })
  })

  afterEach(() => vi.unstubAllGlobals())

  function provisioningBody(): Record<string, unknown> {
    const call = fetchMock.mock.calls.find(
      ([input, init]) =>
        urlOf(input).endsWith("/api/v1/student-profiles") &&
        init?.method === "POST",
    )
    return bodyOf(call?.[1])
  }

  it.each(["failed", "not_sent"])(
    "keeps a created account and warns when its setup email is %s",
    async (deliveryStatus) => {
      const defaultImplementation = fetchMock.getMockImplementation()
      fetchMock.mockImplementation((input, init) => {
        if (
          urlOf(input).endsWith("/api/v1/student-profiles") &&
          init?.method === "POST"
        ) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: {
                  ...profile,
                  student_type: "transferee",
                  invitation_delivery_status: deliveryStatus,
                },
              }),
              { status: 201 },
            ),
          )
        }
        return defaultImplementation!(input, init)
      })
      const user = userEvent.setup()
      renderWorkspace()
      await fillAccountBasics(user)
      await chooseStudentType(user, "Transferee")
      await user.click(
        screen.getByRole("button", { name: "Create account and email setup" }),
      )

      await waitFor(() =>
        expect(toast.warning).toHaveBeenCalledWith(
          `Account created, but the setup email could not be sent to ${profile.email}. Check the email service or address, then use Resend setup email.`,
        ),
      )
      expect(toast.success).not.toHaveBeenCalled()
      expect(
        screen.getByRole("button", { name: "Resend setup email" }),
      ).toBeInTheDocument()
    },
  )

  it.each(["creation result", "directory", "record dialog"])(
    "reports a failed resend from the %s without a success message",
    async (location) => {
      const defaultImplementation = fetchMock.getMockImplementation()
      fetchMock.mockImplementation((input, init) => {
        if (urlOf(input).endsWith("/account-setup-invitations")) {
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: { ...profile, invitation_delivery_status: "failed" },
              }),
            ),
          )
        }
        return defaultImplementation!(input, init)
      })
      const user = userEvent.setup()
      renderWorkspace()
      if (location === "creation result") {
        await fillAccountBasics(user)
        await chooseStudentType(user, "Transferee")
        await user.click(
          screen.getByRole("button", {
            name: "Create account and email setup",
          }),
        )
        await screen.findByRole("button", { name: "Resend setup email" })
      } else {
        await user.click(screen.getByRole("tab", { name: "Student Directory" }))
        const table = await screen.findByRole("table", {
          name: "Student directory",
        })
        if (location === "record dialog") {
          await user.click(
            await within(table).findByRole("button", { name: profile.name }),
          )
        }
      }

      vi.mocked(toast.success).mockClear()
      const target =
        location === "directory"
          ? within(screen.getByRole("table", { name: "Student directory" }))
          : location === "record dialog"
            ? within(screen.getByRole("dialog", { name: profile.name }))
            : screen
      await user.click(
        target.getByRole("button", {
          name:
            location === "directory" ? "Resend email" : "Resend setup email",
        }),
      )

      await waitFor(() =>
        expect(toast.error).toHaveBeenCalledWith(
          `The setup email could not be sent to ${profile.email}. Check the email service or address, then try again.`,
        ),
      )
      expect(toast.success).not.toHaveBeenCalled()
    },
  )

  it("uses one four-part workspace and creates an account with the details, type and ticked requirements Admission chose", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    expect(
      screen.getByRole("heading", { name: "Student Records" }),
    ).toBeInTheDocument()
    expect(screen.getAllByRole("tab").map((tab) => tab.textContent)).toEqual([
      "Create Account",
      "Student Directory",
      "Freshmen",
      "Change Requests",
    ])
    expect(screen.queryByLabelText("Curriculum")).not.toBeInTheDocument()
    expect(screen.queryByText(/temporary credential/i)).not.toBeInTheDocument()
    // The server still derives the entry year; the type is Admission's choice and the Regular /
    // Irregular category is the system's, so it is not on the form.
    expect(screen.queryByLabelText("Entry year")).not.toBeInTheDocument()
    expect(
      screen.queryByLabelText("Enrollment category"),
    ).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Complete address")).not.toBeInTheDocument()
    expect(screen.getByLabelText("Student type")).toBeInTheDocument()
    // The address is typed as street / barangay, town / city and province; citizenship starts Filipino.
    expect(screen.getByLabelText("Street / barangay")).toBeInTheDocument()
    expect(screen.getByLabelText("Town / city")).toBeInTheDocument()
    expect(screen.getByLabelText("Province")).toBeInTheDocument()
    expect(screen.getByLabelText("Citizenship")).toHaveValue("Filipino")

    await user.type(screen.getByLabelText("First name"), profile.first_name)
    await user.type(screen.getByLabelText("Last name"), profile.last_name)
    // The full middle name, not an initial: the server derives the initial every screen shows.
    expect(screen.queryByLabelText("Middle initial")).not.toBeInTheDocument()
    await user.type(screen.getByLabelText("Middle name"), "Ramga")
    await user.type(screen.getByLabelText("Email address"), profile.email)
    const studentNumberField = screen.getByLabelText("Student number")
    expect(studentNumberField).toHaveAttribute("readonly")
    // The number is assigned by the server in order: there is nothing to generate.
    expect(
      screen.queryByRole("button", { name: "Generate" }),
    ).not.toBeInTheDocument()
    // The form shows the number the account will get (a preview, not reserved until saved).
    expect(await screen.findByDisplayValue("2026-10-01936")).toBe(
      studentNumberField,
    )
    await user.type(studentNumberField, "0000-00-00000")
    expect(studentNumberField).not.toHaveValue("0000-00-00000")
    await user.click(screen.getByLabelText("Program"))
    await user.click(
      await screen.findByRole("option", {
        name: "BSIT — Bachelor of Science in Information Technology",
      }),
    )

    const submit = screen.getByRole("button", {
      name: "Create account and email setup",
    })
    // The single "verified" box is gone, and with no student type picked there is nothing to tick yet.
    expect(
      screen.queryByLabelText("Requirements submitted and verified"),
    ).not.toBeInTheDocument()
    expect(
      screen.getByText(
        "Choose the student type to see the Admission requirements that apply.",
      ),
    ).toBeInTheDocument()

    // Submitting without the details and the type stops at the form and takes Admission to the first one.
    const scrollIntoView = vi
      .spyOn(window.HTMLElement.prototype, "scrollIntoView")
      .mockImplementation(() => undefined)
    await agreeToPrivacyNotice(user)
    await user.click(submit)
    expect(
      await screen.findByText("Select the student's gender."),
    ).toBeInTheDocument()
    expect(
      screen.getByText("Enter the student's birth date."),
    ).toBeInTheDocument()
    expect(screen.getByText(/Enter a valid contact number/)).toBeInTheDocument()
    expect(
      screen.getByText("Enter the street and barangay."),
    ).toBeInTheDocument()
    expect(screen.getByText("Enter the town or city.")).toBeInTheDocument()
    expect(screen.getByText("Enter the province.")).toBeInTheDocument()
    expect(screen.getByText("Select the student type.")).toBeInTheDocument()
    await waitFor(() => expect(scrollIntoView).toHaveBeenCalled())
    // The first thing that needs fixing is the first invalid field on the form, not the button.
    expect(scrollIntoView.mock.contexts[0]).toBeInstanceOf(HTMLElement)
    scrollIntoView.mockRestore()
    expect(
      fetchMock.mock.calls.some(
        ([input, init]) =>
          urlOf(input).endsWith("/api/v1/student-profiles") &&
          init?.method === "POST",
      ),
    ).toBe(false)

    await fillStudentDetails(user)
    await user.click(screen.getByLabelText("Student type"))
    await user.click(await screen.findByRole("option", { name: "Transferee" }))

    expect(
      await screen.findByRole("checkbox", { name: "Form 137" }),
    ).not.toBeChecked()
    expect(screen.getByText("0 of 3 checked")).toBeInTheDocument()

    // Two of the three handed in: the account is still created, the third stays missing.
    await user.click(screen.getByRole("checkbox", { name: "Form 137" }))
    await user.click(screen.getByRole("checkbox", { name: "Form 138" }))
    expect(screen.getByText("2 of 3 checked")).toBeInTheDocument()
    await user.click(submit)

    expect(await screen.findByText("Awaiting setup")).toBeInTheDocument()
    // The success panel shows the number the server assigned.
    expect(screen.getByText(profile.student_number)).toBeInTheDocument()
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        `Account created and setup email sent to ${profile.email}.`,
      ),
    )
    const resendBtn = screen.getByRole("button", {
      name: "Resend setup email",
    })
    expect(resendBtn).toBeInTheDocument()

    // The itemized Admission checklist appears immediately after the account
    // is created, so staff never have to separately search the student back
    // up in the directory to find it (stakeholder Doc 16).
    expect(
      await screen.findByRole("heading", { name: "Admission requirements" }),
    ).toBeInTheDocument()
    expect(
      await screen.findByRole("checkbox", { name: "Form 137" }),
    ).toBeInTheDocument()
    // The form is ready for the next account: nothing carried over.
    expect(
      screen.getByText(
        "Choose the student type to see the Admission requirements that apply.",
      ),
    ).toBeInTheDocument()
    await user.click(resendBtn)
    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([input, init]) =>
            urlOf(input).includes(
              `/api/v1/student-profiles/${profile.id}/account-setup-invitations`,
            ) && init?.method === "POST",
        ),
      ).toBe(true)
    })

    const provisioningCall = fetchMock.mock.calls.find(
      ([input, init]) =>
        urlOf(input).endsWith("/api/v1/student-profiles") &&
        init?.method === "POST",
    )
    const body = bodyOf(provisioningCall?.[1])
    expect(body).toMatchObject({
      first_name: profile.first_name,
      middle_name: "Ramga",
      last_name: profile.last_name,
      gender: "F",
      birth_date: profile.birth_date,
      citizenship: "Filipino",
      contact_number: profile.contact_number,
      street_barangay: profile.street_barangay,
      town_city: profile.town_city,
      province: profile.province,
      student_type: "transferee",
      has_existing_student_number: false,
      requirement_type_ids: [1, 2],
    })
    // The category is the system's and the address text is written by the server from the three parts.
    expect(body).not.toHaveProperty("middle_initial")
    expect(body).not.toHaveProperty("enrollment_category")
    expect(body).not.toHaveProperty("address")
    expect(body).not.toHaveProperty("requirements_verified")
    expect(body).not.toHaveProperty("password")
    expect(body).not.toHaveProperty("curriculum_id")
    expect(body).not.toHaveProperty("entry_year")
    expect(body).not.toHaveProperty("student_number")
  })

  describe("the Data Privacy Notice (ADR 0055)", () => {
    it("tells Admission what the student is told, and cannot save until Admission confirms the student agreed", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      await fillAccountBasics(user)
      await chooseStudentType(user, "Transferee")

      // Filling the form ticked the box (the helper); undo it to see the starting state.
      const confirmation = screen.getByRole("checkbox", {
        name: /I showed this Data Privacy Notice/,
      })
      await user.click(confirmation)
      expect(confirmation).not.toBeChecked()

      expect(
        screen.getByText("Data privacy: show this to the student first"),
      ).toBeInTheDocument()
      expect(
        screen.getByRole("link", {
          name: /Open the Data Privacy Notice for the student/,
        }),
      ).toHaveAttribute("href", "/privacy")
      const submit = screen.getByRole("button", {
        name: "Create account and email setup",
      })
      expect(submit).toBeDisabled()

      await user.click(confirmation)
      expect(submit).toBeEnabled()
    })

    it("sends the version of the notice that was shown with the student's details", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      await fillAccountBasics(user)
      await chooseStudentType(user, "Transferee")
      await user.click(
        screen.getByRole("button", { name: "Create account and email setup" }),
      )
      await waitFor(() => expect(toast.success).toHaveBeenCalled())

      expect(provisioningBody()).toMatchObject({
        privacy_notice_acknowledged: true,
        privacy_notice_version: "2026-10-18",
      })
    })

    it("asks again for the next student: one confirmation is never carried over", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      await fillAccountBasics(user)
      await chooseStudentType(user, "Transferee")
      await user.click(
        screen.getByRole("button", { name: "Create account and email setup" }),
      )
      await waitFor(() => expect(toast.success).toHaveBeenCalled())

      expect(
        screen.getByRole("checkbox", {
          name: /I showed this Data Privacy Notice/,
        }),
      ).not.toBeChecked()
      expect(
        screen.getByRole("button", { name: "Create account and email setup" }),
      ).toBeDisabled()
    })
  })

  describe("a freshman pays first", () => {
    async function admitFreshman(
      user: ReturnType<typeof userEvent.setup>,
      created: Record<string, unknown>,
    ) {
      fetchMock.mockImplementation((input, init) => {
        const url = urlOf(input)
        if (url.includes("/api/v1/programs"))
          return Promise.resolve(new Response(JSON.stringify(programs)))
        if (url.includes("/api/v1/admission-requirement-types"))
          return Promise.resolve(
            new Response(JSON.stringify(intakeRequirements)),
          )
        if (url.endsWith("/api/v1/student-profiles") && init?.method === "POST")
          return Promise.resolve(
            new Response(JSON.stringify({ data: created }), { status: 201 }),
          )
        if (url.includes("/queue-link-resends"))
          return Promise.resolve(
            new Response(JSON.stringify({ data: created }), { status: 200 }),
          )
        if (url.includes("/admission-requirements"))
          return Promise.resolve(
            new Response(
              JSON.stringify({
                data: {
                  type: "admission_requirements",
                  student: {
                    student_profile_id: created.id,
                    student_number: created.student_number,
                    name: created.name,
                    student_type: "freshman",
                    student_type_label: "Freshman",
                    admission_status: "admitted",
                  },
                  categories: [],
                  summary: {
                    required_count: 0,
                    submitted_count: 0,
                    missing_count: 0,
                    complete: true,
                  },
                },
              }),
            ),
          )
        if (url.includes("/api/v1/student-profile-change-requests"))
          return Promise.resolve(
            new Response(JSON.stringify({ data: [], ...pagination })),
          )
        if (url.includes("/api/v1/student-profiles"))
          return Promise.resolve(
            new Response(JSON.stringify({ data: [created], ...pagination })),
          )
        return Promise.reject(new Error(`Unexpected request: ${url}`))
      })
      renderWorkspace()
      await fillAccountBasics(user)
      await chooseStudentType(user, "Freshman")
      await user.click(
        screen.getByRole("button", {
          name: "Save student and issue queue number",
        }),
      )
    }

    describe("when saving fails", () => {
      async function submitWithServerError(
        user: ReturnType<typeof userEvent.setup>,
        studentType: string,
        buttonName: string,
        failure: {
          status: number
          message: string
          errors: Record<string, string[]>
        } = {
          status: 500,
          message: "An unexpected server error occurred.",
          errors: {},
        },
      ) {
        fetchMock.mockImplementation((input, init) => {
          const url = urlOf(input)
          if (url.includes("/api/v1/programs"))
            return Promise.resolve(new Response(JSON.stringify(programs)))
          if (url.includes("/api/v1/admission-requirement-types"))
            return Promise.resolve(
              new Response(JSON.stringify(intakeRequirements)),
            )
          if (
            url.endsWith("/api/v1/student-profiles") &&
            init?.method === "POST"
          )
            return Promise.resolve(
              new Response(
                JSON.stringify({
                  error: {
                    code:
                      failure.status === 422
                        ? "VALIDATION_FAILED"
                        : "INTERNAL_ERROR",
                    message: failure.message,
                    errors: failure.errors,
                    request_id: "req-500",
                  },
                }),
                { status: failure.status },
              ),
            )
          return Promise.reject(new Error(`Unexpected request: ${url}`))
        })
        renderWorkspace()
        await fillAccountBasics(user)
        await chooseStudentType(user, studentType)
        await user.click(screen.getByRole("button", { name: buttonName }))
      }

      it("tells Admission the student was not saved, not that an account failed: a freshman has no account until they pay", async () => {
        const user = userEvent.setup()
        await submitWithServerError(
          user,
          "Freshman",
          "Save student and issue queue number",
        )

        expect(await screen.findByText("Student not saved")).toBeInTheDocument()
        expect(
          screen.getByText(/No student record or queue number was created/),
        ).toBeInTheDocument()
        expect(
          screen.queryByText("Account not created"),
        ).not.toBeInTheDocument()
        await waitFor(() =>
          expect(toast.error).toHaveBeenCalledWith(
            "Failed to save the student.",
          ),
        )
        // What was typed is still there to send again.
        expect(screen.getByLabelText("First name")).toHaveValue(
          profile.first_name,
        )
        expect(screen.getByLabelText("Email address")).toHaveValue(
          profile.email,
        )
      })

      it("shows the server's own explanation and request number for a server fault, so the cause is not guessed", async () => {
        const user = userEvent.setup()
        await submitWithServerError(
          user,
          "Freshman",
          "Save student and issue queue number",
          {
            status: 500,
            message:
              'The database is behind this version of the app. Open a terminal in the backend folder, run "php artisan migrate", then try again. Nothing was saved.',
            errors: {},
          },
        )

        expect(await screen.findByText("Student not saved")).toBeInTheDocument()
        expect(
          screen.getByText(/run "php artisan migrate"/),
        ).toBeInTheDocument()
        expect(screen.getByText(/Request req-500/)).toBeInTheDocument()
        expect(
          screen.getByText(/No student record or queue number was created/),
        ).toBeInTheDocument()
        // A fault on the server is not something to "review the form details" for.
        expect(
          screen.queryByText(/Review the form details/),
        ).not.toBeInTheDocument()
      })

      it("still asks to review the form when the server rejected what was typed", async () => {
        const user = userEvent.setup()
        await submitWithServerError(
          user,
          "Freshman",
          "Save student and issue queue number",
          {
            status: 422,
            message: "The submitted data is invalid.",
            errors: { email: ["The email has already been taken."] },
          },
        )

        expect(
          await screen.findByText(/Review the form details and try again/),
        ).toBeInTheDocument()
        expect(
          screen.getByText("The email has already been taken."),
        ).toBeInTheDocument()
        expect(screen.queryByText(/Request req-500/)).not.toBeInTheDocument()
      })

      it("keeps the account wording for a student who does get an account now", async () => {
        const user = userEvent.setup()
        await submitWithServerError(
          user,
          "Transferee",
          "Create account and email setup",
        )

        expect(
          await screen.findByText("Account not created"),
        ).toBeInTheDocument()
        expect(
          screen.getByText(
            /No account was created, so the same details can be sent again/,
          ),
        ).toBeInTheDocument()
        await waitFor(() =>
          expect(toast.error).toHaveBeenCalledWith(
            "Failed to create the account.",
          ),
        )
      })
    })

    it("asks Admission to issue a queue number, not to email account setup, once Freshman is chosen", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      expect(
        screen.getByRole("button", { name: "Create account and email setup" }),
      ).toBeInTheDocument()

      await chooseStudentType(user, "Freshman")

      expect(
        screen.getByRole("button", {
          name: "Save student and issue queue number",
        }),
      ).toBeInTheDocument()
      expect(
        screen.queryByRole("button", {
          name: "Create account and email setup",
        }),
      ).not.toBeInTheDocument()

      await chooseStudentType(user, "Transferee")

      expect(
        screen.getByRole("button", { name: "Create account and email setup" }),
      ).toBeInTheDocument()
    })

    it("shows the queue number and that the link was emailed, with no setup-email resend", async () => {
      const user = userEvent.setup()
      await admitFreshman(user, freshmanProfile)

      expect(await screen.findByText("Q012")).toBeInTheDocument()
      expect(screen.getByText("Awaiting payment")).toBeInTheDocument()
      expect(
        screen.getByText(freshmanProfile.student_number),
      ).toBeInTheDocument()
      expect(
        screen.getByText(
          /live queue link was emailed to juan\.delacruz@grc\.test/i,
        ),
      ).toBeInTheDocument()
      expect(
        screen.getByRole("button", { name: "Resend queue link" }),
      ).toBeInTheDocument()
      expect(
        screen.queryByRole("button", { name: "Resend setup email" }),
      ).not.toBeInTheDocument()
      await waitFor(() =>
        expect(toast.success).toHaveBeenCalledWith(
          "Student saved. Queue number Q012 was emailed to juan.delacruz@grc.test.",
        ),
      )
    })

    it("warns Admission, and shows the number to read out, when the email could not be sent", async () => {
      const user = userEvent.setup()
      await admitFreshman(user, {
        ...freshmanProfile,
        queue_link_delivery_status: "failed",
      })

      expect(await screen.findByText("Q012")).toBeInTheDocument()
      expect(
        screen.getByText(/email could not be sent.*tell the student/i),
      ).toBeInTheDocument()
      await waitFor(() =>
        expect(toast.warning).toHaveBeenCalledWith(
          "Student saved and queue number Q012 issued, but the email could not be sent. Tell the student the number, or resend the link.",
        ),
      )
      expect(toast.success).not.toHaveBeenCalled()
    })

    it("sends the queue link again from the delivery card", async () => {
      const user = userEvent.setup()
      await admitFreshman(user, freshmanProfile)

      await user.click(
        await screen.findByRole("button", { name: "Resend queue link" }),
      )

      await waitFor(() =>
        expect(
          fetchMock.mock.calls.some(
            ([input, init]) =>
              urlOf(input).includes(
                `/api/v1/student-profiles/${freshmanProfile.id}/queue-link-resends`,
              ) && init?.method === "POST",
          ),
        ).toBe(true),
      )
      await waitFor(() =>
        expect(toast.success).toHaveBeenCalledWith(
          "Queue link resent to juan.delacruz@grc.test.",
        ),
      )
    })

    it("lists a freshman who has not paid as awaiting payment and offers the queue link, not the setup email", async () => {
      const user = userEvent.setup()
      fetchMock.mockImplementation((input) => {
        const url = urlOf(input)
        if (url.includes("/api/v1/programs"))
          return Promise.resolve(new Response(JSON.stringify(programs)))
        if (url.includes("/api/v1/admission-requirement-types"))
          return Promise.resolve(
            new Response(JSON.stringify(intakeRequirements)),
          )
        if (url.includes("/api/v1/student-profile-change-requests"))
          return Promise.resolve(
            new Response(JSON.stringify({ data: [], ...pagination })),
          )
        if (url.includes("/api/v1/student-profiles"))
          return Promise.resolve(
            new Response(
              JSON.stringify({ data: [freshmanProfile], ...pagination }),
            ),
          )
        return Promise.reject(new Error(`Unexpected request: ${url}`))
      })
      renderWorkspace()

      await user.click(screen.getByRole("tab", { name: "Student Directory" }))

      const table = await screen.findByRole("table")
      expect(within(table).getByText("Awaiting payment")).toBeInTheDocument()
      expect(
        within(table).getByRole("button", { name: "Resend link" }),
      ).toBeInTheDocument()
      expect(
        within(table).queryByRole("button", { name: "Resend email" }),
      ).not.toBeInTheDocument()
    })
  })

  describe("what Admission has typed is kept", () => {
    async function fillEverything(user: ReturnType<typeof userEvent.setup>) {
      await fillAccountBasics(user)
      await chooseStudentType(user, "Transferee")
      await user.click(
        await screen.findByRole("checkbox", { name: "Form 137" }),
      )
    }

    async function expectEverythingStillThere() {
      expect(screen.getByLabelText("First name")).toHaveValue(
        profile.first_name,
      )
      expect(screen.getByLabelText("Last name")).toHaveValue(profile.last_name)
      expect(screen.getByLabelText("Email address")).toHaveValue(profile.email)
      expect(screen.getByLabelText("Gender")).toHaveTextContent("Female")
      expect(screen.getByLabelText("Birth date")).toHaveValue(
        profile.birth_date,
      )
      expect(screen.getByLabelText("Citizenship")).toHaveValue("Filipino")
      expect(screen.getByLabelText("Contact number")).toHaveValue(
        profile.contact_number,
      )
      expect(screen.getByLabelText("Street / barangay")).toHaveValue(
        profile.street_barangay,
      )
      expect(screen.getByLabelText("Town / city")).toHaveValue(
        profile.town_city,
      )
      expect(screen.getByLabelText("Province")).toHaveValue(profile.province)
      // The program's name needs the list of programs, which a fresh page has to fetch again.
      await waitFor(() =>
        expect(screen.getByLabelText("Program")).toHaveTextContent("BSIT"),
      )
      expect(screen.getByLabelText("Student type")).toHaveTextContent(
        "Transferee",
      )
      expect(
        await screen.findByRole("checkbox", { name: "Form 137" }),
      ).toBeChecked()
      expect(
        screen.getByRole("checkbox", { name: "Form 138" }),
      ).not.toBeChecked()
    }

    function savedDraftKeys(): string[] {
      return Object.keys(window.sessionStorage).filter((key) =>
        key.startsWith("grc.form-draft."),
      )
    }

    it("keeps the Create Account details when Admission switches to another tab and comes back", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      await fillEverything(user)

      await user.click(screen.getByRole("tab", { name: "Student Directory" }))
      expect(
        await screen.findByRole("heading", { name: "Student directory" }),
      ).toBeInTheDocument()
      await user.click(screen.getByRole("tab", { name: "Create Account" }))

      await expectEverythingStillThere()
    })

    it("keeps them when Admission leaves the page and returns to it", async () => {
      const user = userEvent.setup()
      const first = renderWorkspace()
      await fillEverything(user)

      first.unmount()
      renderWorkspace()

      await expectEverythingStillThere()
    })

    it("does not show one staff member's unsaved details to another", async () => {
      const user = userEvent.setup()
      const first = renderWorkspace()
      await fillEverything(user)
      first.unmount()

      renderWithSession(<AdmissionProvisioningWorkspace />, {
        session: {
          userId: "6",
          displayName: "Other Admission Staff",
          role: "admission_staff",
          signedInAt: "2026-08-26T00:00:00Z",
        },
      })

      expect(screen.getByLabelText("First name")).toHaveValue("")
      expect(screen.getByLabelText("Email address")).toHaveValue("")
    })

    it("lets go of the details once the account is created", async () => {
      const user = userEvent.setup()
      const first = renderWorkspace()
      await fillAccountBasics(user)
      await chooseStudentType(user, "Transferee")
      await user.click(
        screen.getByRole("button", { name: "Create account and email setup" }),
      )
      await waitFor(() => expect(toast.success).toHaveBeenCalled())
      expect(await screen.findByText("Awaiting setup")).toBeInTheDocument()

      first.unmount()

      expect(savedDraftKeys()).toEqual([])
      renderWorkspace()
      expect(screen.getByLabelText("First name")).toHaveValue("")
      expect(screen.getByLabelText("Email address")).toHaveValue("")
    })

    it("offers to clear what was typed, and clearing really empties the form and the saved copy", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      expect(
        screen.queryByRole("button", { name: "Clear form" }),
      ).not.toBeInTheDocument()
      await user.type(screen.getByLabelText("First name"), profile.first_name)

      const clear = await screen.findByRole("button", { name: "Clear form" })
      expect(
        screen.getByText(
          /kept in this browser until you create the account or sign out/i,
        ),
      ).toBeInTheDocument()
      await user.click(clear)

      expect(screen.getByLabelText("First name")).toHaveValue("")
      expect(
        screen.queryByRole("button", { name: "Clear form" }),
      ).not.toBeInTheDocument()
      expect(savedDraftKeys()).toEqual([])
    })

    it("keeps the Student Directory search box and the search that was run", async () => {
      const user = userEvent.setup()
      renderWorkspace()
      await user.click(screen.getByRole("tab", { name: "Student Directory" }))
      await user.type(screen.getByLabelText("Search student records"), "Amina")
      await user.click(screen.getByRole("button", { name: "Search" }))

      await user.click(screen.getByRole("tab", { name: "Create Account" }))
      await user.click(screen.getByRole("tab", { name: "Student Directory" }))

      expect(screen.getByLabelText("Search student records")).toHaveValue(
        "Amina",
      )
      await waitFor(() => {
        const lastDirectoryRead = fetchMock.mock.calls
          .map(([input]) => urlOf(input))
          .filter((url) => url.includes("/api/v1/student-profiles?"))
          .at(-1)
        expect(lastDirectoryRead).toContain("search=Amina")
      })
    })
  })

  it("lets a Transferee, a Returnee or an Existing Student say the student already has a student number, but not a Freshman", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    const checkbox = screen.getByRole("checkbox", {
      name: "Student already has a student number",
    })
    expect(checkbox).toBeDisabled()
    expect(
      screen.queryByRole("button", { name: "Generate" }),
    ).not.toBeInTheDocument()

    await chooseStudentType(user, "Freshman")
    expect(checkbox).toBeDisabled()
    await chooseStudentType(user, "Transferee")
    expect(checkbox).toBeEnabled()
    await chooseStudentType(user, "Returnee")
    expect(checkbox).toBeEnabled()
    await chooseStudentType(user, "Existing Student")
    expect(checkbox).toBeEnabled()
  })

  it("sends the number the student already has instead of a new one", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await fillAccountBasics(user)
    await chooseStudentType(user, "Returnee")

    await user.click(
      screen.getByRole("checkbox", {
        name: "Student already has a student number",
      }),
    )
    const field = screen.getByLabelText("Existing student number")
    expect(field).not.toHaveAttribute("readonly")
    await user.type(field, "2024-06-00123")
    await user.click(
      screen.getByRole("button", { name: "Create account and email setup" }),
    )

    expect(await screen.findByText("Awaiting setup")).toBeInTheDocument()
    expect(provisioningBody()).toMatchObject({
      student_type: "returnee",
      has_existing_student_number: true,
      student_number: "2024-06-00123",
    })
  })

  it("stops an existing student number in the wrong format before calling the API", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await fillAccountBasics(user)
    await chooseStudentType(user, "Existing Student")
    await user.click(
      screen.getByRole("checkbox", {
        name: "Student already has a student number",
      }),
    )
    await user.type(
      screen.getByLabelText("Existing student number"),
      "2024-6-123",
    )
    await user.click(
      screen.getByRole("button", { name: "Create account and email setup" }),
    )

    expect(
      await screen.findByText(
        "Student number must be in YYYY-MM-NNNNN format (e.g. 2026-08-07107).",
      ),
    ).toBeInTheDocument()
    expect(
      fetchMock.mock.calls.some(
        ([input, init]) =>
          urlOf(input).endsWith("/api/v1/student-profiles") &&
          init?.method === "POST",
      ),
    ).toBe(false)
  })

  it("drops a typed existing number when the type changes to one that gets a new number", async () => {
    const user = userEvent.setup()
    renderWorkspace()
    await fillAccountBasics(user)
    await chooseStudentType(user, "Returnee")
    const checkbox = screen.getByRole("checkbox", {
      name: "Student already has a student number",
    })
    await user.click(checkbox)
    await user.type(
      screen.getByLabelText("Existing student number"),
      "2024-06-00123",
    )

    await chooseStudentType(user, "Freshman")

    await waitFor(() => expect(checkbox).not.toBeChecked())
    expect(checkbox).toBeDisabled()
    // The typed number is gone; the field shows the number a new (freshman) account gets.
    expect(screen.getByLabelText("Student number")).toHaveValue("2026-10-01936")
    await user.click(
      screen.getByRole("button", {
        name: "Save student and issue queue number",
      }),
    )
    expect(await screen.findByText("Awaiting setup")).toBeInTheDocument()
    const body = provisioningBody()
    expect(body).toMatchObject({
      student_type: "freshman",
      has_existing_student_number: false,
    })
    expect(body).not.toHaveProperty("student_number")
  })

  it("shows the server's message on the field when the existing number is already in use", async () => {
    const user = userEvent.setup()
    const defaultImplementation = fetchMock.getMockImplementation()
    fetchMock.mockImplementation((input, init) => {
      if (
        urlOf(input).endsWith("/api/v1/student-profiles") &&
        init?.method === "POST"
      ) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              error: {
                code: "VALIDATION_FAILED",
                message: "The given data was invalid.",
                errors: {
                  student_number: ["This student number is already in use."],
                },
                request_id: "test-request",
              },
            }),
            { status: 422 },
          ),
        )
      }
      return defaultImplementation!(input, init)
    })
    renderWorkspace()
    await fillAccountBasics(user)
    await chooseStudentType(user, "Returnee")
    await user.click(
      screen.getByRole("checkbox", {
        name: "Student already has a student number",
      }),
    )
    await user.type(
      screen.getByLabelText("Existing student number"),
      "2024-06-00123",
    )
    await user.click(
      screen.getByRole("button", { name: "Create account and email setup" }),
    )

    expect(
      await screen.findByText("This student number is already in use."),
    ).toBeInTheDocument()
  })

  it("never offers a program that is switched off, such as BS Criminology", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(screen.getByLabelText("Program"))

    expect(
      await screen.findByRole("option", {
        name: "BSIT — Bachelor of Science in Information Technology",
      }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole("option", { name: /BS Criminology/ }),
    ).not.toBeInTheDocument()
  })

  it("offers Freshman as a student type and lists the Freshman requirements for it", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(screen.getByLabelText("Student type"))
    await user.click(await screen.findByRole("option", { name: "Freshman" }))

    expect(screen.getByLabelText("Student type")).toHaveTextContent("Freshman")
    // The checklist for that type is requested (not the Transferee one).
    await waitFor(() =>
      expect(
        fetchMock.mock.calls.some(([input]) =>
          urlOf(input).includes(
            "admission-requirement-types?student_type=freshman",
          ),
        ),
      ).toBe(true),
    )
    expect(
      await screen.findByRole("checkbox", { name: "Form 137" }),
    ).toBeInTheDocument()
  })

  it("lets Admission choose the gender and student type from dropdowns, with nothing set automatically and no Regular / Irregular choice", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    // No value is filled in for them, whatever the year level; the category is the system's.
    expect(
      screen.queryByLabelText("Enrollment category"),
    ).not.toBeInTheDocument()
    expect(screen.getByLabelText("Gender")).toHaveTextContent("Select a gender")
    expect(screen.getByLabelText("Student type")).toHaveTextContent(
      "Select a student type",
    )

    await user.click(screen.getByLabelText("Gender"))
    expect(
      (await screen.findAllByRole("option")).map(
        (option) => option.textContent,
      ),
    ).toEqual(["Female", "Male"])
    await user.click(screen.getByRole("option", { name: "Male" }))

    await user.click(screen.getByLabelText("Student type"))
    expect(
      (await screen.findAllByRole("option")).map(
        (option) => option.textContent,
      ),
    ).toEqual(["Freshman", "Transferee", "Returnee", "Existing Student"])
    await user.click(screen.getByRole("option", { name: "Existing Student" }))

    // Changing the year level no longer rewrites what was chosen.
    await user.click(screen.getByLabelText("Year level"))
    await user.click(await screen.findByRole("option", { name: "2nd Year" }))
    expect(screen.getByLabelText("Gender")).toHaveTextContent("Male")
    expect(screen.getByLabelText("Student type")).toHaveTextContent(
      "Existing Student",
    )
  })

  it("searches by name and opens the full student profile editor", async () => {
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(screen.getByRole("tab", { name: "Student Directory" }))
    await user.type(screen.getByLabelText("Search student records"), "Amina")
    await user.click(screen.getByRole("button", { name: "Search" }))

    // The directory now renders through the shared DataTable (Stakeholder
    // Doc 17), which doubles every row into a real table (desktop) and a
    // card list (phone) — queries below are scoped to the table to avoid
    // matching both.
    const table = await screen.findByRole("table", {
      name: "Student directory",
    })
    await waitFor(() =>
      expect(
        within(table).getByRole("button", { name: profile.name }),
      ).toBeInTheDocument(),
    )
    expect(
      within(table).getByRole("button", { name: "Resend email" }),
    ).toBeInTheDocument()
    // The student number/email line must be allowed to wrap on a narrow
    // phone screen instead of forcing horizontal scroll (Stakeholder Doc 17).
    expect(
      within(table).getByText(`${profile.student_number} · ${profile.email}`),
    ).toHaveClass("whitespace-normal")
    // The phone card list must carry the same student-number/email text too
    // — it is its own, separate rendering, not just a CSS-hidden duplicate.
    expect(
      screen.getAllByText(`${profile.student_number} · ${profile.email}`),
    ).toHaveLength(2)
    await user.click(within(table).getByRole("button", { name: profile.name }))

    expect(
      screen.getByRole("dialog", { name: profile.name }),
    ).toBeInTheDocument()
    expect(screen.getByDisplayValue(profile.email)).toBeInTheDocument()
    // The address is shown as its three parts, with the rest of the details the CHED forms ask for.
    expect(screen.getByLabelText("Street / barangay")).toHaveValue(
      profile.street_barangay,
    )
    expect(screen.getByLabelText("Town / city")).toHaveValue(profile.town_city)
    expect(screen.getByLabelText("Province")).toHaveValue(profile.province)
    expect(screen.getByLabelText("Birth date")).toHaveValue(profile.birth_date)
    expect(screen.getByLabelText("Gender")).toHaveTextContent("Female")
    expect(
      screen.queryByLabelText("Enrollment category"),
    ).not.toBeInTheDocument()
    expect(
      screen.getByLabelText("Identity verified in person at Admission"),
    ).toBeInTheDocument()
  })

  it("locks the academic setup selectors once a student already has an enrollment", async () => {
    fetchMock.mockImplementation((input, init) => {
      const url = urlOf(input)
      if (url.includes("/api/v1/programs")) {
        return Promise.resolve(new Response(JSON.stringify(programs)))
      }
      if (url.includes("/api/v1/admission-requirement-types")) {
        return Promise.resolve(new Response(JSON.stringify(intakeRequirements)))
      }
      if (
        url.includes(`/api/v1/student-profiles/${enrolledProfile.id}`) &&
        init?.method === "PATCH"
      ) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: { ...enrolledProfile, street_barangay: "Updated Address" },
            }),
          ),
        )
      }
      if (url.includes("/api/v1/student-profiles")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: [enrolledProfile], ...pagination }),
          ),
        )
      }
      if (url.includes("/api/v1/student-profile-change-requests")) {
        return Promise.resolve(
          new Response(JSON.stringify({ data: [], ...pagination })),
        )
      }
      return Promise.reject(new Error(`Unexpected request: ${url}`))
    })
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(screen.getByRole("tab", { name: "Student Directory" }))
    await user.type(
      screen.getByLabelText("Search student records"),
      enrolledProfile.name,
    )
    await user.click(screen.getByRole("button", { name: "Search" }))
    const directoryTable = await screen.findByRole("table", {
      name: "Student directory",
    })
    await user.click(
      await within(directoryTable).findByRole("button", {
        name: enrolledProfile.name,
      }),
    )

    expect(
      screen.getByRole("dialog", { name: enrolledProfile.name }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        "Student number, program, year level, and admission status are locked because this student already has an enrollment. Entry year, enrollment category, and student type are always set automatically and are never directly editable.",
      ),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText("Student number")).not.toBeInTheDocument()
    expect(screen.getByLabelText("Middle name")).toHaveValue("Ramga")
    expect(screen.queryByLabelText("Program")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Entry year")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Year level")).not.toBeInTheDocument()
    expect(
      screen.queryByLabelText("Enrollment category"),
    ).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Student type")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Financial status")).not.toBeInTheDocument()
    expect(screen.queryByLabelText("Admission status")).not.toBeInTheDocument()

    expect(screen.getByLabelText("First name")).toBeInTheDocument()
    expect(screen.getByLabelText("Last name")).toBeInTheDocument()
    expect(screen.getByLabelText("Middle name")).toBeInTheDocument()
    expect(screen.queryByLabelText("Middle initial")).not.toBeInTheDocument()
    expect(screen.getByLabelText("Suffix")).toBeInTheDocument()
    expect(screen.getByLabelText("Email")).toBeInTheDocument()
    const street = screen.getByLabelText("Street / barangay")
    await user.clear(street)
    await user.type(street, "Updated Address")
    await user.type(
      screen.getByLabelText("Reason for correction"),
      "Student presented an updated barangay certificate.",
    )
    await user.click(
      screen.getByLabelText("Identity verified in person at Admission"),
    )
    await user.click(
      screen.getByRole("button", { name: "Save verified correction" }),
    )

    await waitFor(() => {
      const patchCall = fetchMock.mock.calls.find(
        ([reqInput, reqInit]) =>
          urlOf(reqInput).endsWith(
            `/api/v1/student-profiles/${enrolledProfile.id}`,
          ) && reqInit?.method === "PATCH",
      )
      expect(patchCall).toBeDefined()
      const body = bodyOf(patchCall?.[1])
      expect(body).toMatchObject({ street_barangay: "Updated Address" })
      // The single address text is written by the server from the three parts.
      expect(body).not.toHaveProperty("address")
      expect(body).not.toHaveProperty("student_number")
      expect(body).not.toHaveProperty("program_id")
      expect(body).not.toHaveProperty("entry_year")
      expect(body).not.toHaveProperty("year_level")
      expect(body).not.toHaveProperty("enrollment_category")
      expect(body).not.toHaveProperty("student_type")
      expect(body).not.toHaveProperty("financial_status")
      expect(body).not.toHaveProperty("admission_status")
    })
  })

  describe("a student record from before these details were collected", () => {
    const olderProfile = {
      ...profile,
      id: 34,
      student_number: "2026-08-01098",
      name: "Rosa Reyes",
      first_name: "Rosa",
      last_name: "Reyes",
      email: "rosa.reyes@grc.test",
      address: "Old Address, Caloocan City",
      gender: null,
      birth_date: null,
      citizenship: null,
      contact_number: null,
      street_barangay: null,
      town_city: null,
      province: null,
      generated_fields: [],
      academic_setup_editable: false,
      account_setup_status: "active",
    } as const

    async function openRecord(
      user: ReturnType<typeof userEvent.setup>,
      record: Record<string, unknown> & { name: string; id: number },
    ) {
      fetchMock.mockImplementation((input, init) => {
        const url = urlOf(input)
        if (url.includes("/api/v1/programs")) {
          return Promise.resolve(new Response(JSON.stringify(programs)))
        }
        if (url.includes("/api/v1/admission-requirement-types")) {
          return Promise.resolve(
            new Response(JSON.stringify(intakeRequirements)),
          )
        }
        if (
          url.includes(`/api/v1/student-profiles/${record.id}`) &&
          init?.method === "PATCH"
        ) {
          return Promise.resolve(new Response(JSON.stringify({ data: record })))
        }
        if (url.includes("/api/v1/student-profiles")) {
          return Promise.resolve(
            new Response(JSON.stringify({ data: [record], ...pagination })),
          )
        }
        if (url.includes("/api/v1/student-profile-change-requests")) {
          return Promise.resolve(
            new Response(JSON.stringify({ data: [], ...pagination })),
          )
        }
        return Promise.reject(new Error(`Unexpected request: ${url}`))
      })
      renderWorkspace()
      await user.click(screen.getByRole("tab", { name: "Student Directory" }))
      await user.type(
        screen.getByLabelText("Search student records"),
        record.name,
      )
      await user.click(screen.getByRole("button", { name: "Search" }))
      const table = await screen.findByRole("table", {
        name: "Student directory",
      })
      await user.click(
        await within(table).findByRole("button", { name: record.name }),
      )
    }

    it("can be corrected without filling the new details, and the blank ones are not sent", async () => {
      const user = userEvent.setup()
      await openRecord(user, olderProfile)

      expect(screen.getByLabelText("Street / barangay")).toHaveValue("")
      // The address already on file stays visible until the three parts are typed.
      expect(
        screen.getByText("Address on file: Old Address, Caloocan City"),
      ).toBeInTheDocument()

      await user.clear(screen.getByLabelText("Last name"))
      await user.type(screen.getByLabelText("Last name"), "Reyes-Cruz")
      await user.type(
        screen.getByLabelText("Reason for correction"),
        "Corrected the surname on the marriage certificate.",
      )
      await user.click(
        screen.getByLabelText("Identity verified in person at Admission"),
      )
      await user.click(
        screen.getByRole("button", { name: "Save verified correction" }),
      )

      await waitFor(() => {
        const patchCall = fetchMock.mock.calls.find(
          ([reqInput, reqInit]) =>
            urlOf(reqInput).endsWith(
              `/api/v1/student-profiles/${olderProfile.id}`,
            ) && reqInit?.method === "PATCH",
        )
        expect(patchCall).toBeDefined()
        const body = bodyOf(patchCall?.[1])
        expect(body).toMatchObject({ last_name: "Reyes-Cruz" })
        for (const key of [
          "gender",
          "birth_date",
          "citizenship",
          "contact_number",
          "street_barangay",
          "town_city",
          "province",
          "address",
          "enrollment_category",
        ]) {
          expect(body).not.toHaveProperty(key)
        }
      })
    })

    it("tells Admission which details were generated for the demo, so the real ones can be entered", async () => {
      const user = userEvent.setup()
      await openRecord(user, {
        ...olderProfile,
        gender: "M",
        birth_date: "2004-01-01",
        generated_fields: ["gender", "birth_date"],
      })

      const dialog = screen.getByRole("dialog", { name: olderProfile.name })
      expect(
        within(dialog).getAllByText(
          "Generated for the demo. Enter the student's real value.",
        ),
      ).toHaveLength(2)
      expect(screen.getByLabelText("Gender")).toHaveTextContent("Male")
      expect(screen.getByLabelText("Birth date")).toHaveValue("2004-01-01")
    })
  })

  it("compares requested values and requires in-person verification before approval", async () => {
    fetchMock.mockImplementation((input, init) => {
      const url = urlOf(input)
      if (url.includes("/api/v1/programs")) {
        return Promise.resolve(new Response(JSON.stringify(programs)))
      }
      if (url.includes("/api/v1/admission-requirement-types")) {
        return Promise.resolve(new Response(JSON.stringify(intakeRequirements)))
      }
      if (url.endsWith("/decision") && init?.method === "PATCH") {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: {
                ...changeRequest,
                status: "approved",
                status_label: "Approved",
                official: changeRequest.requested,
                identity_verified_at: "2026-08-26T09:00:00Z",
                decided_at: "2026-08-26T09:00:00Z",
              },
            }),
          ),
        )
      }
      if (url.includes("student-profile-change-requests")) {
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: [changeRequest], ...pagination }),
          ),
        )
      }
      return Promise.resolve(new Response(JSON.stringify({ data: [] })))
    })
    const user = userEvent.setup()
    renderWorkspace()

    await user.click(screen.getByRole("tab", { name: "Change Requests" }))
    await user.click(await screen.findByRole("button", { name: "Review" }))

    expect(screen.getByText(changeRequest.official.email)).toBeInTheDocument()
    expect(screen.getByText(changeRequest.requested.email)).toBeInTheDocument()
    const approve = screen.getByRole("button", { name: "Approve changes" })
    expect(approve).toBeDisabled()
    await user.click(
      screen.getByLabelText("Student identity verified in person at Admission"),
    )
    expect(approve).toBeEnabled()
    await user.click(approve)

    await waitFor(() => {
      const decision = fetchMock.mock.calls.find(
        ([input, init]) =>
          urlOf(input).endsWith("/decision") && init?.method === "PATCH",
      )
      expect(bodyOf(decision?.[1])).toEqual({
        action: "approve",
        identity_verified_in_person: true,
      })
    })
  })
})
