/**
 * Typed mirror of docs/testing/SEEDED_IDENTITIES.md — local/testing-only
 * fixtures created by backend/database/seeders/RoleUserSeeder.php and
 * DatabaseSeeder. Every synthetic login shares this same password; the
 * seeders refuse to run outside Laravel's local/testing environments.
 */
export const SEED_PASSWORD = "password"

export type SeedRole =
  | "student"
  | "admission_staff"
  | "faculty"
  | "program_chair"
  | "dean"
  | "executive_director"
  | "registrar_head"
  | "registrar_staff"
  | "accounting_staff"
  | "it_admin"

export const SEED_IDENTITIES: Record<SeedRole, { email: string; name: string }> = {
  student: { email: "student.seed@grc.test", name: "Seed Student" },
  admission_staff: { email: "admission.seed@grc.test", name: "Seed Admission Staff" },
  faculty: { email: "faculty.seed@grc.test", name: "Seed Faculty" },
  program_chair: { email: "chair.seed@grc.test", name: "Seed Program Chair" },
  dean: { email: "dean.seed@grc.test", name: "Seed Dean" },
  executive_director: { email: "executive.seed@grc.test", name: "Seed Executive Director" },
  registrar_head: { email: "registrar-head.seed@grc.test", name: "Seed Registrar Head" },
  registrar_staff: { email: "registrar-staff.seed@grc.test", name: "Seed Registrar Staff" },
  accounting_staff: { email: "accounting.seed@grc.test", name: "Seed Accounting Staff" },
  it_admin: { email: "it.control@grc.test", name: "IT Control" },
}

/**
 * Additional student lifecycle scenarios from the full DatabaseSeeder.
 * `student.seed@grc.test` itself is 1st year / regular. The four below are
 * 2nd/3rd/4th year regular and one 2nd-year irregular — regular students
 * enrol by block, irregular by subject (DemoEnrollmentSeeder).
 */
export const SEED_STUDENT_SCENARIOS = {
  pendingRegistrarApproval: { email: "student2.seed@grc.test", name: "Seed Student Two" },
  pendingPayment: { email: "student3.seed@grc.test", name: "Seed Student Three" },
  withdrawn: { email: "student4.seed@grc.test", name: "Seed Student Four" },
  irregular: { email: "student5.seed@grc.test", name: "Seed Student Five" },
} as const

/** A deliberately nonexistent email — safe to use for throttle/negative tests. */
export const NONEXISTENT_EMAIL = "not-a-real-account@grc.test"
