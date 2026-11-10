import {
  closingInstant,
  openingInstant,
} from "@/features/lib/enrollment-window-time"
import {
  enrollmentScheduleEnvelopeSchema,
  saveEnrollmentScheduleInputSchema,
  type EnrollmentSchedule,
  type SaveEnrollmentScheduleInput,
} from "@/features/schemas/enrollment-window-schema"
import {
  ApiClientError,
  getAuthenticatedJson,
  patchAuthenticatedJson,
} from "@/features/services/api-client"

function parseResponse<T>(
  schema: {
    safeParse: (
      payload: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  payload: unknown,
  resourceName: string,
): T {
  const parsed = schema.safeParse(payload)

  if (!parsed.success) {
    throw new ApiClientError({
      kind: "contract",
      message: `The API responded, but its ${resourceName} payload did not match the published v1 contract.`,
      cause: parsed.error,
    })
  }

  return parsed.data
}

function enrollmentSchedulePath(academicTermId: number): string {
  return `/api/v1/academic-terms/${academicTermId}/enrollment-windows`
}

function enrollmentScheduleUpdatePath(academicTermId: number): string {
  return `/api/v1/academic-terms/${academicTermId}/enrollment-schedule`
}

export async function getEnrollmentSchedule(
  academicTermId: number,
  signal?: AbortSignal,
): Promise<EnrollmentSchedule> {
  const payload = await getAuthenticatedJson(
    enrollmentSchedulePath(academicTermId),
    signal,
  )

  return parseResponse(
    enrollmentScheduleEnvelopeSchema,
    payload,
    "enrollment schedule",
  ).data
}

/**
 * The Registrar submits dates only. Each date is composed into a full ISO
 * 8601 instant with the fixed 08:00 open / 23:59 close convention
 * (`enrollment-window-time.ts`) before it reaches the API, which is
 * unaware of that convention and still stores/returns full datetimes.
 */
export async function saveEnrollmentSchedule(
  academicTermId: number,
  input: SaveEnrollmentScheduleInput,
): Promise<EnrollmentSchedule> {
  const parsedInput = parseResponse(
    saveEnrollmentScheduleInputSchema,
    input,
    "enrollment schedule save request",
  )

  const payload = await patchAuthenticatedJson(
    enrollmentScheduleUpdatePath(academicTermId),
    {
      enrollment_opens_at: new Date(
        openingInstant(parsedInput.enrollment_opens_at),
      ).toISOString(),
      enrollment_closes_at: new Date(
        closingInstant(parsedInput.enrollment_closes_at),
      ).toISOString(),
      add_drop_opens_at: parsedInput.add_drop_opens_at
        ? new Date(openingInstant(parsedInput.add_drop_opens_at)).toISOString()
        : null,
      add_drop_closes_at: parsedInput.add_drop_closes_at
        ? new Date(closingInstant(parsedInput.add_drop_closes_at)).toISOString()
        : null,
      ...(parsedInput.enrollment_platform !== undefined
        ? { enrollment_platform: parsedInput.enrollment_platform }
        : {}),
      windows: parsedInput.windows.map((window) => ({
        audience: window.audience,
        opens_at: window.opens_at
          ? new Date(openingInstant(window.opens_at)).toISOString()
          : null,
        closes_at: window.closes_at
          ? new Date(closingInstant(window.closes_at)).toISOString()
          : null,
      })),
    },
  )

  return parseResponse(
    enrollmentScheduleEnvelopeSchema,
    payload,
    "updated enrollment schedule",
  ).data
}
