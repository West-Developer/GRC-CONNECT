export interface SuperAdminActingContext {
  role: string
  college?: string | null
}

export interface ActingContextStore {
  get(): SuperAdminActingContext | null | undefined
  set(context: SuperAdminActingContext | null | undefined): void
  getHeaderValue(): string | null
  clear(): void
}

export function createActingContextStore(): ActingContextStore {
  let current: SuperAdminActingContext | null | undefined = undefined

  return {
    get() {
      return current
    },
    set(context) {
      current = context
    },
    getHeaderValue() {
      if (current === undefined) {
        return null
      }
      if (current === null) {
        return "none"
      }
      return current.college ? `${current.role}:${current.college}` : current.role
    },
    clear() {
      current = undefined
    },
  }
}

export const browserActingContextStore = createActingContextStore()
