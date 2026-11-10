// This module holds mutable module-scope state (the token provider and 401
// handler below). Under Next.js a module is shared across every request the
// server process handles, so it must never be imported by a Server Component —
// doing so would leak one user's credentials into another's request. The
// setters are called from `src/app/providers.tsx`, which is "use client".

import { apiErrorEnvelopeSchema } from "@/features/schemas/api-error-schema"

const DEFAULT_API_BASE_URL = "http://127.0.0.1:8000"

/**
 * Supplies the bearer token for outgoing requests. Registered once at startup
 * so this module never imports the token store directly, keeping
 * `auth-token.ts` the single owner of token storage (PRD §9.1).
 */
type TokenProvider = () => string | null

let provideToken: TokenProvider = () => null

/** Invoked whenever the API rejects a token, so the app can sign the user out. */
type UnauthorizedHandler = () => void

let handleUnauthorized: UnauthorizedHandler = () => undefined

export function setAuthTokenProvider(provider: TokenProvider): void {
  provideToken = provider
}

export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
  handleUnauthorized = handler
}

/**
 * Supplies the acting-context header value (e.g. "none" or "dean:ccs") for outgoing requests
 * when a super admin is signed in. Registered once at startup or during session setup.
 */
type ActingContextProvider = () => string | null

let provideActingContext: ActingContextProvider = () => null

/** Invoked when a super admin request receives 409 ACTING_CONTEXT_CHANGED. */
type ActingContextConflictHandler = () => void

let handleActingContextConflict: ActingContextConflictHandler = () => undefined

export function setActingContextProvider(provider: ActingContextProvider): void {
  provideActingContext = provider
}

export function setActingContextConflictHandler(
  handler: ActingContextConflictHandler,
): void {
  handleActingContextConflict = handler
}

/** Per-request authentication isolation for device and portal token flows. */
export interface AuthenticatedRequestOptions {
  token?: string
  headers?: Readonly<Record<string, string>>
  suppressUnauthorizedHandler?: boolean
}

const reservedHeaderNames = new Set(["accept", "authorization", "content-type"])

function getCallerHeaders(
  headers: AuthenticatedRequestOptions["headers"],
): Record<string, string> {
  return Object.fromEntries(
    Object.entries(headers ?? {}).filter(
      ([name]) => !reservedHeaderNames.has(name.toLowerCase()),
    ),
  )
}

export type ApiClientErrorKind =
  "configuration" | "connection" | "contract" | "http"

interface ApiClientErrorOptions {
  kind: ApiClientErrorKind
  message: string
  cause?: unknown
  code?: string
  fieldErrors?: Record<string, string[]>
  requestId?: string
  retryAfterSeconds?: number
  status?: number
}

export class ApiClientError extends Error {
  readonly kind: ApiClientErrorKind
  readonly code?: string
  readonly fieldErrors?: Record<string, string[]>
  readonly requestId?: string
  readonly retryAfterSeconds?: number
  readonly status?: number

  constructor({
    kind,
    message,
    cause,
    code,
    fieldErrors,
    requestId,
    retryAfterSeconds,
    status,
  }: ApiClientErrorOptions) {
    super(message, { cause })
    this.name = "ApiClientError"
    this.kind = kind
    this.code = code
    this.fieldErrors = fieldErrors
    this.requestId = requestId
    this.retryAfterSeconds = retryAfterSeconds
    this.status = status
  }
}

export function isApiClientError(error: unknown): error is ApiClientError {
  return error instanceof ApiClientError
}

function isLocalOrPrivateHost(hostname: string): boolean {
  return (
    hostname === "localhost" ||
    hostname === "127.0.0.1" ||
    hostname === "::1" ||
    /^192\.168\.\d+\.\d+$/.test(hostname) ||
    /^10\.\d+\.\d+\.\d+$/.test(hostname) ||
    /^172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+$/.test(hostname)
  )
}

function resolveApiBaseUrl(): string {
  const configuredBaseUrl =
    process.env.NEXT_PUBLIC_API_BASE_URL?.trim() || DEFAULT_API_BASE_URL

  // When loaded in a browser on a local network device (e.g. phone/tablet or other PC
  // reaching http://192.168.x.x:3000), adapt a loopback or local development API base URL
  // so requests target the current host machine rather than loopback or a stale IP.
  if (typeof window !== "undefined" && window.location?.hostname) {
    const currentHost = window.location.hostname
    const isLoopback =
      currentHost === "localhost" ||
      currentHost === "127.0.0.1" ||
      currentHost === "::1"

    if (!isLoopback) {
      try {
        const parsed = new URL(configuredBaseUrl)
        if (isLocalOrPrivateHost(parsed.hostname)) {
          parsed.hostname = currentHost
          return parsed.toString().replace(/\/+$/, "")
        }
      } catch {
        // Fall back to configuredBaseUrl if URL parsing fails
      }
    }
  }

  return configuredBaseUrl
}

function buildApiUrl(path: string): string {
  const baseUrl = resolveApiBaseUrl()

  try {
    return new URL(path, baseUrl).toString()
  } catch (cause) {
    throw new ApiClientError({
      kind: "configuration",
      message: "The public API address is not configured correctly.",
      cause,
    })
  }
}

/** Laravel's throttle middleware always sends this as a plain integer count of seconds. */
function readRetryAfterSeconds(response: Response): number | undefined {
  const header = response.headers.get("Retry-After")

  if (header === null) {
    return undefined
  }

  const seconds = Number(header)

  return Number.isFinite(seconds) && seconds >= 0 ? seconds : undefined
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return (await response.json()) as unknown
  } catch (cause) {
    throw new ApiClientError({
      kind: "contract",
      message: "The public API returned a response that was not valid JSON.",
      cause,
      status: response.status,
    })
  }
}

