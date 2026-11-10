import type { GuideChapter } from "../types"

export const studentChapter: GuideChapter = {
  id: "student",
  title: "Student",
  tagline: "Choose your section, pay, and keep your records",
  intro: [
    "As a student you use GRC Connect to enroll each semester, follow the approval and payment of your enrollment, see your class schedule, read your grades and print your Certificate of Registration.",
    "Your menu has seven pages: Enrollment, Schedule, Statement of Account, Grades, Certificate of Registration, Student Information and Admission.",
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
              what: "Open **Enrollment** when it opens for your year level, choose your section and submit.",
            },
            {
              who: "Registrar",
              what: "Approves your enrollment. If you are an irregular student, your Program Head approves it first.",
            },
            {
              who: "You",
              what: "Take your queue number at the Cashier and wait for your turn.",
            },
            {
              who: "Cashier",
              what: "Confirms your payment. You are now enrolled.",
            },
            {
              who: "You",
              what: "Open **Certificate of Registration** and **Schedule** to print or save them.",
            },
          ],
        },
        {
          kind: "note",
          tone: "tip",
          text: "The row of five steps at the top of Enrollment always shows where you are: Select section, Submitted, Registrar approved, Payment confirmed, Enrolled.",
        },
      ],
    },
    {
      id: "enrollment",
      title: "Enrollment: choose your section and submit",
      blocks: [
        {
          kind: "paragraph",
          text: "Enrollment opens for your year level on a date set by the Registrar, and closes on a deadline. The page tells you both. If the page says enrollment is not open yet, check back on the opening date.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the **Enrollment is open** message.",
              detail: "It names your year level and the closing date and time.",
            },
            {
              text: "Look at the section offered to you and select **Choose** next to it.",
              detail:
                "A regular student takes one section for the whole block. The page shows the seats left and the total units.",
            },
            {
              text: "Check every subject, day, time and room in the window that opens.",
              detail:
                "Select **View in calendar** to see the week as a timetable.",
            },
            { text: "Select **Submit enrollment**." },
            {
              text: "Select **Confirm submission** to send it to the Registrar.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "student-enrollment-open",
          caption:
            "Steps 1 and 2: the Enrollment page while enrollment is open for your year level.",
        },
        {
          kind: "figure",
          shot: "student-section-dialog",
          caption:
            "Steps 3 and 4: the section window lists every subject of the block.",
        },
        {
          kind: "figure",
          shot: "student-confirm-submit",
          caption: "Step 5: confirm to send the enrollment to the Registrar.",
        },
        {
          kind: "note",
          tone: "know",
          text: "Professors may show as Announced after enrollment until your enrollment is complete.",
        },
      ],
    },
    {
      id: "after-submitting",
      title: "After you submit",
      blocks: [
        {
          kind: "figure",
          shot: "student-enrollment-submitted",
          caption: "After submitting, the enrollment waits for the Registrar.",
        },
        {
          kind: "bullets",
          items: [
            "**Pending Registrar Approval**: the Registrar is reviewing your enrollment. You will get a notification when it is approved or returned.",
            "**Cancel enrollment**: until the Registrar approves, you can cancel from the card named Picked the wrong section?. Your seats are released and you can choose again.",
            "**Cashier queue**: once the Registrar approves, the page shows your queue number and who is being served. Keep this page open and visible near your turn.",
            "**Payment confirmed**: the Cashier records your payment and the status becomes Enrolled.",
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "Only the Cashier can confirm payment. Submitting an enrollment does not enroll you until the Registrar approves it and the Cashier confirms your payment.",
        },
      ],
    },
    {
      id: "irregular",
      title: "If you are an irregular student",
      blocks: [
        {
          kind: "paragraph",
          text: "You are an irregular student when a subject from a semester you have already finished still needs to be resolved (failed, incomplete, dropped or never taken), when a subject of your block is credited from another school or curriculum, or when a prerequisite is not met yet. You then pick a section for each subject instead of taking a block. The page tells you each semester whether you are regular or irregular, and why.",
        },
        {
          kind: "steps",
          items: [
            { text: "Read **You're Irregular this semester** to see why." },
            {
              text: "Pick a **Schedule preset** to fill the table for you, or choose each section yourself.",
              detail:
                "**Concise (1-2 Days)**, **Morning** and **Afternoon / Evening** pick sections that fit that pattern without conflicts. **Manual** leaves every subject for you. Select **Reset to Manual** to start over.",
            },
            {
              text: "Use **Add subject** for a subject that is not shown, and **Remove** for one you do not want.",
            },
            {
              text: "Watch the unit counter at the top of the table.",
              detail:
                "It shows your units against the regular load and the maximum. An overload needs the Program Head to acknowledge it. If you choose more than the maximum, you can still submit: you are asked whether you are sure, and your Program Head then checks it and may change your subjects and schedule to a load that fits.",
            },
            {
              text: "Select **Submit enrollment**, then **Confirm submission**.",
            },
            {
              text: "Your Program Head reviews it first.",
              detail:
                "The tracker has one more step for you: **Program Head approved**.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "student-irregular-subjects",
          caption:
            "Steps 1 to 3: an irregular student's page lists the eligible subjects, each with its own section.",
        },
        {
          kind: "figure",
          shot: "student-irregular-arranged",
          caption:
            "Steps 2 and 4: a preset has chosen a section for every subject.",
        },
        {
          kind: "figure",
          shot: "student-irregular-submit",
          caption: "Step 5: submit when every subject has a section.",
        },
        {
          kind: "note",
          tone: "know",
          title: "If the Program Head changes your subjects",
          text: "The enrollment comes back to you with the Program Head's note. Accept it to send it on to the Registrar. If you decline, give a reason and it returns to the Program Head.",
        },
      ],
    },
    {
      id: "changes-after-enrolling",
      title: "Changing your subjects after you are enrolled",
      blocks: [
        {
          kind: "paragraph",
          text: "Once you are enrolled, the Enrollment page shows your enrolled class schedule, the Add/Drop requests card and a list of your enrollments with your queue ticket. Every request you make goes to the Registrar for a decision.",
        },
        {
          kind: "figure",
          shot: "student-enrollment",
          caption: "The Enrollment page after you are enrolled.",
        },
        {
          kind: "bullets",
          items: [
            "**Add or drop a subject**: open the **Add/Drop requests** card, choose the subject, give a reason and send the request. The card says when it opens: the add/drop window opens once enrollment closes for the term, and it closes at the add/drop deadline.",
            "**Withdraw from the term**: use **Withdraw from this term**. You are asked to confirm and the Registrar decides the request.",
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "A request is not final until you see the Registrar's decision in Your add/drop requests or Your withdrawal requests, and in your notifications.",
        },
      ],
    },
    {
      id: "schedule",
      title: "Schedule",
      blocks: [
        {
          kind: "paragraph",
          text: "Your weekly timetable, with the room and professor for each class.",
        },
        {
          kind: "figure",
          shot: "student-schedule",
          caption: "Your schedule, by day.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Schedule** and pick the school year and semester if you want an earlier one.",
            },
            {
              text: "Use **Calendar view** for the week by day, or **Table view** for a list.",
            },
            {
              text: "The summary cards show your section, total units, number of subjects and your status.",
            },
          ],
        },
      ],
    },
    {
      id: "statement-of-account",
      title: "Statement of Account",
      blocks: [
        {
          kind: "paragraph",
          text: "What was assessed, what you paid and what you still owe, term by term.",
        },
        {
          kind: "figure",
          shot: "student-statement-of-account",
          caption: "Your Statement of Account.",
        },
        {
          kind: "steps",
          items: [
            { text: "Pick a term, or leave **All terms**." },
            {
              text: "Read the outstanding balance, the amount assessed and the amount paid.",
            },
            { text: "Select **Download PDF** to keep a copy." },
          ],
        },
      ],
    },
    {
      id: "grades",
      title: "Grades and prospectus",
      blocks: [
        {
          kind: "paragraph",
          text: "Your recorded grades by school year and semester, and your whole curriculum in the prospectus.",
        },
        {
          kind: "figure",
          shot: "student-grades",
          caption: "Choose a semester to open its grade slip.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Select a semester under a school year to open its grade slip.",
            },
            {
              text: "Select **Prospectus** to see every subject in your curriculum and your grade for each.",
            },
            {
              text: "Switch between **Table** and **Tiles** to read it the way you prefer.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "student-prospectus",
          caption: "The prospectus lists every subject of your curriculum.",
        },
        {
          kind: "note",
          tone: "know",
          text: "A grade appears here once the professor has submitted it and the Registrar has locked it.",
        },
        {
          kind: "paragraph",
          text: "If you took subjects at another school, select **Request Credit Mapping** and list them. Your list goes to your Program Head, who maps each subject to your curriculum. The Registrar Head then approves it. The Prospectus marks credits that still wait for approval. If you are a Transferee or a Returnee with nothing to credit, select **I have no subject to credit** in the same window instead: your Program Head reviews it, and you can go on to enroll once they confirm it.",
        },
        {
          kind: "figure",
          shot: "student-credit-mapping-form",
          caption:
            "List every subject you took at another school, one at a time.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Select **Upload TOR** and add a clear copy of your Transcript of Records (PDF, JPG or PNG).",
            },
            {
              text: "Fill in the school, subject, units, school year, semester and grade of one subject.",
            },
            {
              text: "Select **Add Subject to My List**. Repeat steps 2 and 3 for each subject.",
            },
            {
              text: "Select **Submit to Program Head** when the list is complete.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "student-credit-mapping-list",
          caption:
            "Your list, ready to send. Once it is sent, the Program Head maps each subject.",
        },
      ],
    },
    {
      id: "cor",
      title: "Certificate of Registration",
      blocks: [
        {
          kind: "paragraph",
          text: "Your official proof of enrollment. It is created when the Cashier confirms your payment.",
        },
        {
          kind: "figure",
          shot: "student-digital-com",
          caption: "The Certificate of Registration page.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open **Certificate of Registration** after your payment is confirmed.",
            },
            {
              text: "Print it or save it as a PDF with your browser's print option.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "Until payment is confirmed the page says that no Certificate of Registration has been generated yet. Your bill and balance are in the Statement of Account.",
        },
      ],
    },
    {
      id: "student-information",
      title: "Student Information",
      blocks: [
        {
          kind: "paragraph",
          text: "Your official identity and academic profile, and the way to ask for a correction.",
        },
        {
          kind: "figure",
          shot: "student-student-information",
          caption:
            "Your official information, with the form to request a change below it.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Under **Request an information change**, type the correction. Only your name, email and complete address may be requested.",
            },
            { text: "Explain what needs correction in **Reason for change**." },
            { text: "Select **Submit change request**." },
            {
              text: "Take your supporting documents to the Admission Office. Your information changes only after Admission verifies and approves the request.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "student-change-request-form",
          caption:
            "Ask for a correction, give the reason, then bring your documents to Admission.",
        },
        {
          kind: "note",
          tone: "know",
          text: "While a request is waiting you can revise or cancel it, and **Request history** shows every decision with the Admission Office's notes.",
        },
      ],
    },
    {
      id: "admission",
      title: "Admission",
      blocks: [
        {
          kind: "paragraph",
          text: "Your admission status and the documents Admission has recorded as submitted.",
        },
        {
          kind: "figure",
          shot: "student-admission-requirements",
          caption: "Your admission requirements checklist.",
        },
        {
          kind: "bullets",
          items: [
            "A filled circle means Admission has received the document. An empty one is still missing.",
            "The bar and the count show how many requirements are submitted.",
            "Bring anything still missing to the Admission Office.",
          ],
        },
      ],
    },
  ],
}
