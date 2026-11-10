/**
 * Persists a student's in-progress subject/section (or block) picks on the
 * enrollment screen across navigation, so leaving the page and coming back
 * does not lose what was already chosen. Scoped per (user, academic term) so
 * different students sharing a browser, or the same student across terms,
 * never see each other's drafts.
 *
 * Client-side only, by design (2026-08-18): this is UX convenience, not
 * business state — the authoritative enrollment record is only created on
 * submit (`POST /enrollments`). Storing it server-side as a real Draft
 * enrollment would surface abandoned picks on Registrar-facing dashboards
 * and need its own cleanup policy, which the feature does not need.
 *
 * Mirrors `features/auth/auth-token.ts`'s storage pattern: dependency-
 * injectable storage, fails closed (never throws) when storage is
 * unavailable or throws.
 */

export interface EnrollmentDraft {
  selections: Record<number, number>
  selectedBlockCode: string | null
}

export interface EnrollmentDraftStorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

export interface EnrollmentDraftStore {
  clear(userId: string | number, academicTermId: number): void
  read(userId: string | number, academicTermId: number): EnrollmentDraft | null
  write(
    userId: string | number,
    academicTermId: number,
    draft: EnrollmentDraft,
  ): boolean
}

function draftStorageKey(
  userId: string | number,
  academicTermId: number,
): string {
  return `grc.enrollment-draft.v1.${userId}.${academicTermId}`
}

export function createEnrollmentDraftStore(
  storage: EnrollmentDraftStorageLike | null,
): EnrollmentDraftStore {
  return {
    clear(userId, academicTermId) {
      if (!storage) {
        return
      }

      try {
        storage.removeItem(draftStorageKey(userId, academicTermId))
      } catch {
        // A browser that refuses storage cannot retain a draft anyway.
      }
    },

    read(userId, academicTermId) {
      if (!storage) {
        return null
      }

      try {
        const raw = storage.getItem(draftStorageKey(userId, academicTermId))
        if (raw === null || raw.trim() === "") {
          return null
        }

        const parsed: unknown = JSON.parse(raw)
        if (
          typeof parsed !== "object" ||
          parsed === null ||
          !("selections" in parsed) ||
          !("selectedBlockCode" in parsed)
        ) {
          return null
        }

        return parsed as EnrollmentDraft
      } catch {
        return null
      }
    },

    write(userId, academicTermId, draft) {
      if (!storage) {
        return false
      }

      try {
        storage.setItem(
          draftStorageKey(userId, academicTermId),
          JSON.stringify(draft),
        )
        return true
      } catch {
        return false
      }
    },
  }
}

export function createBrowserEnrollmentDraftStore(): EnrollmentDraftStore {
  try {
    return createEnrollmentDraftStore(window.localStorage)
  } catch {
    return createEnrollmentDraftStore(null)
  }
}