interface RequestOptions {
  authenticated?: boolean
  authenticatedOptions?: AuthenticatedRequestOptions
  body?: unknown
  method: "GET" | "POST" | "PUT" | "PATCH" | "DELETE"
  signal?: AbortSignal
}

async function request(
  path: string,
  {
    authenticated = false,
    authenticatedOptions,
    body,
    method,
    signal,
  }: RequestOptions,
): Promise<unknown> {
  const headers: Record<string, string> = {
    ...getCallerHeaders(authenticatedOptions?.headers),
    Accept: "application/json",
  }

  // A FormData body (a file upload) carries its own multipart Content-Type with the
  // boundary; setting one here would break it.
  const isForm = typeof FormData !== "undefined" && body instanceof FormData

  if (body !== undefined && !isForm) {
    headers["Content-Type"] = "application/json"
  }

  if (authenticated) {
    const token = authenticatedOptions?.token ?? provideToken()

    if (token !== null) {
      headers.Authorization = `Bearer ${token}`
    }

    const actingContext = provideActingContext()
    if (actingContext !== null && headers["X-Acting-Context"] === undefined) {
      headers["X-Acting-Context"] = actingContext
    }
  }

  let response: Response

  try {
    response = await fetch(buildApiUrl(path), {
      method,
      headers,
      body:
        body === undefined
          ? undefined
          : isForm
            ? body
            : JSON.stringify(body),
      credentials: "omit",
      cache: "no-store",
      signal,
    })
  } catch (cause) {
    if (cause instanceof Error && cause.name === "AbortError") {
      throw cause
    }

    throw new ApiClientError({
      kind: "connection",
      message:
        "The public enrollment API could not be reached from this browser.",
      cause,
    })
  }

  if (response.status === 204) {
    return null
  }

  const payload = await readJson(response)

  if (!response.ok) {
    // A rejected token must never leave the app in a half-signed-in state.
    // Login itself returns 401 for bad credentials, so it opts out.
    if (
      response.status === 401 &&
      authenticated &&
      !authenticatedOptions?.suppressUnauthorizedHandler
    ) {
      handleUnauthorized()
    }

    const parsedError = apiErrorEnvelopeSchema.safeParse(payload)
    const retryAfterSeconds = readRetryAfterSeconds(response)

    if (parsedError.success) {
      if (
        response.status === 409 &&
        parsedError.data.error.code === "ACTING_CONTEXT_CHANGED"
      ) {
        handleActingContextConflict()
      }

      throw new ApiClientError({
        kind: "http",
        message: parsedError.data.error.message,
        code: parsedError.data.error.code,
        fieldErrors: parsedError.data.error.errors,
        requestId: parsedError.data.error.request_id,
        retryAfterSeconds,
        status: response.status,
      })
    }

    throw new ApiClientError({
      kind: "http",
      message: "The public API returned an unexpected error response.",
      retryAfterSeconds,
      status: response.status,
    })
  }

  return payload
}

export function getJson(path: string, signal?: AbortSignal): Promise<unknown> {
  return request(path, { method: "GET", signal })
}

export function getAuthenticatedJson(
  path: string,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<unknown> {
  return request(path, {
    authenticated: true,
    authenticatedOptions: options,
    method: "GET",
    signal,
  })
}

export function postJson(
  path: string,
  body: unknown,
  signal?: AbortSignal,
): Promise<unknown> {
  return request(path, { body, method: "POST", signal })
}

export function postAuthenticatedJson(
  path: string,
  body?: unknown,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<unknown> {
  return request(path, {
    authenticated: true,
    authenticatedOptions: options,
    body: body ?? {},
    method: "POST",
    signal,
  })
}

/** A multipart upload (a file) to an authenticated endpoint. */
export function postAuthenticatedForm(
  path: string,
  form: FormData,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<unknown> {
  return request(path, {
    authenticated: true,
    authenticatedOptions: options,
    body: form,
    method: "POST",
    signal,
  })
}

export function patchAuthenticatedJson(
  path: string,
  body: unknown,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<unknown> {
  return request(path, {
    authenticated: true,
    authenticatedOptions: options,
    body,
    method: "PATCH",
    signal,
  })
}

export function putAuthenticatedJson(
  path: string,
  body: unknown,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<unknown> {
  return request(path, {
    authenticated: true,
    authenticatedOptions: options,
    body,
    method: "PUT",
    signal,
  })
}

export function deleteAuthenticatedJson(
  path: string,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<unknown> {
  return request(path, {
    authenticated: true,
    authenticatedOptions: options,
    method: "DELETE",
    signal,
  })
}

export async function getAuthenticatedBlob(
  path: string,
  signal?: AbortSignal,
  options?: AuthenticatedRequestOptions,
): Promise<Blob> {
  const headers: Record<string, string> = {
    ...getCallerHeaders(options?.headers),
    Accept: "application/pdf, application/octet-stream, */*",
  }

  const token = options?.token ?? provideToken()
  if (token !== null) {
    headers.Authorization = `Bearer ${token}`
  }

  let response: Response
  try {
    response = await fetch(buildApiUrl(path), {
      method: "GET",
      headers,
      credentials: "omit",
      cache: "no-store",
      signal,
    })
  } catch (cause) {
    if (cause instanceof Error && cause.name === "AbortError") {
      throw cause
    }
    throw new ApiClientError({
      kind: "connection",
      message: "The public enrollment API could not be reached from this browser.",
      cause,
    })
  }

  if (!response.ok) {
    if (response.status === 401 && !options?.suppressUnauthorizedHandler) {
      handleUnauthorized()
    }
    throw new ApiClientError({
      kind: "http",
      message: "Failed to download document from server.",
      status: response.status,
    })
  }

  return response.blob()
}
