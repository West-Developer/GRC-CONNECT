import type { GuideChapter } from "../types"

export const admissionChapter: GuideChapter = {
  id: "admission_staff",
  title: "Admission Staff",
  tagline: "Create student accounts and keep official records correct",
  intro: [
    "Admission creates every student's account and keeps the verified profile and the list of submitted requirements. You cannot edit grades, curriculum, payments or Registrar approvals.",
    "Your menu has two pages: Student Records and Enrollment Dashboard.",
  ],
  sections: [
    {
      id: "create-account",
      title: "Create a student account",
      blocks: [
        {
          kind: "paragraph",
          text: "Create an account only after Admission has received the student's requirements. No password is ever shown or typed for the student: the system emails a one-time setup code instead.",
        },
        {
          kind: "figure",
          shot: "admission-student-records",
          caption: "Student Records opens on the Create Account tab.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Student Records** and stay on the **Create Account** tab.",
            },
            {
              text: "Fill in **First name**, **Last name** and **Email address**.",
              detail:
                "**Middle name** and **Suffix** are optional. The full middle name stays in the student's record, and everywhere else only its first letter is shown.",
            },
            {
              text: "Fill in **Gender**, **Birth date**, **Citizenship**, **Contact number** and the address: **Street / barangay**, **Town / city** and **Province**.",
            },
            {
              text: "Choose the **Student type**: Freshman, Transferee, Returnee or Existing Student.",
            },
            {
              text: "Check the **Student number**.",
              detail:
                "It is the next number in order and is confirmed when you save. Tick **Student already has a student number** only for a transferee, a returnee or an existing student, then type it as YYYY-MM-NNNNN. A freshman always gets a new number.",
            },
            {
              text: "Choose the **Program** and the **Year level**. The curriculum is picked automatically from the program and entry year.",
            },
            { text: "Select **Create account and email setup**." },
          ],
        },
        {
          kind: "figure",
          shot: "admission-create-account-filled",
          caption:
            "The form filled in for a freshman: names, email, gender, birth date, citizenship, contact number and address.",
        },
        {
          kind: "figure",
          shot: "admission-create-account-type",
          caption:
            "The lower part of the form: student type, student number, program and year level.",
        },
        {
          kind: "note",
          tone: "know",
          title: "Freshmen pay first",
          text: "For a freshman the button reads **Save student and issue queue number**. The system emails a queue number with a link to the live queue. The account setup email follows only after the Cashier confirms the freshman's first payment.",
        },
        {
          kind: "note",
          tone: "tip",
          text: "Your unsaved details are kept in this browser until you create the account or sign out. Select **Clear form** to start over.",
        },
        {
          kind: "paragraph",
          text: "The panel on the right, **Account setup delivery**, shows whether the latest invitation was sent and whether the account is active. The **Admission requirements** card lets you tick each document as you receive it.",
        },
        {
          kind: "figure",
          shot: "admission-account-created",
          caption:
            "After saving a freshman, the form clears and the panel on the right shows the queue number and that the live queue link was emailed. **Resend queue link** sends it again.",
        },
      ],
    },
    {
      id: "directory",
      title: "Student directory",
      blocks: [
        { kind: "paragraph", text: "Open the **Student Directory** tab." },
        {
          kind: "figure",
          shot: "admission-student-directory",
          caption: "Find a student by name, number or email.",
        },
        {
          kind: "steps",
          items: [
            { text: "Type a name, student number or email in the search box." },
            { text: "Select **Search**." },
            {
              text: "Open the student to read the record and the requirements checklist.",
            },
            {
              text: "To correct an official detail, change it, give the reason and select **Save verified correction**.",
              detail:
                "Tick **Identity verified in person at Admission** when you have checked the student's documents.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "You can also set the admission status to Pending, Admitted, Enrolled, Graduated or Withdrawn.",
        },
      ],
    },
    {
      id: "freshmen",
      title: "Freshmen",
      blocks: [
        {
          kind: "figure",
          shot: "admission-freshmen",
          caption:
            "The Freshmen tab shows who has paid and whose account is ready.",
        },
        {
          kind: "bullets",
          items: [
            "**Not paid yet**: the freshman has a queue number but the Cashier has not confirmed the first payment.",
            "**Paid**: the payment is confirmed and the setup email has been sent. The account is ready once the student sets a password.",
            "**Resend queue link** sends the queue number and live-queue link again.",
            "**Resend setup email** sends a new setup code to a freshman whose code expired or never arrived.",
          ],
        },
      ],
    },
    {
      id: "change-requests",
      title: "Change requests",
      blocks: [
        {
          kind: "paragraph",
          text: "A student can ask for a correction to their name, email or address. The change takes effect only after Admission verifies it.",
        },
        {
          kind: "figure",
          shot: "admission-change-requests",
          caption: "Student information change requests.",
        },
        {
          kind: "steps",
          items: [
            { text: "Open the **Change Requests** tab and select a request." },
            {
              text: "Compare the requested details with the student's record and read the student's reason.",
            },
            { text: "Check the student's documents." },
            {
              text: "Select **Approve changes**, or write **Decision notes** and select **Reject with notes**.",
            },
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "Decision notes are required when you reject a request, so the student knows what to bring or correct.",
        },
      ],
    },
    {
      id: "dashboard",
      title: "Enrollment Dashboard",
      blocks: [
        {
          kind: "paragraph",
          text: "A live count of the students who are still in the admission process for the current term.",
        },
        {
          kind: "figure",
          shot: "admission-enrollment-dashboard",
          caption: "The Enrollment Dashboard for Admission.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the number of students counted and the four groups: Enrolled, Ongoing, Not yet done and Not enrolled.",
            },
            {
              text: "Select a group, a department or a section to see the students in it.",
            },
            { text: "Select **Refresh** to load the latest numbers." },
          ],
        },
      ],
    },
  ],
}
