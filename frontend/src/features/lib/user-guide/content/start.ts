import type { GuideChapter } from "../types"

export const startChapter: GuideChapter = {
  id: "start",
  title: "Getting started",
  tagline: "Sign in, find your way around, and read the words the system uses",
  intro: [
    "GRC Connect is where Global Reciprocal Colleges runs enrollment: schedules, subject selection, approvals, payment and records. Everyone signs in at the same page and sees only the pages that belong to their role.",
    "This chapter applies to every role. After it, open the chapter for your own role to see its pages and how to finish each task.",
  ],
  sections: [
    {
      id: "how-it-works",
      title: "How enrollment works, start to finish",
      blocks: [
        {
          kind: "paragraph",
          text: "Enrollment passes through several offices, in a fixed order. Knowing who has the next step tells you why a request is waiting and who to ask about it.",
        },
        {
          kind: "journey",
          items: [
            {
              who: "Registrar Head",
              what: "Creates the school year and semester that starts the enrollment cycle.",
            },
            {
              who: "Program Head",
              what: "Plans the sections, rooms, capacities and teachers for each subject, then sends the schedule for approval.",
            },
            {
              who: "Dean, then Executive Director",
              what: "The Dean approves the plan or returns it with notes. The Executive Director gives the final approval and publishes it.",
            },
            {
              who: "Student",
              what: "Chooses a section for each subject and submits the enrollment.",
            },
            {
              who: "Program Head, for irregular enrollments",
              what: "Approves or rejects an irregular or overloaded enrollment of their own college before it reaches the Registrar.",
            },
            {
              who: "Registrar Staff or Registrar Head",
              what: "Approves the submitted enrollment so the student can go to payment.",
            },
            {
              who: "Accounting Staff (the Cashier)",
              what: "Calls the student's queue number and confirms the payment. The enrollment is then complete and the Certificate of Registration is ready.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "New freshmen and transferees pay first. Admission gives them a queue number, the Cashier confirms their first payment, and only then do they receive the email to set up their account.",
        },
      ],
    },
    {
      id: "signing-in",
      title: "Signing in",
      blocks: [
        {
          kind: "paragraph",
          text: "Open the sign-in page from the **Sign in to portal** button on the home page. Use the email address your office registered for you.",
        },
        {
          kind: "steps",
          items: [
            { text: "Type your **Email address**." },
            { text: "Type your **Password**." },
            {
              text: "Select **Sign in**.",
              detail:
                "You can also use Sign in with Google, if a GRC account exists for that Google email.",
            },
            {
              text: "If asked, enter the 6-digit code sent to your email and select **Verify and sign in**.",
              detail:
                "The sign-in page then says Check your email. The code works for a short time and only a few wrong attempts are allowed. Select **Resend code** if it does not arrive.",
            },
          ],
        },
        { kind: "figure", shot: "login", caption: "The sign-in page." },
        {
          kind: "figure",
          shot: "login-code",
          caption:
            "Step 4: the code step, shown when the system needs to confirm it is you.",
        },
        {
          kind: "note",
          tone: "tip",
          text: "You may not be asked for a code if you signed in a little while ago. Sign out when you use a shared computer.",
        },
      ],
    },
    {
      id: "first-time",
      title: "Setting up your account for the first time",
      blocks: [
        {
          kind: "paragraph",
          text: "You do not choose your own password at first. Your office sends you an email with a 6-digit setup code. Students receive it from Admission. Professors are invited by their Program Head, and staff by the Registrar Head.",
        },
        {
          kind: "figure",
          shot: "account-setup",
          caption: "The account setup page.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Open the link in the email, or go to the account setup page.",
            },
            { text: "Enter your email and the 6-digit **setup code**." },
            {
              text: "Create your password, then type it again to confirm it.",
              detail:
                "Use at least 8 characters with a lowercase letter, an uppercase letter, a number and a symbol.",
            },
            {
              text: "Select **Create password and activate**, then sign in with your new password.",
            },
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "The setup code can be used once and expires after 24 hours. A student whose code expired can select **Resend setup email** on the same page. Professors and staff ask the office that invited them to send a new one.",
        },
      ],
    },
    {
      id: "forgot-password",
      title: "If you forget your password",
      blocks: [
        {
          kind: "figure",
          shot: "forgot-password",
          caption:
            "Request a reset code from the sign-in page with Forgot password?",
        },
        {
          kind: "steps",
          items: [
            { text: "On the sign-in page, select **Forgot password?**." },
            {
              text: "Enter your email address and select **Send reset code**.",
            },
            {
              text: "Open the email, then enter the 6-digit code and your new password.",
              detail: "The reset code works once and expires after an hour.",
            },
            { text: "Sign in with the new password." },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "Resetting your password signs you out everywhere. There is no change-password page inside the portal, so use this same route any time you want a new password.",
        },
      ],
    },
    {
      id: "find-your-way",
      title: "Finding your way around the portal",
      blocks: [
        {
          kind: "paragraph",
          text: "After you sign in you land on **GRC Connect**, your home page. The menu on the left lists only the pages your role can use. The top bar holds your notifications and **Sign out**.",
        },
        {
          kind: "figure",
          shot: "portal-home",
          caption:
            "The portal home page for a student. Other roles see their own pages in the same layout.",
        },
        {
          kind: "key",
          items: [
            "**Menu**: the pages for your role. Select one to open it.",
            "**Current term**: the school year and semester everything on screen refers to.",
            "**Notifications**: the bell at the top. A red number means you have unread messages.",
            "**Sign out**: always sign out on a shared computer.",
          ],
        },
        {
          kind: "bullets",
          items: [
            "The small square next to the **GRC Connect** label collapses the menu to give the page more room.",
            "**User guide** in the top bar opens this guide at the chapter for your role. On a phone it is at the bottom of the menu.",
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "If you see No active academic term, the Registrar Head has not opened a school year and semester yet. Most enrollment pages stay empty until then.",
        },
      ],
    },
    {
      id: "notifications",
      title: "Notifications",
      blocks: [
        {
          kind: "paragraph",
          text: "The system tells you when something needs you or has changed: a schedule is published, an enrollment is approved, a request is returned. Select the bell to read them.",
        },
        {
          kind: "figure",
          shot: "portal-notifications",
          caption: "The notifications panel.",
        },
        {
          kind: "bullets",
          items: [
            "Select a message to open it and mark it as read.",
            "Select **Mark all as read** to clear the red number.",
            "Select **Unread only** to hide messages you have already read.",
          ],
        },
      ],
    },
    {
      id: "on-your-phone",
      title: "Using GRC Connect on your phone",
      blocks: [
        {
          kind: "paragraph",
          text: "Every page works on a phone. The menu moves behind the button at the top left, and tables turn into stacked cards so you never have to scroll sideways.",
        },
        {
          kind: "phones",
          shots: ["phone-login", "phone-menu", "phone-enrollment"],
          caption: "Sign in, open the menu, and use a page on a phone.",
        },
      ],
    },
    {
      id: "words",
      title: "Words you will see",
      blocks: [
        {
          kind: "terms",
          items: [
            {
              term: "Academic term",
              meaning:
                "One school year and semester, for example 2026-2027 1st semester.",
            },
            {
              term: "Section",
              meaning:
                "One class of a subject, with its own days, time, room and teacher.",
            },
            {
              term: "Block",
              meaning:
                "The set of sections a regular student takes together in one semester.",
            },
            {
              term: "Regular student",
              meaning:
                "A student who follows the full block for their year level and semester.",
            },
            {
              term: "Irregular student",
              meaning:
                "A student who chooses subjects one by one, for example after a back subject or a credited subject. Their enrollment also goes to the Program Head for approval.",
            },
            {
              term: "Prospectus",
              meaning:
                "Every subject in a student's curriculum, with the grade earned so far.",
            },
            {
              term: "Units",
              meaning:
                "The credit value of a subject. Tuition is based on the units enrolled.",
            },
            {
              term: "Assessment",
              meaning:
                "The amount a student must pay for the term, built from tuition and fees.",
            },
            {
              term: "Queue number",
              meaning:
                "The ticket that tells the Cashier when it is your turn to pay.",
            },
            {
              term: "Certificate of Registration (COR)",
              meaning:
                "The document that proves you are enrolled. It is available once the Cashier confirms payment.",
            },
          ],
        },
      ],
    },
    {
      id: "troubleshooting",
      title: "When something goes wrong",
      blocks: [
        {
          kind: "terms",
          items: [
            {
              term: "The email or password you entered was not recognized",
              meaning:
                "Check the email and password, then try again. If you are not sure of the password, use **Forgot password?**.",
            },
            {
              term: "Too many attempts",
              meaning: "Wait a few minutes before you try again.",
            },
            {
              term: "The code did not arrive",
              meaning: "Check your spam folder, then select **Resend code**.",
            },
            {
              term: "This verification code is invalid or expired",
              meaning: "Select **Resend code** and use the newest email.",
            },
            {
              term: "No GRC account was found for this Google email",
              meaning:
                "Contact Admission or the Registrar's Office to have your account created.",
            },
            {
              term: "Your session cannot be restored after refresh on this browser",
              meaning:
                "Your browser is blocking site storage. Allow it for this site, or sign in again after each refresh.",
            },
            {
              term: "A page is empty",
              meaning:
                "Check the current term at the bottom of the menu. Most pages wait for the Registrar Head to open the school year and semester.",
            },
          ],
        },
        {
          kind: "note",
          tone: "tip",
          text: "If none of this helps, contact the office that handles your request: Admission for accounts, the Program Head for schedules, the Registrar for approvals and records, Accounting for payments.",
        },
      ],
    },
  ],
}
