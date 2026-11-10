import type { GuideChapter } from "../types"

export const deanChapter: GuideChapter = {
  id: "dean",
  title: "Dean",
  tagline: "Approve your college's plan and watch its enrollment",
  intro: [
    "The Dean reviews the enrollment plan and the curricula that the Program Heads of the college submit, approves them or returns them with notes, and follows enrollment, faculty load and honors for the college.",
    "Your menu has five pages: Enrollment, Curriculum Approvals, Enrollment Dashboard, Faculty Load and Honors.",
  ],
  sections: [
    {
      id: "enrollment",
      title: "Enrollment: review the planning",
      blocks: [
        {
          kind: "paragraph",
          text: "The Program Head submits a schedule for each college. It reaches you first. After you approve it, it goes to the Executive Director, who publishes it. Open **Enrollment**: **Pending decisions** lists the plans that wait for you, with who submitted each.",
        },
        {
          kind: "steps",
          items: [
            { text: "Select **Review schedule** to read the plan." },
            { text: "Select **Approve schedule** when the plan is ready." },
            {
              text: "Or select **Return with notes** if something must change.",
            },
            {
              text: "Check the confirmation, then select **Confirm decision**.",
            },
          ],
        },
        {
          kind: "figure",
          shot: "dean-schedule-approvals",
          caption: "Steps 1 to 3: the plans that wait for your decision.",
        },
        {
          kind: "figure",
          shot: "dean-review-schedule",
          caption:
            "The review window lists each block section with its subjects and units. **View schedule** opens a block's days, times, rooms and professors.",
        },
        {
          kind: "figure",
          shot: "dean-confirm-decision",
          caption:
            "Step 4: every decision is confirmed and recorded in the audit log.",
        },
        {
          kind: "note",
          tone: "careful",
          text: "When you return a plan, write notes the Program Head can act on. The plan goes back to them as a draft with your note, and they submit it again after fixing it.",
        },
        {
          kind: "note",
          tone: "know",
          text: "**Decision history** lists every plan you approved or returned, with the date and your notes.",
        },
      ],
    },
    {
      id: "curriculum-approvals",
      title: "Curriculum Approvals",
      blocks: [
        {
          kind: "paragraph",
          text: "Curricula that a Program Head has finished and submitted wait here for your decision.",
        },
        {
          kind: "figure",
          shot: "dean-curriculum-approvals",
          caption: "Curriculum Approvals.",
        },
        {
          kind: "steps",
          items: [
            { text: "Select **Review** to read the curriculum." },
            { text: "Select **Approve**, or **Return with notes**." },
            {
              text: "When you return one, write the **Notes for Program Head**. The curriculum goes back as a Draft, and a reason is required.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "When there is nothing to review the page says that no curricula are pending your review.",
        },
      ],
    },
    {
      id: "dashboard",
      title: "Enrollment Dashboard",
      blocks: [
        {
          kind: "paragraph",
          text: "Your college's enrollment for the current term.",
        },
        {
          kind: "figure",
          shot: "dean-enrollment-dashboard",
          caption: "The Enrollment Dashboard for your college.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the number of students counted and the four groups: **Enrolled**, **Ongoing**, **Not yet done** and **Not enrolled**.",
            },
            {
              text: "Select a group, a section or a student to see who is in it.",
            },
            { text: "Select **Refresh** for the latest counts." },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "**Ongoing** counts the students who have submitted and are waiting on an approval or on payment. Select it to see who they are.",
        },
      ],
    },
    {
      id: "faculty-load",
      title: "Faculty Load",
      blocks: [
        {
          kind: "paragraph",
          text: "How many units each professor of your college carries against their maximum.",
        },
        {
          kind: "figure",
          shot: "dean-faculty-load-monitoring",
          caption: "Faculty load for the college.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the counts: **Professors**, **Over limit**, **No limit set** and **Sections without a professor**.",
            },
            {
              text: "Use the load status filter to find who is over their limit.",
            },
            {
              text: "For one professor, select **Set max load** to raise or lower their own limit, or **Remove own max load** to return to the college default.",
            },
            {
              text: "For a section without a professor, select **Assign professor**, or use **Change professor** to change who teaches a section.",
            },
          ],
        },
      ],
    },
    {
      id: "honors",
      title: "Honors",
      blocks: [
        {
          kind: "paragraph",
          text: "The live Dean's List. It appears after professors submit complete grades for the term.",
        },
        {
          kind: "figure",
          shot: "dean-honors",
          caption: "The Dean's List for a term.",
        },
        {
          kind: "steps",
          items: [
            { text: "Pick the **Academic term**." },
            { text: "Filter by college, program or year." },
            {
              text: "Read the students who qualify, with their year and units. Use **Refresh** to check the latest submitted grades.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "A student qualifies only after every enrolled subject has a submitted or locked grade. The page states the rule: a general weighted average from 1.00 to 1.50 with a minimum of 16 units. NSTP, PATHFIT and PE do not affect it. Until grades are in, the page says that no students qualify yet.",
        },
      ],
    },
  ],
}
