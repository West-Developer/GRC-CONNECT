/**
 * Keeps what a staff member has typed into a long form when they switch tabs,
 * open another portal page, or reload by accident, so coming back does not mean
 * typing it all again. Scoped per (user, form): two people sharing a browser,
 * or two different forms, never see each other's drafts.
 *
 * Client-side only, by design: this is UX convenience, not business state — the
 * authoritative record is only created on submit. It follows
 * `enrollment-draft-store.ts` (injectable storage, never throws when storage is
 * missing or refused), with three differences because these forms hold personal
 * data about people who are not users yet:
 *
 * - it lives in `sessionStorage`, so it dies with the browser tab;
 * - a draft older than {@link FORM_DRAFT_MAX_AGE_MS} is dropped when it is read;
 * - `clearAll()` removes every draft when someone signs out, so the next person
 *   on the machine starts clean. A session that merely expires does not call
 *   it: the half-typed form is the last thing to throw away then, and drafts
 *   are per user, so signing in again brings back exactly that person's.
 *
 * The key carries a version: change it when a form's fields change shape, so an
 * old draft is ignored instead of being fed into the new form.
 */

export const FORM_DRAFT_MAX_AGE_MS = 12 * 60 * 60 * 1000

const KEY_PREFIX = "grc.form-draft.v1."

export interface FormDraftStorageLike {
  readonly length: number
  getItem(key: string): string | null
  key(index: number): string | null
  removeItem(key: string): void
  setItem(key: string, value: string): void
}

export interface FormDraftStore {
  clear(userId: string, formKey: string): void
  /** Every draft of every user. Called when the session ends. */
  clearAll(): void
  read<TValues = unknown>(userId: string, formKey: string): TValues | null
  write(userId: string, formKey: string, values: unknown): boolean
}

interface StoredDraft {
  savedAt: number
  values: unknown
}

function storageKey(userId: string, formKey: string): string {
  return `${KEY_PREFIX}${userId}.${formKey}`
}

function isStoredDraft(value: unknown): value is StoredDraft {
  return (
    typeof value === "object" &&
    value !== null &&
    "savedAt" in value &&
    typeof value.savedAt === "number" &&
    "values" in value &&
    typeof value.values === "object" &&
    value.values !== null
  )
}

export function createFormDraftStore(
  storage: FormDraftStorageLike | null,
  now: () => number = Date.now,
): FormDraftStore {
  const remove = (key: string): void => {
    try {
      storage?.removeItem(key)
    } catch {
      // A browser that refuses storage cannot retain a draft anyway.
    }
  }

  return {
    clear(userId, formKey) {
      remove(storageKey(userId, formKey))
    },

    clearAll() {
      if (!storage) {
        return
      }

      try {
        const draftKeys: string[] = []
        for (let index = 0; index < storage.length; index += 1) {
          const key = storage.key(index)
          if (key?.startsWith(KEY_PREFIX)) {
            draftKeys.push(key)
          }
        }
        // Collected first: removing while counting would skip entries.
        draftKeys.forEach(remove)
      } catch {
        // Nothing to clear if storage cannot even be listed.
      }
    },

    read<TValues = unknown>(userId: string, formKey: string) {
      if (!storage) {
        return null
      }

      const key = storageKey(userId, formKey)

      try {
        const raw = storage.getItem(key)
        if (raw === null || raw.trim() === "") {
          return null
        }

        const parsed: unknown = JSON.parse(raw)
        if (!isStoredDraft(parsed)) {
          return null
        }

        if (now() - parsed.savedAt > FORM_DRAFT_MAX_AGE_MS) {
          remove(key)
          return null
        }

        return parsed.values as TValues
      } catch {
        return null
      }
    },

    write(userId, formKey, values) {
      if (!storage) {
        return false
      }

      try {
        const draft: StoredDraft = { savedAt: now(), values }
        storage.setItem(storageKey(userId, formKey), JSON.stringify(draft))
        return true
      } catch {
        return false
      }
    },
  }
}

let browserStore: FormDraftStore | null = null

/**
 * The store the app uses, over `sessionStorage`. Created on first use rather
 * than at import time: the module is also loaded while Next prerenders, where
 * there is no `window`.
 */
export const browserFormDraftStore: FormDraftStore = {
  clear: (userId, formKey) => resolveBrowserStore().clear(userId, formKey),
  clearAll: () => resolveBrowserStore().clearAll(),
  read<TValues = unknown>(userId: string, formKey: string) {
    return resolveBrowserStore().read<TValues>(userId, formKey)
  },
  write: (userId, formKey, values) =>
    resolveBrowserStore().write(userId, formKey, values),
}

function resolveBrowserStore(): FormDraftStore {
  if (browserStore === null) {
    try {
      browserStore = createFormDraftStore(window.sessionStorage)
    } catch {
      browserStore = createFormDraftStore(null)
    }
  }

  return browserStore
}
