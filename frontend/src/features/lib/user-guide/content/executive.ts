import type { GuideChapter } from "../types"

export const executiveChapter: GuideChapter = {
  id: "executive_director",
  title: "Executive Director",
  tagline: "Publish approved schedules and see the whole institution",
  intro: [
    "The Executive Director gives the final approval to the enrollment plans that the Deans have approved, publishes them, approves curricula, and follows enrollment across the institution. You cannot change detailed student records.",
    "Your menu has four pages: Enrollment, Curriculum Approvals, Institution Dashboard and Enrollment Dashboard.",
  ],
  sections: [
    {
      id: "enrollment",
      title: "Enrollment: publish the schedules",
      blocks: [
        {
          kind: "paragraph",
          text: "A department plan reaches you after its Dean has approved it. When you publish it, its sections become open for students to choose. Open **Enrollment**: **For review** lists the plans that wait for an Executive Director decision.",
        },
        {
          kind: "steps",
          items: [
            { text: "Select **Review schedule** to read a department's plan." },
            { text: "Select **Publish schedule** to approve and publish it." },
            { text: "Or select **Return with notes** if it needs revision." },
            { text: "Check the confirmation and select **Confirm decision**." },
          ],
        },
        {
          kind: "figure",
          shot: "executive-master-schedule",
          caption: "Steps 1 to 3: the department plans that wait for you.",
        },
        {
          kind: "figure",
          shot: "executive-confirm-publish",
          caption:
            "Step 4: publishing is confirmed and recorded in the audit log.",
        },
        {
          kind: "note",
          tone: "careful",
          text: "The Registrar Head can start enrollment once at least one college has published its schedule. A returned plan goes back to the Program Head with your notes.",
        },
        {
          kind: "paragraph",
          text: "**Decision History** lists every plan with its status and review history, and the published sections of the current master schedule.",
        },
      ],
    },
    {
      id: "curriculum-approvals",
      title: "Curriculum Approvals",
      blocks: [
        {
          kind: "paragraph",
          text: "Curricula submitted by Program Heads wait here for your decision as well.",
        },
        {
          kind: "figure",
          shot: "executive-curriculum-approvals",
          caption: "Curriculum Approvals.",
        },
        {
          kind: "steps",
          items: [
            { text: "Select **Review** to read a curriculum." },
            {
              text: "Select **Approve**, or **Return with notes** and write the notes for the Program Head. A reason is required.",
            },
          ],
        },
      ],
    },
    {
      id: "institution-dashboard",
      title: "Institution Dashboard",
      blocks: [
        {
          kind: "paragraph",
          text: "Institution-level numbers: enrollment status, programs and sections, students by program, and the year-over-year trend.",
        },
        {
          kind: "figure",
          shot: "executive-institution-dashboard",
          caption: "The Institution Dashboard.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Choose **Active term** or **All terms** to change what the numbers cover.",
            },
            {
              text: "Read **Students by program** and **Year over year**, which shows the enrollment count of each school year, oldest first.",
            },
            { text: "Select **View & Print Report** to print the page." },
          ],
        },
      ],
    },
    {
      id: "dashboard",
      title: "Enrollment Dashboard",
      blocks: [
        {
          kind: "paragraph",
          text: "Enrollment status for the whole institution, from the overall count down to each student.",
        },
        {
          kind: "figure",
          shot: "executive-enrollment-dashboard",
          caption: "The Enrollment Dashboard for the institution.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the four groups: **Enrolled**, **Ongoing**, **Not yet done** and **Not enrolled**.",
            },
            { text: "Switch between **All Departments** and one department." },
            {
              text: "Select a department, a section or a count to see the students in it.",
            },
          ],
        },
      ],
    },
  ],
}
