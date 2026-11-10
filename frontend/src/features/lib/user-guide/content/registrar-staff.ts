import type { GuideChapter } from "../types"

export const registrarStaffChapter: GuideChapter = {
  id: "registrar_staff",
  title: "Registrar Staff",
  tagline: "Approve enrollments and keep enrollment records in order",
  intro: [
    "Registrar Staff approve or reject the enrollments students submit, so that students can go on to payment. You also follow credit mappings, handle withdrawal requests and look up Certificates of Registration. The Registrar Head makes the overrides and the credit decisions: you cannot.",
    "Your menu has seven pages: Enrollment Approvals, Students, Credit Mappings, Enrollment Requests, COR Records, Graduates and Enrollment Dashboard.",
  ],
  sections: [
    {
      id: "enrollment-approvals",
      title: "Enrollment Approvals",
      blocks: [
        {
          kind: "paragraph",
          text: "Every submitted enrollment, regular or irregular, passes through you. Irregular enrollments arrive after the Program Head has approved them. Once you approve, the student can take a queue number at the Cashier kiosk.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Enrollment Approvals** and stay on **Pending review**.",
            },
            { text: "Select **Review** on a row to read the enrollment." },
            { text: "Select **Approve** to accept it." },
            {
              text: "Or select **Reject**, write the reason and select **Confirm decision**. The student is told the reason.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "registrar-staff-enrollment-approvals",
          caption:
            "The enrollment queue, with a Review, Approve and Reject button on each row.",
        },
        {
          kind: "paragraph",
          text: "The review window shows what you need to decide. Check the **Academic Load**, the **Unit Overload Status** and the **Timetable Conflict Check**. Select **View Prospectus** to see the student's curriculum and grades, and **Calendar timetable** to see the week.",
        },
        {
          kind: "figure",
          shot: "registrar-review-enrollment",
          caption:
            "The review window shows the student, the load and every subject.",
        },
        {
          kind: "figure",
          shot: "registrar-confirm-approve",
          caption:
            "Approving asks you to confirm. Once confirmed, the student can go to the Cashier.",
        },
        {
          kind: "figure",
          shot: "registrar-reject-enrollment",
          caption: "Rejecting an enrollment needs a reason.",
        },
        {
          kind: "bullets",
          items: [
            "**Awaiting Program Head**: an irregular enrollment that the Program Head has not decided yet.",
            "**Approved**: waiting for the student to pay. **Enrolled students**: the Cashier confirmed the payment.",
            "Search by student number, name or email to find one enrollment.",
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "A freshman or a transferee who paid first is held at payment before you see them. Approval is what enrolls them from the money already on file.",
        },
      ],
    },
    {
      id: "students",
      title: "Students",
      blocks: [
        {
          kind: "paragraph",
          text: "A read-only list of every student, with their program, year level, category and academic standing.",
        },
        {
          kind: "bullets",
          items: [
            "Search by student number or name to find one student.",
            "Only the Registrar Head can open a student's details from this list.",
          ],
        },
      ],
    },
    {
      id: "credit-mappings",
      title: "Credit Mappings",
      blocks: [
        {
          kind: "paragraph",
          text: "Follow the credit mappings that the Program Head has endorsed. The Registrar Head decides them, so this page is read-only for you.",
        },
        {
          kind: "figure",
          shot: "registrar-staff-credit-mappings",
          caption: "Credit mappings you can follow.",
        },
      ],
    },
    {
      id: "enrollment-requests",
      title: "Enrollment Requests",
      blocks: [
        {
          kind: "paragraph",
          text: "Requests students make after they enroll.",
        },
        {
          kind: "figure",
          shot: "registrar-staff-enrollment-requests",
          caption: "Enrollment requests by type.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Choose **Withdrawals** to approve or reject a student's withdrawal from the term.",
            },
            {
              text: "Choose **Drops**, **Add subject** or **Change section** to view those requests.",
            },
            {
              text: "Open a request, read the student's reason and make your decision.",
            },
          ],
        },
      ],
    },
    {
      id: "cor-records",
      title: "COR Records",
      blocks: [
        {
          kind: "paragraph",
          text: "Find, review and print any student's confirmed Certificate of Registration.",
        },
        {
          kind: "figure",
          shot: "registrar-staff-cor-records",
          caption: "Find a student's COR.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Enter a **Student number**, a **Student name**, or both.",
            },
            { text: "Open the student's **Confirmed COR history**." },
            { text: "Select **View COR**, then print it or **Download COR**." },
          ],
        },
      ],
    },
    {
      id: "graduates",
      title: "Graduates",
      blocks: [
        {
          kind: "paragraph",
          text: "The official register of graduated students.",
        },
        {
          kind: "figure",
          shot: "registrar-staff-graduates",
          caption: "The graduates directory.",
        },
        {
          kind: "bullets",
          items: [
            "Search by student name or ID.",
            "Filter by program, graduation school year or curriculum version.",
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
          text: "Shows only the students who are waiting for the Registrar's approval.",
        },
        {
          kind: "figure",
          shot: "registrar-staff-enrollment-dashboard",
          caption: "The Enrollment Dashboard for Registrar Staff.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Select a department, a section or a student to see who is waiting.",
            },
            { text: "Select **Refresh** for the latest counts." },
          ],
        },
      ],
    },
  ],
}
