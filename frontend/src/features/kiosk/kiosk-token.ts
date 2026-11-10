import type { TokenStorageLike } from "@/features/auth/auth-token"

/** Dedicated persistent device credential; never shares portal-token storage. */
export const kioskTokenStorageKey = "grc.kiosk-token.v1"

export interface KioskTokenStore {
  clear(): void
  read(): string | null
  write(token: string): boolean
}

export function createKioskTokenStore(
  storage: TokenStorageLike | null,
): KioskTokenStore {
  return {
    clear() {
      if (!storage) return

      try {
        storage.removeItem(kioskTokenStorageKey)
      } catch {
        // A kiosk that cannot access storage cannot retain this device token.
      }
    },

    read() {
      if (!storage) return null

      try {
        const token = storage.getItem(kioskTokenStorageKey)
        return token !== null && token.trim() !== "" ? token : null
      } catch {
        return null
      }
    },

    write(token) {
      if (!storage) return false

      try {
        storage.setItem(kioskTokenStorageKey, token)
        return true
      } catch {
        return false
      }
    },
  }
}

export function createBrowserKioskTokenStore(): KioskTokenStore {
  try {
    return createKioskTokenStore(window.localStorage)
  } catch {
    return createKioskTokenStore(null)
  }
}
