import { createContext } from "react"

import type {
  AuthSession,
  Credentials,
  LoginOtpChallenge,
} from "@/features/auth/auth-types"
import type { AuthenticatedUser } from "@/features/schemas/auth-schema"

export interface AuthContextValue {
  session: AuthSession | null
  storageAvailable: boolean
  status: "restoring" | "anonymous" | "authenticated"
  signIn: (credentials: Credentials) => Promise<AuthSession>
  verifyLoginOtp: (challengeToken: string, code: string) => Promise<AuthSession>
  resendLoginOtp: (challengeToken: string) => Promise<LoginOtpChallenge>
  signInWithGoogle: (credential: string) => Promise<AuthSession>
  signOut: () => void
  replaceSession: (user: AuthenticatedUser) => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)
