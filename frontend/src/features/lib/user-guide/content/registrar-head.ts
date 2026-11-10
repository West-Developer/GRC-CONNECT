import type { GuideChapter } from "../types"

export const registrarHeadChapter: GuideChapter = {
  id: "registrar_head",
  title: "Registrar Head",
  tagline: "Open the term, approve enrollments, lock grades and report to CHED",
  intro: [
    "The Registrar Head runs the enrollment cycle and the official records. You create each school year and semester, start enrollment, give the final approval to enrollments and credits, lock submitted grades, decide schedule change requests and prepare the CHED reports. Every override you make is recorded in the audit log.",
    "Your menu has twenty pages. This chapter groups them by the work they belong to.",
  ],
  sections: [
    {
      id: "term-at-a-glance",
      title: "The cycle at a glance",
      blocks: [
        {
          kind: "journey",
          items: [
            {
              who: "You",
              what: "Create the school year and semester, which starts as a Draft term.",
            },
            {
              who: "Program Heads, Deans and Executive Director",
              what: "Plan, approve and publish the schedules of each college.",
            },
            {
              who: "You",
              what: "Set the enrollment dates and select **Start enrollment** once at least one college has published.",
            },
            {
              who: "Registrar Staff or you",
              what: "Approve each submitted enrollment so the student can go to the Cashier.",
            },
            {
              who: "Professors",
              what: "Submit the final grades of their sections.",
            },
            {
              who: "You",
              what: "Lock the submitted grades, then archive the semester. The next one opens automatically as a Draft.",
            },
          ],
        },
      ],
    },
    {
      id: "enrollment",
      title: "Enrollment: the term and its dates",
      blocks: [
        {
          kind: "paragraph",
          text: "The four steps at the top show where the current term is: **Term Setup**, **College Planning**, **Enrollment Ongoing** and **Archive**.",
        },
        {
          kind: "steps",
          items: [
            { text: "Enter the **School year**, for example 2026-2027." },
            { text: "Choose the **Semester**." },
            {
              text: "Set the **Enrollment start**. Enrollment opens at 8:00 AM on that date.",
            },
            {
              text: "Set the **Enrollment deadline**. Enrollment closes at 11:59 PM on that date.",
            },
            { text: "Set the **Add/drop/Change subject deadline**." },
            {
              text: "Select **Create school year**. The new term starts as a Draft.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "registrar-head-term-form",
          caption:
            "The form that creates a school year and semester. It appears when no school year is open yet.",
        },
        {
          kind: "figure",
          shot: "registrar-head-term-created",
          caption:
            "After you create it, the page confirms the new term as a Draft and shows the enrollment schedule.",
        },
        {
          kind: "note",
          tone: "know",
          text: "You use this form only for the very first term. In every later cycle, selecting **Archive current semester** closes the semester, keeps it in history and opens the next one automatically as a Draft.",
        },
        {
          kind: "paragraph",
          text: "Before you start, set these on the **Enrollment schedule** card:",
        },
        {
          kind: "bullets",
          items: [
            "The term-wide enrollment start and deadline dates. Enrollment always opens at 8:00 AM on the start date and closes at 11:59 PM on the deadline date.",
            "If one audience needs a different window, set it under **Per-audience window**: each year level, irregular students and late enrollees.",
            "Under **Add, Drop & Change Subject Schedule**, the period when enrolled students may ask to change subjects.",
            "The **Platform for this term** (Online, Face-to-Face or Both). It is printed on every student's Certificate of Registration.",
          ],
        },
        {
          kind: "steps",
          items: [
            {
              text: "Check **Publication readiness**: each college is marked published or not published.",
            },
            { text: "Select **Save enrollment schedule**." },
            { text: "Select **Start enrollment**." },
          ],
        },
        {
          kind: "figure",
          shot: "registrar-head-start-enrollment",
          caption:
            "The enrollment schedule card, with the readiness of each college.",
        },
        {
          kind: "note",
          tone: "careful",
          text: "**Start enrollment** works only after at least one college has published its schedule. Once enrollment is ongoing, the page shows **Enrollment is ongoing** and **Archive current semester**.",
        },
        {
          kind: "figure",
          shot: "registrar-head-academic-terms",
          caption:
            "Once enrollment has started, the cycle shows Enrollment Ongoing.",
        },
      ],
    },
    {
      id: "enrollment-approvals",
      title: "Enrollment Approvals",
      blocks: [
        {
          kind: "paragraph",
          text: "Every submitted enrollment, regular or irregular, needs your approval or a Registrar Staff member's. Irregular enrollments arrive here after the Program Head has approved them. An approved student then takes a queue number at the Cashier kiosk.",
        },
        {
          kind: "figure",
          shot: "registrar-head-enrollment-approvals",
          caption: "The enrollment queue, filtered to what needs a decision.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Enrollment Approvals**. **Pending review** is selected.",
            },
            {
              text: "Select **Review** to see the student, the academic load, the unit overload check, the timetable conflict check and each subject.",
            },
            {
              text: "Select **View Prospectus** if you need the student's whole record.",
            },
            { text: "Select **Approve**, or **Reject** and write the reason." },
          ],
        },
        {
          kind: "bullets",
          items: [
            "**Awaiting Program Head**: an irregular enrollment that the Program Head has not decided yet.",
            "**Approved**: waiting for payment. **Enrolled students**: the Cashier confirmed payment.",
            "**Rejected**: the student is told the reason.",
          ],
        },
        {
          kind: "note",
          tone: "tip",
          text: "Search by student number, name or email to find one enrollment.",
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
            "Select a student's name to open their details: program, curriculum, student type, standing and more.",
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
          text: "Credits that a Program Head has mapped and endorsed wait here for your approval.",
        },
        {
          kind: "figure",
          shot: "registrar-head-credit-mappings",
          caption: "Students whose credits wait for your approval.",
        },
        {
          kind: "paragraph",
          text: "Select **Review** for a student. Each previous subject is shown beside the subject the Program Head mapped it to.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "If a mapping is wrong, select **Return** and write why. The Program Head is told.",
            },
            {
              text: "If a subject cannot be credited, select **Reject** and write why. The student is told.",
            },
            {
              text: "When the mappings are right, select **Approve all** to approve all of the student's credits at once.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "registrar-head-credit-review",
          caption: "The credit review panel.",
        },
        {
          kind: "note",
          tone: "know",
          text: "Once the Program Head has passed the credits to you, the student can already enroll and the credits count provisionally. The Prospectus marks them as awaiting your approval.",
        },
      ],
    },
    {
      id: "submitted-schedules",
      title: "Submitted Schedules",
      blocks: [
        {
          kind: "paragraph",
          text: "A read-only view of the schedule each Program Head submitted and of the sections already published.",
        },
        {
          kind: "figure",
          shot: "registrar-head-submitted-schedules",
          caption: "Submitted schedules and the decision history.",
        },
        {
          kind: "bullets",
          items: [
            "**Submitted plans**: the current published plan of each college for the active term. Open one to see its sections.",
            "**History**: every decision with the term, college, decision, date and notes.",
          ],
        },
      ],
    },
    {
      id: "schedule-changes",
      title: "Schedule Change Requests",
      blocks: [
        {
          kind: "paragraph",
          text: "A Program Head cannot edit a published schedule. They ask you to, and you decide.",
        },
        {
          kind: "figure",
          shot: "registrar-head-section-change-requests",
          caption: "Requests to change a published schedule.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open a request and compare what is published with what the Program Head asks for.",
            },
            { text: "Read the reason the Program Head gave." },
            { text: "Approve or reject it." },
          ],
        },
      ],
    },
    {
      id: "grade-approvals",
      title: "Grade Approvals",
      blocks: [
        {
          kind: "paragraph",
          text: "Locking submitted grades makes them count toward prerequisites and each student's standing.",
        },
        {
          kind: "figure",
          shot: "registrar-head-grade-approvals",
          caption: "Submitted grades waiting to be locked.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Pending approvals**. Filter by department if you need to: grades are grouped as Professor, then Subjects, then Students.",
            },
            {
              text: "Open a student's grades, lock them and confirm **Lock this grade?**.",
            },
            {
              text: "At the end of the semester, select **Lock all grades for this semester** and confirm.",
            },
            {
              text: "Open **Grade history** to search the official locked grades of every semester.",
            },
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "Locking is part of the official record. After it, a correction needs an audited request. Locking can also change a student's standing: a failed, incomplete or dropped subject can make the student irregular next semester.",
        },
      ],
    },
    {
      id: "transcripts",
      title: "Academic Transcripts",
      blocks: [
        {
          kind: "paragraph",
          text: "View and print any student's prospectus and grade slip.",
        },
        {
          kind: "figure",
          shot: "registrar-head-academic-transcripts",
          caption: "Look up a student by number or name.",
        },
        {
          kind: "steps",
          items: [
            { text: "Choose **By Student Number** or **By Student Name**." },
            { text: "Select **View transcript**." },
            {
              text: "Select **Search another student** to look up someone else.",
            },
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
          text: "The official register of graduated students across all programs and school years.",
        },
        {
          kind: "figure",
          shot: "registrar-head-graduates",
          caption: "The graduates directory.",
        },
        {
          kind: "steps",
          items: [
            { text: "Search by name or student ID." },
            {
              text: "Filter by program, graduation school year or curriculum version.",
            },
            {
              text: "Read the student, program, curriculum, graduation year and final GPA.",
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
          text: "Find a student's confirmed Certificate of Registration history and print any of them.",
        },
        {
          kind: "figure",
          shot: "registrar-head-cor-records",
          caption: "Find a student's COR.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Enter a **Student number**, a **Student name**, or both to narrow the results.",
            },
            {
              text: "Open the student to see their **Confirmed COR history**.",
            },
            { text: "Select **View COR**, then print or **Download COR**." },
          ],
        },
      ],
    },
    {
      id: "enrollment-requests",
      title: "Enrollment Requests",
      blocks: [
        {
          kind: "paragraph",
          text: "Student requests made after enrollment: withdrawals, drops, added subjects and section changes.",
        },
        {
          kind: "figure",
          shot: "registrar-head-enrollment-requests",
          caption: "Enrollment requests by type.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Choose the type: **Withdrawals**, **Drops**, **Add subject** or **Change section**.",
            },
            {
              text: "Open a request, read the student's reason, then approve or reject it.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "Students can ask to drop or add a subject only during the add/drop window you set on the Enrollment page. It opens once enrollment closes for the term.",
        },
      ],
    },
    {
      id: "analytics",
      title: "Analytics",
      blocks: [
        {
          kind: "figure",
          shot: "registrar-head-registrar-analytics",
          caption: "Enrollment Analytics across all departments.",
        },
        {
          kind: "paragraph",
          text: "**Enrollment Analytics** shows official enrollment trends across all departments, or one department you pick. **Attrition Analytics** shows anonymous cohort counts of students who will not continue: those who withdrew, and those who finished the first semester but did not enroll in the second after its window closed.",
        },
        {
          kind: "figure",
          shot: "registrar-head-attrition-analytics",
          caption:
            "Attrition Analytics, by cohort school year, college, program and year level.",
        },
        {
          kind: "note",
          tone: "careful",
          text: "Predictions and attrition figures are advice for planning. They never refuse or change a student's enrollment.",
        },
      ],
    },
    {
      id: "ched-reports",
      title: "CHED Reports",
      blocks: [
        {
          kind: "paragraph",
          text: "Preview and download the CHED Enrollment List and the NSTP forms for a term, as a CSV file or in CHED's own Excel template.",
        },
        {
          kind: "figure",
          shot: "registrar-head-ched-reports",
          caption: "CHED Reports.",
        },
        {
          kind: "steps",
          items: [
            { text: "Choose the **School year** and **Semester**." },
            {
              text: "Choose the report tab: **Enrollment List**, **NSTP 2 Enrollment**, **NSTP 2 Graduates** or **Form 2-A Summary**.",
            },
            {
              text: "Read the preview. Select a student to see every field that goes into the file.",
            },
            {
              text: "Select **Download CSV**, or **Download Excel** for CHED's own template, ready to submit.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "The download always contains every student of the term, not only the search results. Every preview and download is recorded in the audit log.",
        },
      ],
    },
    {
      id: "dashboard",
      title: "Enrollment Dashboard",
      blocks: [
        {
          kind: "figure",
          shot: "registrar-head-enrollment-dashboard",
          caption: "Enrollment status across all departments.",
        },
        {
          kind: "paragraph",
          text: "Counts for the active term, from the overall figure down to each student: **Enrolled**, **Ongoing**, **Not yet done** and **Not enrolled**. Switch between **All Departments** and one department, and select any count to see the students in it.",
        },
      ],
    },
    {
      id: "payment-discount",
      title: "Payment Discount",
      blocks: [
        {
          kind: "paragraph",
          text: "The discount given to students who pay their assessment in full, and the dates it is valid. Changes apply to new payments only.",
        },
        {
          kind: "figure",
          shot: "registrar-head-payment-discount",
          caption: "The full-payment discount.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Enter the **Discount percentage**, from 0 to 100 with at most one decimal. Enter 0 to turn the discount off.",
            },
            {
              text: "Set **Valid from** and **Valid until**. Leave one empty for no start or no end date.",
            },
            { text: "Select **Save discount**." },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "Payments and Certificates of Registration that already exist are not changed.",
        },
      ],
    },
    {
      id: "audit-logs",
      title: "Audit Logs",
      blocks: [
        { kind: "paragraph", text: "See who changed what." },
        {
          kind: "figure",
          shot: "registrar-head-audit-logs",
          caption: "The audit log with its filters.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Filter by **Action**, **Entity type** or a date range, then select **Apply audit filters**.",
            },
            { text: "Open a person to read their activity." },
            { text: "Open a record to compare the old and new values." },
          ],
        },
      ],
    },
    {
      id: "rooms",
      title: "Rooms",
      blocks: [
        {
          kind: "paragraph",
          text: "The authoritative list of rooms, and every scheduled use of them.",
        },
        {
          kind: "figure",
          shot: "registrar-head-rooms",
          caption: "The room inventory.",
        },
        {
          kind: "bullets",
          items: [
            "Pick a school year, semester and room to see its full weekly schedule across all colleges.",
            "**Awaiting a room** lists sections that still have no room.",
          ],
        },
      ],
    },
    {
      id: "faculty-workforce",
      title: "Faculty Workforce",
      blocks: [
        {
          kind: "paragraph",
          text: "Professors of every college, and the subjects each is approved to teach.",
        },
        {
          kind: "figure",
          shot: "registrar-head-faculty-workforce",
          caption: "The faculty roster across all colleges.",
        },
        {
          kind: "paragraph",
          text: "Search a professor by name and filter by college, status and employment type. As Registrar Head you can read the roster. Changes belong to each college's Program Head.",
        },
      ],
    },
    {
      id: "invite-staff",
      title: "Invite Staff",
      blocks: [
        {
          kind: "paragraph",
          text: "Invite anyone into a GRC staff account by email. They set their own name and password with a one-time code.",
        },
        {
          kind: "figure",
          shot: "registrar-head-staff-invitations",
          caption: "Invite staff and follow their status.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Under **Invite by email**, add one or more email addresses and choose the **Role** of each person: Professor / Faculty, Executive Director, Registrar Head, Registrar Staff or Accounting Staff.",
            },
            { text: "Send the invitations." },
            {
              text: "Follow them in **Invited staff**: **Invited**, active, or **Delivery failed**. Select **Resend** if a code expired or did not arrive.",
            },
          ],
        },
      ],
    },
  ],
}
