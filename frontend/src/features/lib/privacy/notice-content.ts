/**
 * The Data Privacy Notice (ADR 0055), written once. The `/privacy` page, the read-and-agree screen, Admission's
 * Create Account notice and the short "why we ask" notices on every other form all read from here, so the
 * purposes a person is told about cannot differ from one screen to the next.
 *
 * Law: Republic Act No. 10173, the Data Privacy Act of 2012. Nothing here sets a school policy that the school has
 * not published: no retention period is stated, and no Data Protection Officer is named (the contact comes from
 * the API, see `config/privacy.php`). Change the wording only together with `PRIVACY_NOTICE_VERSION` on the server,
 * so everyone is asked to read it again.
 */

/** The version of the wording below. The server decides which version is current; the two must match. */
export const PRIVACY_NOTICE_CONTENT_VERSION = "2026-10-18"

export const PRIVACY_LAW = {
  name: "Data Privacy Act of 2012 (Republic Act No. 10173)",
  regulator: "National Privacy Commission",
  regulatorUrl: "https://privacy.gov.ph",
} as const

export interface NoticeParagraph {
  id: string
  title: string
  body: string
}

/** Who this notice is about. */
export const WHO_IS_COVERED: readonly NoticeParagraph[] = [
  {
    id: "applicants",
    title: "Applicants and incoming freshmen",
    body: "People whose details Admission records before they have paid or signed in for the first time.",
  },
  {
    id: "students",
    title: "Current students",
    body: "Every Freshman, Transferee, Returnee and Existing student, whether Regular or Irregular, and anyone who signs in to take part in enrollment.",
  },
  {
    id: "former-students",
    title: "Former students",
    body: "Graduates and students who withdrew or left. Their academic records stay in the system for as long as the school needs them (see “How long we keep your data”).",
  },
  {
    id: "faculty",
    title: "Professors and faculty",
    body: "Professors who set up an account, state their availability and subject preferences, and are assigned sections.",
  },
  {
    id: "staff",
    title: "Staff who use the system",
    body: "Admission, Registrar, Accounting, Program Heads, Deans, the Executive Director and IT Control. Their name, work email, role and the actions they take in the system are also personal data.",
  },
  {
    id: "queue",
    title: "People who take a queue number",
    body: "A queue number is tied to a student and their email address. The public queue link shows only a number and its status, never a name or any other detail.",
  },
  {
    id: "others",
    title: "Parents, guardians and other people you name",
    body: "The system does not ask for these details now. If a form ever does, this notice applies to them too, and you should give only the details of people who know and agree.",
  },
]

export interface DataUse {
  id: string
  label: string
  /** What is kept. */
  data: readonly string[]
  /** Why it is kept: the only things it is used for. */
  purposes: readonly string[]
  /** Who can see it inside the school. */
  seenBy: readonly string[]
  /** Set when the law treats some of it as sensitive personal information. */
  sensitive?: string
}

