import type { GuideChapter } from "../types"

export const programChairChapter: GuideChapter = {
  id: "program_chair",
  title: "Program Head",
  tagline:
    "Plan the term's sections, approve irregular enrollments and map credits",
  intro: [
    "The Program Head turns the college's curriculum into a schedule that the Dean and the Executive Director can approve, assigns professors and rooms, advises irregular students, and maps the credits of students who come from another school.",
    "Your menu has nine pages. They are grouped below in the order you meet them during a term. Irregular Advising and Credit Mappings are tabs on the **Enrollment** page, not menu pages of their own: a red number above a tab says how many students are waiting for you there, and the same total shows beside **Enrollment** in the menu.",
  ],
  sections: [
    {
      id: "term-at-a-glance",
      title: "Your semester at a glance",
      blocks: [
        {
          kind: "journey",
          items: [
            {
              who: "Registrar Head",
              what: "Creates the school year and semester. Enrollment planning opens for you.",
            },
            {
              who: "You",
              what: "Choose the curriculum and the number of block sections for each year level, generate the schedule, assign professors and rooms, and submit it for approval.",
            },
            {
              who: "Dean, then Executive Director",
              what: "Approve the schedule. If they return it, the note tells you what to fix.",
            },
            {
              who: "Students",
              what: "Enroll once the Registrar Head starts enrollment.",
            },
            {
              who: "You",
              what: "Approve or change the enrollment of each irregular or overloaded student of your college.",
            },
            {
              who: "You",
              what: "Map the credited subjects of transferees, then send them to the Registrar Head.",
            },
          ],
        },
      ],
    },
    {
      id: "enrollment",
      title: "Enrollment: plan the term's sections",
      blocks: [
        {
          kind: "paragraph",
          text: "This is where you build the schedule. It opens after the Registrar Head creates the school year and semester. Until then the page says it is waiting for the Registrar.",
        },
        {
          kind: "figure",
          shot: "chair-planning-form",
          caption:
            "Enrollment while the schedule is being planned: Demand Forecast, Generate Schedule and one tab per year level.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Select **Demand Forecast** to see the advisory demand for each subject.",
              detail:
                "It uses past enrollment, the curriculum, ranked professor preferences and declared availability. It is advice only: you can edit every recommendation.",
            },
            {
              text: "In each year-level tab, choose the **Curriculum** and enter the **Number of block sections** and the **Students per block**, then select **Save and continue**.",
              detail:
                "Repeat for the 1st to the 4th year. Every year level must be complete before you generate subjects.",
            },
            {
              text: "Select **Generate Schedule** to build a draft, or **Generate subject list** to release the current-semester subjects of each block.",
            },
            {
              text: "Open the **Review** tab and check each block section.",
              detail:
                "Select **View schedule & assign** to set the professor, the room, the days and the time of a subject. Changing a recommendation asks for an **Override reason**, and the original recommendation stays in the audit trail.",
            },
            {
              text: "Open the **Approval** tab and select **Submit for Dean and Executive Director Approval**, then **Confirm submission**.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "If the Dean or the Executive Director returns the schedule, the page shows **Returned for correction** with their note and the menu marks Enrollment as **Returned**. Fix what the note asks and submit again. **Schedule approval history** lists every decision.",
        },
        {
          kind: "figure",
          shot: "chair-program-chair-enrollment",
          caption:
            "After approval the page shows Approved and published, with the review activity.",
        },
      ],
    },
    {
      id: "irregular-advising",
      title: "Irregular Advising: approve irregular enrollments",
      blocks: [
        {
          kind: "paragraph",
          text: "A student who picks subjects one by one, or who goes over the regular unit load, needs your approval before the Registrar sees the enrollment.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Enrollment**, then the **Irregular Advising** tab. **Pending Review** lists what needs you.",
            },
            {
              text: "A red **Over the unit maximum** badge marks a student who chose more units than the maximum. Select the **Over the unit maximum** button above the list to see only those.",
              detail:
                "An irregular student may submit more than the maximum after confirming that they are sure. It is not refused: you see it here, set the right units and schedule, and the student accepts your changes.",
            },
            {
              text: "Select **Check Schedule** to see the student's chosen subjects, units and any timetable conflict.",
            },
            {
              text: "Select **Prospectus** to see the student's whole curriculum and grades.",
            },
            {
              text: "Select **Approve** to send the enrollment on to the Registrar.",
            },
            { text: "Or select **Reject** and give the reason." },
          ],
        },
        {
          kind: "figure",
          shot: "chair-irregular-enrollments",
          caption:
            "The submissions queue. The tabs filter by where each enrollment is.",
        },
        {
          kind: "figure",
          shot: "chair-check-schedule",
          caption:
            "The schedule check window shows the student, the load and the chosen subjects.",
        },
        {
          kind: "figure",
          shot: "chair-student-prospectus",
          caption:
            "**Prospectus** shows every subject of the student's curriculum and its grade or status.",
        },
        {
          kind: "note",
          tone: "know",
          title: "If you need to change the subjects",
          text: "In the schedule check window, search a subject or section to add one, or use the bin icon to remove one. The enrollment then goes back to the student, who must accept it before the Registrar sees it.",
        },
        {
          kind: "paragraph",
          text: "When you select **Approve**, a window asks you to confirm.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Write a **Note for the student** if you want to. It is optional.",
            },
            {
              text: "When the enrollment is over the regular unit load, tick **I acknowledge this enrollment exceeds the regular unit load**.",
            },
            {
              text: "Select **Confirm Approval**. It stays off until the acknowledgement is ticked.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "chair-confirm-approve",
          caption: "Approving an overload needs you to acknowledge it.",
        },
        {
          kind: "bullets",
          items: [
            "**Waiting for Student**: you changed the subjects and the student has not answered yet.",
            "**With Registrar**: you approved it and the Registrar is reviewing it.",
          ],
        },
      ],
    },
    {
      id: "dashboard",
      title: "Enrollment Dashboard",
      blocks: [
        { kind: "paragraph", text: "How far your college has come this term." },
        {
          kind: "figure",
          shot: "chair-enrollment-dashboard",
          caption:
            "Counts for the active term, from overall down to each student.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the four groups: **Enrolled**, **Ongoing** (submitted, waiting on approval or payment), **Not yet done**, and **Not enrolled** (rejected, cancelled or withdrawn).",
            },
            {
              text: "Select a group, a section or a student to see who is in it.",
            },
            { text: "Select **Refresh** for the latest counts." },
          ],
        },
      ],
    },
    {
      id: "analytics",
      title: "Enrollment Analytics",
      blocks: [
        {
          kind: "paragraph",
          text: "Descriptive, predictive and prescriptive views built from your college's own enrollment and forecast data.",
        },
        {
          kind: "figure",
          shot: "chair-program-chair-analytics",
          caption:
            "Enrollment Analytics with filters for school year, semester and year level.",
        },
        {
          kind: "bullets",
          items: [
            "Use the filters at the top to choose the **School year**, **Semester**, **Student year level** and **Trend semester**, and drag the school-year range to include more or fewer years.",
            "**Descriptive**: the officially enrolled students of your college, with the workflow badges for those still in progress.",
            "**Drops**, **Withdrawals** and **Course shifts**: the same filters applied to each kind of change.",
            "**Predictive**: the section demand forecast, with the predicted students and sections needed for each subject.",
            "**Prescriptive**: a short list of recommended actions, such as unassigned professors and overload flags.",
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "Forecasts and recommended actions are advice. They never enroll or refuse a student on their own, and you can edit every recommendation.",
        },
      ],
    },
    {
      id: "curriculum-editor",
      title: "Curriculum Editor",
      blocks: [
        {
          kind: "paragraph",
          text: "Build each year level's subject list, units and prerequisites. A curriculum goes to the Dean and the Executive Director for approval before it is used.",
        },
        {
          kind: "figure",
          shot: "chair-subjects-prerequisites",
          caption: "The Curriculum Editor.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "On **Manage**, select **Create new curriculum** to start with a program, then add the curriculum name and its subjects. Or select **Open saved curriculum** to carry on with a draft.",
            },
            {
              text: "Pick the year level and edit its subject placements and prerequisites. Edits to a draft save automatically.",
            },
            {
              text: "Set the **Maximum Student Unit Limit** if the college needs a custom one, then **Save changes**.",
            },
            {
              text: "Select **Submit for Dean Review**, check the summary and select **Confirm & Submit**.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "**Manage** lets you edit a draft. **View** is a read-only preview of a curriculum that is already in use.",
        },
      ],
    },
    {
      id: "schedule",
      title: "Schedule",
      blocks: [
        {
          kind: "paragraph",
          text: "Review and edit the generated sections and their assignments for the selected term.",
        },
        {
          kind: "figure",
          shot: "chair-schedule",
          caption: "The generated schedule and its assignments.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Filter by year level, then open a section with **View schedule & assign**.",
            },
            {
              text: "Choose the professor. The system matches by declared preference, availability, no conflict, then the lower assigned units.",
            },
            {
              text: "Read the warning at the top. It lists lecture and laboratory pairs that are not back-to-back. It never stops you from saving or submitting.",
            },
            {
              text: "Choose the room and time on the room calendar, so the slot is conflict-free.",
            },
            { text: "Select **Save professor only** or save the section." },
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "Once a schedule is published, a change needs a reason and is sent to the Registrar Head as a **Request change**. It does not apply until the Registrar Head approves it.",
        },
      ],
    },
    {
      id: "faculty-loading",
      title: "Faculty Loading",
      blocks: [
        {
          kind: "paragraph",
          text: "Set how many units a professor may carry and read the load report.",
        },
        {
          kind: "figure",
          shot: "chair-faculty-loading",
          caption: "Maximum teaching load and the Faculty Load Report.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Under **Maximum teaching load**, set the default for full-time and part-time professors and select **Save default maximum units**.",
            },
            {
              text: "Use the filters to narrow the **Faculty Load Report** by subject, professor or both.",
            },
            {
              text: "For one professor who needs a different limit, select **Set max load**. **Remove own max load** returns them to the college default.",
            },
            {
              text: "Check **Flags to review** for unassigned subjects or overloads.",
            },
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
          text: "Your college's professors, their status and the subjects each may teach.",
        },
        {
          kind: "figure",
          shot: "chair-faculty-workforce",
          caption: "The faculty roster.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Search a professor by name, and filter by account status or employment type.",
            },
            {
              text: "Select a professor to review their profile, teaching load and timetable.",
            },
            {
              text: "Change their status or employment type, record the reason for the change, and select **Save workforce profile**.",
            },
            { text: "Approve which subjects they may teach." },
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
          text: "Check room availability before assigning a section.",
        },
        {
          kind: "figure",
          shot: "chair-rooms",
          caption: "Room availability and the sections still awaiting a room.",
        },
        {
          kind: "bullets",
          items: [
            "**Find a room**: pick a school year, semester and room to see its whole week and every college's booking in it.",
            "**Awaiting a room**: sections that have no room yet. Select **Assign a room** to place one.",
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
          text: "Students who come from another school send a list of the subjects they took. You decide which GRC subject each one counts as, and the Registrar Head approves it.",
        },
        {
          kind: "figure",
          shot: "chair-credit-mappings",
          caption:
            "Students waiting for a credit review, and the credits you have already sent.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Enrollment**, then the **Credit Mappings** tab. **Students waiting for review** lists the students of your college.",
            },
            { text: "Select **Review** for a student." },
            {
              text: "Under **Map credits for** the student, take each previous subject in turn and search the GRC subject it counts as, by code or title. If the system found a best match, it is already selected for you.",
            },
            { text: "Select **Confirm**. Nothing is saved until you do." },
            {
              text: "If the subject cannot be credited, select **No equivalent** and write why. The student is told the reason.",
            },
            {
              text: "When every subject is confirmed, select **Submit to Registrar Head**. The student then appears under **Waiting for the Registrar Head**.",
            },
          ],
        },
        {
          kind: "note",
          tone: "tip",
          text: "**Confirm all suggested** confirms every suggested match in one step. It stays off when the system has no suggestion for the student's subjects.",
        },
        {
          kind: "paragraph",
          text: "A Transferee or Returnee who has nothing to credit does not send a list. They select **I have no subject to credit**, and the student appears under **No subject to credit** on the same tab.",
        },
        {
          kind: "bullets",
          items: [
            "Select **Confirm** beside the student. They can then go on to enroll; the Registrar Head has nothing to approve, because no credit was claimed.",
            "A student stays held back from enrolling until you confirm it. A Transferee is also given their queue number when you confirm.",
          ],
        },
        {
          kind: "figure",
          shot: "chair-credit-mapping-panel",
          caption:
            "Steps 3 to 5: the side-by-side mapping, one row for each previous subject.",
        },
        {
          kind: "figure",
          shot: "chair-credit-mapping-submit",
          caption: "Step 6: submit once every subject is confirmed.",
        },
        {
          kind: "note",
          tone: "tip",
          text: "For a student who did not ask online, use **Record a credit for a student**: find the student, enter the previous school, subject and units, and select **Record credit**.",
        },
        {
          kind: "paragraph",
          text: "Under **Transcripts of Records** you can read the TOR a student uploaded. Read it, then record the subjects the student took elsewhere so you can map them.",
        },
      ],
    },
    {
      id: "invite-professors",
      title: "Invite Professors",
      blocks: [
        {
          kind: "paragraph",
          text: "Invite a professor by email. They set their own name and password with a one-time code.",
        },
        {
          kind: "figure",
          shot: "chair-faculty-invitations",
          caption: "Invite professors and follow their status.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Under **Invite by email**, add one or more email addresses and send.",
            },
            {
              text: "Follow each invitation in **Your faculty roster**: **Invited**, active, or **Delivery failed**.",
            },
            { text: "Select **Resend** if the code expired or never arrived." },
          ],
        },
      ],
    },
  ],
}
