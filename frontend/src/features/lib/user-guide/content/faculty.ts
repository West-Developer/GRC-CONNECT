import type { GuideChapter } from "../types"

export const facultyChapter: GuideChapter = {
  id: "faculty",
  title: "Professor / Faculty",
  tagline: "Share your availability, read your classes and submit grades",
  intro: [
    "Professors tell the Program Head when they can teach and which subjects they prefer, read the classes assigned to them, and enter and submit the final grades of their own sections. You cannot see student payments or Registrar overrides.",
    "Your menu has four pages: Availability Preferences, Teaching Schedule, Grade Submission and My Information.",
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
              who: "You",
              what: "Keep your weekly availability and ranked subject preferences up to date before the schedule is planned.",
            },
            {
              who: "Program Head",
              what: "Builds the schedule with your input and assigns you to sections.",
            },
            {
              who: "Dean and Executive Director",
              what: "Approve and publish the schedule. You are notified.",
            },
            {
              who: "You",
              what: "Read your assigned classes under **Teaching Schedule**.",
            },
            {
              who: "You",
              what: "At the end of the semester, enter each student's grade and submit the section under **Grade Submission**.",
            },
            {
              who: "Registrar Head",
              what: "Locks the submitted grades so they become part of the official record.",
            },
          ],
        },
      ],
    },
    {
      id: "availability",
      title: "Availability and subject preferences",
      blocks: [
        {
          kind: "paragraph",
          text: "The Program Head uses your availability and preferences as inputs when building the schedule. Your availability recurs every term until you change it.",
        },
        {
          kind: "figure",
          shot: "faculty-availability-preferences",
          caption:
            "Availability window, with your saved weekly windows below it.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Availability Preferences** and stay on the **Availability window** tab.",
            },
            {
              text: "Choose the **Day**, then the **Start time** and **End time** you can teach.",
            },
            {
              text: "Select **Save availability**. The window appears in the list below.",
            },
            {
              text: "Use **Edit** to change a window, or **Remove** to delete it.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "faculty-subject-preferences",
          caption: "Rank the subjects you are prepared to teach.",
        },
        {
          kind: "steps",
          items: [
            { text: "Open the **Subject preferences** tab." },
            {
              text: "Choose a curriculum and semester, then search the **Preferred subject** by code or title.",
            },
            {
              text: "Set your **Proficiency** and save. Your first choices are your **Primary** subjects, the others are **Secondary**.",
            },
            {
              text: "A lecture and its laboratory are saved together as a pair.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "The **Teaching history** tab is read-only. It shows evidence from earlier schedules that the system uses only as a tie-breaker after your own preferences.",
        },
      ],
    },
    {
      id: "teaching-schedule",
      title: "Teaching schedule",
      blocks: [
        {
          kind: "paragraph",
          text: "The sections assigned to you, with the days, time, room and number of enrolled students.",
        },
        {
          kind: "figure",
          shot: "faculty-teaching-schedule",
          caption: "Your teaching schedule.",
        },
        {
          kind: "steps",
          items: [
            { text: "Open **Teaching Schedule**." },
            {
              text: "Use **Table view** for a list or **Calendar view** for the weekly timetable from Monday to Saturday.",
            },
            { text: "Filter by an earlier term or by room to find a class." },
            {
              text: "Select a class block in the calendar to see its room and section details.",
            },
          ],
        },
      ],
    },
    {
      id: "grade-submission",
      title: "Grade submission",
      blocks: [
        {
          kind: "paragraph",
          text: "Enter the final grades of every student in each section assigned to you, save them as a draft while you work, and submit the section to the Registrar when the sheet is complete.",
        },
        {
          kind: "figure",
          shot: "faculty-grade-submission",
          caption: "Your assigned classes for the active semester.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Grade Submission**. The active semester and your assigned classes appear, each with how many students are enrolled and how many grades are recorded.",
            },
            { text: "Select a class to open its **Section Grade Sheet**." },
          ],
        },
        {
          kind: "paragraph",
          text: "On the grade sheet, enter a **Grade** for each student, with **Remarks** if needed, and save the sheet as a draft as often as you like.",
        },
        {
          kind: "figure",
          shot: "faculty-grade-sheet",
          caption:
            "The Section Grade Sheet lists every enrolled student of the section.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "To enter many grades at once, select **Download CSV Template** and fill it in.",
            },
            {
              text: "Select **Upload Grades (Excel or CSV)** to load the file. Grades that match a student number are filled in for you.",
            },
            {
              text: "When every enrolled student has a valid grade, select **Submit final grades**, then **Submit section**.",
              detail: "Select **Review again** if you want to check first.",
            },
          ],
        },
        {
          kind: "bullets",
          items: [
            "**Awaiting Registrar**: you submitted the grades and they are read-only until the Registrar Head locks them.",
            "**Official grades locked**: the grades are part of the official record.",
            "**INC · Awaiting completion**: an Incomplete grade stays editable. Enter the completion grade and select **Save completion grade**.",
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "You can edit and submit only the sections assigned to you. After grades are locked, a correction needs an audited request to the Registrar.",
        },
        {
          kind: "note",
          tone: "know",
          text: "Grade submission is the last step of the semester. In the PE, PATHFIT and NSTP subjects a grade is still required but does not count toward the weighted average.",
        },
      ],
    },
    {
      id: "my-information",
      title: "My Information",
      blocks: [
        {
          kind: "paragraph",
          text: "Your official faculty profile as recorded in the system.",
        },
        {
          kind: "figure",
          shot: "faculty-professor-information",
          caption: "Your faculty profile.",
        },
        {
          kind: "bullets",
          items: [
            "**Identity**: your full name, college, status and employment type.",
            "**Assignability**: whether you can be assigned to sections this term. If you are marked as not assignable, contact your Program Head to update your workforce status.",
            "If the page says your faculty record was not found, contact your Program Head.",
          ],
        },
      ],
    },
  ],
}