/** What the system keeps, why, and who sees it. Every item on every form belongs to one of these. */
export const DATA_USES: readonly DataUse[] = [
  {
    id: "identity",
    label: "Identity and contact details",
    data: [
      "Full name (first, middle, last and suffix) and student number",
      "Email address and contact number",
      "Home address (street or barangay, town or city, province)",
      "Gender, birth date and citizenship",
    ],
    purposes: [
      "Create and identify your student record and account.",
      "Contact you: account-setup emails, your queue number and notices about your enrollment.",
      "Print your name and details on the Certificate of Registration and other school documents.",
      "Prepare the enrollment, graduate and NSTP lists that CHED and the NSTP program require the school to report.",
      "Check who you are when you ask for a correction.",
    ],
    seenBy: [
      "Admission Staff, who enter and correct it",
      "Registrar Head and Registrar Staff, for records and required reports",
      "Accounting Staff: name, student number and payment details only",
      "Program Heads and Deans: students of their own college, to advise and approve enrollment",
      "Professors: the class lists of their own sections",
      "IT Control: name and login email, to manage accounts",
      "You, on your own profile",
    ],
    sensitive:
      "Your birth date shows your age, which the law treats as sensitive personal information.",
  },
  {
    id: "academic",
    label: "Academic and enrollment records",
    data: [
      "Program, year level, student type and curriculum",
      "The subjects and sections you enroll in, adds, drops and withdrawals",
      "Grades, credited subjects from another school, honors and graduation status",
      "Your Certificate of Registration, grade slips and prospectus",
    ],
    purposes: [
      "Enroll you, build schedules and class lists, and keep seats fair.",
      "Record and release your grades, work out your standing and decide credits.",
      "Check whether you are a Regular or Irregular student and what you may enroll in.",
      "Issue the Certificate of Registration, grade slips and records you ask for.",
      "Plan the school's sections and rooms using counts that do not name you.",
    ],
    seenBy: [
      "You",
      "Professors: the grades of their own sections",
      "Program Heads and Deans: their own college, for advising, approvals and planning",
      "Registrar Head and Registrar Staff",
      "Executive Director: summaries and approvals",
    ],
    sensitive:
      "Your grades and other education records are sensitive personal information under the law.",
  },
  {
    id: "documents",
    label: "Admission documents and Transcript of Records",
    data: [
      "Which admission requirements you have handed in",
      "For Transferees and Returnees: the Transcript of Records you upload and the subjects taken elsewhere",
    ],
    purposes: [
      "Confirm you meet the admission requirements.",
      "Credit subjects you already passed elsewhere: the Program Head maps them and the Registrar Head approves them.",
    ],
    seenBy: [
      "Admission Staff",
      "The Program Head of your college and the Registrar Head, for credit mapping",
      "You",
    ],
    sensitive: "A Transcript of Records is an education record.",
  },
  {
    id: "payments",
    label: "Billing, payments and queue number",
    data: [
      "Your assessment, the payments you make (amount, date and reference) and any scholarship or discount",
      "Whether you are a scholar or a payee",
      "Your queue number and where it stands",
    ],
    purposes: [
      "Bill you correctly and confirm your payment.",
      "Let the Cashier call you in order.",
      "Release your Certificate of Registration after payment and keep the school's accounting records.",
    ],
    seenBy: [
      "Accounting Staff",
      "Registrar Head and Registrar Staff: payment status",
      "You",
    ],
  },
  {
    id: "requests",
    label: "Requests you make",
    data: [
      "A request to change your name, email or address, and your reason",
      "A withdrawal request and your reason",
      "Requests to change a section or add or drop a subject",
    ],
    purposes: [
      "Let the school review and decide on your request and keep a record of the decision.",
    ],
    seenBy: [
      "Admission Staff: requests to change your details",
      "Registrar Head and Registrar Staff: withdrawals and enrollment requests",
      "Your Program Head: section and subject requests",
      "You",
    ],
  },
  {
    id: "account",
    label: "Your account and sign-in",
    data: [
      "Your login email and your password, which is stored scrambled so nobody can read it",
      "One-time codes sent to your email",
      "The time and network address of sign-ins and a log of the important actions taken in the system",
    ],
    purposes: [
      "Make sure only you can open your account.",
      "Protect accounts and look into problems or misuse.",
    ],
    seenBy: [
      "IT Control: account status and login email",
      "The Registrar Head and the system administrator, who can read the activity log",
      "You",
    ],
  },
  {
    id: "faculty",
    label: "Professor and staff details",
    data: [
      "Name, work email, college or department and master’s degree",
      "A professor’s availability, subject preferences, teaching load and the sections assigned",
    ],
    purposes: [
      "Create and secure the account.",
      "Build the class schedule, assign sections fairly and check teaching loads.",
      "Show your name on schedules, class lists, grade sheets and approvals.",
    ],
    seenBy: [
      "Program Heads, Deans and the Executive Director, for scheduling and approvals",
      "Registrar Head and Registrar Staff",
      "IT Control: name and login email",
      "The students in your class lists see your name",
    ],
  },
]

