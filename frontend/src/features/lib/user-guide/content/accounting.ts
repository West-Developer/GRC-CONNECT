import type { GuideChapter } from "../types"

export const accountingChapter: GuideChapter = {
  id: "accounting_staff",
  title: "Accounting Staff",
  tagline: "Serve the payment queue and keep each student's account",
  intro: [
    "Accounting Staff act as the Cashier. You call students from the queue, confirm the payment they hand over, and the system completes their enrollment and creates the Certificate of Registration. You also record advance payments, read statements of account and maintain the fee schedule. You cannot change academic records or the Registrar's decisions.",
    "Your menu has eight pages: Payment Queue, Transaction History, Advance Payment, Statement of Account, COR Records, Queue Kiosk Access, Enrollment Dashboard and Fee Settings.",
  ],
  sections: [
    {
      id: "payment-queue",
      title: "Payment Queue",
      blocks: [
        {
          kind: "paragraph",
          text: "Students whose enrollment the Registrar approved take a queue number at the Cashier kiosk. Freshmen and transferees receive theirs by email before they pay. You see every ticket here in the order it was issued.",
        },
        {
          kind: "figure",
          shot: "accounting-payment-queue",
          caption:
            "The payment queue: status, now serving, waiting and served today.",
        },
        {
          kind: "bullets",
          items: [
            "**Queue status** says whether the queue is open. **Cut off for today** stops new tickets; waiting tickets are saved and the queue resumes on the next service day. **Resume queue** reopens it.",
            "**Now serving** shows the student at your counter and how many are waiting and served today.",
            "**Waiting** lists each ticket with the student number, the amount due and **Mark priority**.",
            "**Served today** lists the students you have already served.",
          ],
        },
        {
          kind: "paragraph",
          text: "A student who forgot to take a number can be helped from the same page.",
        },
        {
          kind: "figure",
          shot: "accounting-find-student",
          caption: "Find a student and issue a queue ticket on their behalf.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Type a student number, name or email under **Find student**.",
            },
            { text: "Select **Find student**." },
            {
              text: "Select **Issue queue ticket**. If the student already has a ticket, select **Serve selected student** instead.",
            },
          ],
        },
        {
          kind: "paragraph",
          text: "To serve the next student:",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Select **Call next**. The next ticket moves to **Now serving**.",
            },
            { text: "Select **Announce ticket** to call the number again." },
            {
              text: "Select **Skip** to put a student who is not there back in the queue.",
            },
            {
              text: "Read the **Assessment Particulars**, the prior balance, the total outstanding and any advance credit.",
            },
            {
              text: "Select **Preview COR** to see the Certificate of Registration before you confirm.",
            },
            { text: "Select **Confirm payment** once the student has paid." },
          ],
        },
        {
          kind: "figure",
          shot: "accounting-now-serving",
          caption:
            "The student at your counter, with the assessment particulars.",
        },
        {
          kind: "figure",
          shot: "accounting-serving-actions",
          caption: "Steps 2, 3, 5 and 6: the buttons under the assessment.",
        },
        {
          kind: "paragraph",
          text: "A window then asks for the payment, in two steps.",
        },
        {
          kind: "steps",
          items: [
            { text: "Choose **Regular payee**." },
            {
              text: "Or choose **Scholar** and the scholarship. A scholarship discount is deducted from the assessment automatically.",
            },
            { text: "Select **Continue**." },
          ],
        },
        {
          kind: "figure",
          shot: "accounting-payment-classification",
          caption:
            "First choose whether the student is a regular payee or a scholar.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Choose **Pay in full**. A full-payment discount applies if the Registrar Head has one active.",
            },
            { text: "Or choose **Installment**." },
            { text: "Check the **Amount**." },
            {
              text: "Select **Preview COR** if you want to see the certificate first.",
            },
            { text: "Select **Confirm payment**." },
          ],
        },
        {
          kind: "figure",
          shot: "accounting-confirm-payment",
          caption:
            "Confirm received payment shows the assessment and what remains.",
        },
        {
          kind: "note",
          tone: "careful",
          text: "A first payment must be at least the minimum shown in the window. Confirming the same payment twice has no extra effect, so a double click cannot charge a student twice. A partial payment needs a promissory note on file only when the student carries a bill from an earlier term.",
        },
        {
          kind: "note",
          tone: "know",
          title: "Freshmen and transferees",
          text: "For a freshman or a transferee the button reads **Confirm first payment**. The money is held as credit, the enrollment goes to the Registrar for approval and the system sends the account setup email. The Registrar's approval is what enrolls them.",
        },
      ],
    },
    {
      id: "transaction-history",
      title: "Transaction History",
      blocks: [
        {
          kind: "paragraph",
          text: "Every enrollment payment and balance payment, with the receipt.",
        },
        {
          kind: "figure",
          shot: "accounting-payment-records",
          caption: "Find transactions by student number and date.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Enter a **Student number** or the **Processed on** date.",
            },
            { text: "Select **Search**." },
            {
              text: "Read each transaction: **Enrollment payment** or **Balance payment**.",
            },
          ],
        },
      ],
    },
    {
      id: "advance-payment",
      title: "Advance Payment",
      blocks: [
        {
          kind: "paragraph",
          text: "Record a payment made before enrollment, and correct a student's billing classification.",
        },
        {
          kind: "figure",
          shot: "accounting-advance-payment",
          caption: "Find the student first.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Search a student number, name or email and select the student.",
            },
            {
              text: "Read the **Advance Payment Credit**, the **Current Outstanding Balance** and the **Prior Term Balance**.",
            },
            {
              text: "Select **Record Payee Advance Payment**, enter the **Advance Payment Amount (PHP)** and confirm.",
            },
            {
              text: "To change whether the student is a payee or a scholar, select **Edit Classification**.",
            },
          ],
        },
        {
          kind: "note",
          tone: "know",
          text: "Payments are used on outstanding balances first, and any excess is kept as advance credit for the next enrollment.",
        },
      ],
    },
    {
      id: "statement-of-account",
      title: "Statement of Account",
      blocks: [
        {
          kind: "paragraph",
          text: "What was assessed, what was paid and the running balance of any student, term by term.",
        },
        {
          kind: "figure",
          shot: "accounting-statement-of-account",
          caption: "Look up a student's statement.",
        },
        {
          kind: "steps",
          items: [
            { text: "Search a student number, name or email." },
            { text: "Choose a **Term**, or **All terms**." },
            { text: "Print a copy with **Download PDF**." },
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
          text: "Find and print the confirmed Certificates of Registration of earlier enrollments.",
        },
        {
          kind: "figure",
          shot: "accounting-cor-records",
          caption: "Find a student's COR.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Enter a **Student number**, a **Student name**, or both.",
            },
            {
              text: "Open the student's **Confirmed COR history** and select **View COR**.",
            },
            { text: "Print it or select **Download COR**." },
          ],
        },
      ],
    },
    {
      id: "kiosk-access",
      title: "Queue Kiosk Access",
      blocks: [
        {
          kind: "paragraph",
          text: "The kiosk at the Cashier is a shared device. This page shows its sign-in and lets you rotate its password.",
        },
        {
          kind: "figure",
          shot: "accounting-queue-kiosk-access",
          caption: "The kiosk credential.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Read the **Kiosk email**. The password is hidden: select **Reveal password** to show it.",
            },
            {
              text: "Before a front-desk handoff, enter a **New kiosk password** of at least 8 characters and select **Rotate password**.",
            },
            {
              text: "Confirm. Every active kiosk signs out immediately, and its staff must sign in again with the new password.",
            },
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "If the page says the default kiosk password is still active, rotate it before the kiosk is used. Share the new password only through an approved channel.",
        },
      ],
    },
    {
      id: "dashboard",
      title: "Enrollment Dashboard",
      blocks: [
        {
          kind: "paragraph",
          text: "Shows only the students waiting at the payment stage.",
        },
        {
          kind: "figure",
          shot: "accounting-enrollment-dashboard",
          caption: "The Enrollment Dashboard for Accounting.",
        },
      ],
    },
    {
      id: "fee-settings",
      title: "Fee Settings",
      blocks: [
        {
          kind: "paragraph",
          text: "The tuition rate and the miscellaneous fees that appear on each student's assessment and Certificate of Registration. Every change is recorded in the audit log.",
        },
        {
          kind: "figure",
          shot: "accounting-fee-settings",
          caption: "Tuition rate and fee particulars.",
        },
        {
          kind: "steps",
          items: [
            {
              text: "Enter the **Tuition Rate Per Academic Unit (₱)** and read the **Assessment Calculation Preview**.",
            },
            {
              text: "Edit a fee, or select **Add Fee Particular** and fill in its label, amount, who it applies to and the semester.",
            },
            { text: "Use **Remove fee** for one that no longer applies." },
            { text: "Select **Save Fee Settings**." },
          ],
        },
        {
          kind: "note",
          tone: "careful",
          text: "New and regenerated assessments use the new amounts. Existing Certificates of Registration are not changed.",
        },
      ],
    },
  ],
}