export const SENSITIVE_SUMMARY =
  "The law treats some of what the school keeps as sensitive personal information: your age (birth date) and your education records (grades, credits, Transcript of Records). That is why we ask you to read this notice and agree before you use the system."

export const SHARING: readonly string[] = [
  "Inside the school, only the roles listed above can see your data, and only what their work needs.",
  "We do not sell or rent your data, and we do not use it for advertising.",
  "We share it outside the school only when the law or a government office requires it (for example the reports CHED and the NSTP program ask for), or with the providers that host the system and send its emails, who may use it only to run the system for the school.",
  "If you choose Sign in with Google, Google tells us that your email is verified. We never receive your Google password.",
]

export const RETENTION: readonly string[] = [
  "We keep your data only as long as it is needed for the purposes above and for as long as the law and CHED’s rules require the school to keep student records.",
  "The school’s records-retention schedule decides the exact periods. Ask the contact below if you want to know how long a particular record is kept.",
]

export const SECURITY: readonly string[] = [
  "Every role sees only what its work needs.",
  "Passwords are stored scrambled. Setup and reset codes expire and work once.",
  "The system may ask for a one-time code sent to your email when you sign in.",
  "Changes to records and important actions are written to an activity log.",
  "Signing out ends your session and clears half-typed forms from the computer.",
  "If personal data is lost or exposed in a way that puts people at risk, the school tells the people affected and the National Privacy Commission, as the Data Privacy Act requires.",
]

export const YOUR_PART: readonly string[] = [
  "Keep your password and one-time codes to yourself, and sign out on a shared computer.",
  "Give true and current details, and tell the school when they change.",
  "If you are under 18, read this notice together with a parent or guardian.",
]

export const STAFF_DUTIES: readonly string[] = [
  "Use the personal data of students and colleagues only for the work you are assigned.",
  "Do not look up people you have no work reason to look up.",
  "Do not copy, photograph, post or send student data outside the system.",
  "Keep your password and codes private, and sign out on shared computers.",
  "Report a mistake, a loss or a suspected breach to the contact below right away.",
  "Your access and what you do in the system are logged.",
]

export const RIGHTS: readonly NoticeParagraph[] = [
  {
    id: "informed",
    title: "To be informed",
    body: "To know what is collected, why, and who sees it. This notice and the short notice beside each form do that.",
  },
  {
    id: "object",
    title: "To object",
    body: "To object to the processing of your personal data, including a change in how it is used.",
  },
  {
    id: "access",
    title: "To access",
    body: "To ask what personal data the school holds about you and to get a copy.",
  },
  {
    id: "correct",
    title: "To correct",
    body: "To have wrong or outdated details fixed. A student can ask on the Student Information page; Admission checks it against the official documents before changing the record.",
  },
  {
    id: "erase",
    title: "To remove or block",
    body: "To ask that data be removed or blocked when it is incomplete, false, unlawfully obtained or no longer needed, except for records the school must keep by law.",
  },
  {
    id: "portability",
    title: "To receive your data",
    body: "To ask for your data in a commonly used electronic format where it is processed electronically.",
  },
  {
    id: "complain",
    title: "To complain and to claim damages",
    body: "To file a complaint with the National Privacy Commission, and to be paid damages for harm caused by inaccurate, false or unlawfully obtained data.",
  },
]

/* ---- The agreement itself --------------------------------------------------------------------------- */

export const AGREEMENT_TEXT = {
  student:
    "I have read and understood the Data Privacy Notice. I agree that Global Reciprocal Colleges may collect, use and keep my personal data, including sensitive personal information such as my birth date and my education records, for the purposes it describes.",
  staff:
    "I have read and understood the Data Privacy Notice. I agree that Global Reciprocal Colleges may collect and use my personal data for the purposes it describes, and I will keep the personal data of students and colleagues that I handle confidential and use it only for my work.",
  /** What Admission confirms for an applicant, on Create Account. */
  admission:
    "I showed this Data Privacy Notice to the student (or to their parent or guardian). They had the chance to read it and ask questions, and they agree that Global Reciprocal Colleges may collect and use their details for the purposes it describes.",
} as const

/* ---- The short notice beside a form ------------------------------------------------------------------ */

export type InlineNoticeId =
  | "account-setup-student"
  | "account-setup-faculty"
  | "account-setup-staff"
  | "sign-in"
  | "forgot-password"
  | "profile-change"
  | "tor-upload"
  | "withdrawal"
  | "invite-faculty"
  | "invite-staff"
  | "faculty-availability"

export interface InlineNotice {
  /** What this form asks for. */
  collects: string
  /** What it is used for, and only that. */
  purpose: string
  /** Who inside the school can see it. */
  seenBy: string
}

export const INLINE_NOTICES: Record<InlineNoticeId, InlineNotice> = {
  "account-setup-student": {
    collects: "your email, the one-time code and a password you choose",
    purpose:
      "to confirm it is you and to protect your account. Your password is stored scrambled, so nobody can read it",
    seenBy: "IT Control (your account status and login email)",
  },
  "account-setup-faculty": {
    collects:
      "your name, college or department, master’s degree, email and a password you choose",
    purpose:
      "to create and protect your account and to show your name on schedules, class lists, grade sheets and approvals. Your password is stored scrambled",
    seenBy:
      "Program Heads, Deans, the Executive Director, the Registrar and IT Control, each only what their work needs",
  },
  "account-setup-staff": {
    collects:
      "your name, college or department, master’s degree, email and a password you choose",
    purpose:
      "to create and protect your account and to show your name on the approvals and records you handle. Your password is stored scrambled",
    seenBy:
      "The Registrar’s Office, the Executive Director and IT Control, each only what their work needs",
  },
  "sign-in": {
    collects:
      "your email and password, and sometimes a one-time code sent to your email",
    purpose:
      "only to confirm it is you. The time and network address of sign-ins are logged to protect your account",
    seenBy:
      "only the Registrar Head and the system administrator, who can read the activity log",
  },
  "forgot-password": {
    collects: "the email address you type here",
    purpose:
      "only to send a reset code to the matching account. The answer is the same whether or not an account exists",
    seenBy: "nobody else; it is used only for this request",
  },
  "profile-change": {
    collects: "the new name, email or address you propose, and your reason",
    purpose:
      "so Admission can check the change against your official documents before it changes your record",
    seenBy: "Admission Staff and you",
  },
  "tor-upload": {
    collects:
      "your Transcript of Records, which holds your grades and personal details",
    purpose:
      "only to credit the subjects you already passed: your Program Head maps them and the Registrar Head approves them",
    seenBy: "your Program Head, the Registrar Head, Admission Staff and you",
  },
  withdrawal: {
    collects: "your reason for withdrawing",
    purpose:
      "so the Registrar can review and decide on your request, and the decision can be recorded",
    seenBy: "the Registrar Head and Registrar Staff, and you",
  },
  "invite-faculty": {
    collects: "the professor’s email address",
    purpose:
      "only to send a one-time invitation to set up an account. The professor gives their own name and details when they set up",
    seenBy: "you, and IT Control, which manages accounts",
  },
  "invite-staff": {
    collects: "the person’s email address and the role they will have",
    purpose:
      "only to send a one-time invitation to set up an account. The person gives their own name and details when they set up",
    seenBy: "you, and IT Control, which manages accounts",
  },
  "faculty-availability": {
    collects: "the days and times you are free to teach",
    purpose: "only to build the class schedule and assign sections fairly",
    seenBy: "your Program Head, the Dean and the Executive Director",
  },
}

/** The staff roles the portal knows. A student sees the student wording; everyone else the staff wording. */
export function agreementTextForRole(role: string | undefined): string {
  return role === "student" ? AGREEMENT_TEXT.student : AGREEMENT_TEXT.staff
}
