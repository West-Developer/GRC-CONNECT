"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { Building2, CircleAlert, Mail, MapPin, UserRound } from "lucide-react"
import { useEffect } from "react"
import { useForm } from "react-hook-form"

import { AccordionCard } from "@/features/components/portal/accordion-card"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { formatYearLevel } from "@/features/lib/format-year-level"
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useCancelProfileChangeRequestMutation,
  useCreateProfileChangeRequestMutation,
  useOwnStudentProfileQuery,
  useProfileChangeRequestsQuery,
  useReviseProfileChangeRequestMutation,
} from "@/features/hooks/use-student-records"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  storeProfileChangeRequestSchema,
  type ProfileChangeRequest,
  type StoreProfileChangeRequestInput,
} from "@/features/schemas/admission-schema"

function statusVariant(status: ProfileChangeRequest["status"]) {
  if (status === "approved") return "success" as const
  if (status === "rejected") return "destructive" as const
  if (status === "pending") return "warning" as const
  return "outline" as const
}

export function StudentInformationWorkspace() {
  const profileQuery = useOwnStudentProfileQuery()
  const requestsQuery = useProfileChangeRequestsQuery({ page: 1, per_page: 50 })
  const create = useCreateProfileChangeRequestMutation()
  const revise = useReviseProfileChangeRequestMutation()
  const cancel = useCancelProfileChangeRequestMutation()
  const profile = profileQuery.data
  const requests = requestsQuery.data?.data ?? []
  const pending = requests.find((request) => request.status === "pending")
  const {
    formState: { errors },
    handleSubmit,
    register,
    reset,
    setError,
  } = useForm<StoreProfileChangeRequestInput>({
    resolver: zodResolver(storeProfileChangeRequestSchema),
    defaultValues: {
      first_name: "",
      middle_name: "",
      last_name: "",
      suffix: "",
      email: "",
      address: "",
      reason: "",
    },
  })

  useEffect(() => {
    if (!profile) return
    reset({
      first_name: pending?.requested.first_name ?? profile.first_name,
      // An older account has only the initial; that is what the box starts with until a full name is typed.
      middle_name:
        pending?.requested.middle_name ??
        pending?.requested.middle_initial ??
        profile.middle_name ??
        profile.middle_initial ??
        "",
      last_name: pending?.requested.last_name ?? profile.last_name,
      suffix: pending?.requested.suffix ?? profile.suffix ?? "",
      email: pending?.requested.email ?? profile.email,
      address: pending?.requested.address ?? profile.address ?? "",
      reason: pending?.reason ?? "",
    })
  }, [pending, profile, reset])

  const submit = async (values: StoreProfileChangeRequestInput) => {
    try {
      if (pending) {
        await revise.mutateAsync({ id: pending.id, input: values })
      } else {
        await create.mutateAsync(values)
      }
    } catch (error) {
      applyApiFieldErrors(error, setError)
    }
  }

  const combinedQuery = {
    isPending: profileQuery.isPending || requestsQuery.isPending,
    isError: profileQuery.isError || requestsQuery.isError,
    error: profileQuery.error ?? requestsQuery.error,
    data: profileQuery.data,
    refetch: () => {
      void profileQuery.refetch()
      void requestsQuery.refetch()
    },
  }

  return (
    <WorkspacePage
      title="Student Information"
      description="View your official student record and request verified personal-information changes."
    >
      <AsyncBoundary
        query={combinedQuery}
        loadingLabel="Loading student information…"
      >
        {(official) => (
          <div className="space-y-5">
            <Alert>
              <CircleAlert aria-hidden="true" />
              <AlertTitle>Admission verification is required</AlertTitle>
              <AlertDescription>
                After submitting a change request, go to the Admission Office
                with supporting documents. Your official information changes
                only after Admission verifies and approves the request.
              </AlertDescription>
            </Alert>

            <div className="grid gap-4 lg:grid-cols-3">
              <Card>
                <CardHeader>
                  <CardTitle level={2}>Official identity</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <p className="flex gap-2">
                    <UserRound aria-hidden="true" className="size-4" />
                    <span>
                      <strong>{official.name}</strong>
                      <br />
                      {official.student_number}
                    </span>
                  </p>
                  <p>
                    <strong>Middle name</strong>
                    <br />
                    {official.middle_name ??
                      (official.middle_initial
                        ? `${official.middle_initial}.`
                        : "None on record")}
                  </p>
                  <p className="flex gap-2">
                    <Mail aria-hidden="true" className="size-4" />
                    {official.email}
                  </p>
                  <p className="flex gap-2 whitespace-pre-line">
                    <MapPin aria-hidden="true" className="size-4 shrink-0" />
                    {official.address ?? "Not provided"}
                  </p>
                </CardContent>
              </Card>
              <Card className="lg:col-span-2">
                <CardHeader>
                  <CardTitle level={2}>Academic profile</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm sm:grid-cols-2">
                  <p className="flex gap-2">
                    <Building2 aria-hidden="true" className="size-4" />
                    <span>
                      <strong>{official.program_code}</strong>
                      <br />
                      {official.program_name}
                    </span>
                  </p>
                  <p>
                    <strong>Curriculum</strong>
                    <br />
                    {official.curriculum_name} (
                    {official.curriculum_effective_school_year})
                  </p>
                  <p>
                    <strong>Year level</strong>
                    <br />
                    {formatYearLevel(official.year_level)}
                  </p>
                  <p>
                    <strong>Status</strong>
                    <br />
                    {official.admission_status_label} ·{" "}
                    {official.academic_standing_label}
                  </p>
                </CardContent>
              </Card>
            </div>

            {pending && (
              <Card className="border-warning/40 bg-warning/5">
                <CardHeader>
                  <div className="flex flex-wrap items-center gap-2">
                    <CardTitle level={2}>Pending proposed values</CardTitle>
                    <Badge variant="warning">Awaiting Admission</Badge>
                  </div>
                  <CardDescription>
                    These values are not yet part of your official record.
                  </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-3 text-sm sm:grid-cols-3">
                  <p>
                    <strong>Name</strong>
                    <br />
                    {pending.requested.name}
                  </p>
                  {pending.requested.middle_name !== null && (
                    <p>
                      <strong>Middle name</strong>
                      <br />
                      {pending.requested.middle_name}
                    </p>
                  )}
                  <p>
                    <strong>Email</strong>
                    <br />
                    {pending.requested.email}
                  </p>
                  <p className="whitespace-pre-line">
                    <strong>Address</strong>
                    <br />
                    {pending.requested.address}
                  </p>
                </CardContent>
              </Card>
            )}

            {/* A 7-field form is a lot to meet at once on a phone: it starts
                collapsed there (stakeholder Doc 13) and opens with one tap. */}
            <AccordionCard
              id="student-info-request"
              title={
                pending
                  ? "Revise pending request"
                  : "Request an information change"
              }
              description="Only your name, email, and complete address may be requested here."
              collapseOnMobile
            >
              <form onSubmit={(event) => void handleSubmit(submit)(event)}>
                <FieldGroup className="grid gap-4 md:grid-cols-2">
                  <Field data-invalid={Boolean(errors.first_name)}>
                    <FieldLabel htmlFor="request-first-name">
                      Proposed first name
                    </FieldLabel>
                    <Input
                      id="request-first-name"
                      {...register("first_name")}
                    />
                    <FieldError>{errors.first_name?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.last_name)}>
                    <FieldLabel htmlFor="request-last-name">
                      Proposed last name
                    </FieldLabel>
                    <Input id="request-last-name" {...register("last_name")} />
                    <FieldError>{errors.last_name?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.middle_name)}>
                    <FieldLabel htmlFor="request-middle-name">
                      Proposed middle name
                    </FieldLabel>
                    <Input
                      id="request-middle-name"
                      maxLength={100}
                      {...register("middle_name")}
                    />
                    <FieldDescription>
                      Optional. Only you and Admission see the full middle name;
                      it shows as an initial everywhere else.
                    </FieldDescription>
                    <FieldError>{errors.middle_name?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.suffix)}>
                    <FieldLabel htmlFor="request-suffix">
                      Proposed suffix
                    </FieldLabel>
                    <Input
                      id="request-suffix"
                      placeholder="Jr., Sr., III…"
                      maxLength={20}
                      {...register("suffix")}
                    />
                    <FieldDescription>Optional.</FieldDescription>
                    <FieldError>{errors.suffix?.message}</FieldError>
                  </Field>
                  <Field data-invalid={Boolean(errors.email)}>
                    <FieldLabel htmlFor="request-email">
                      Proposed email
                    </FieldLabel>
                    <Input
                      id="request-email"
                      type="email"
                      {...register("email")}
                    />
                    <FieldError>{errors.email?.message}</FieldError>
                  </Field>
                  <Field
                    className="md:col-span-2"
                    data-invalid={Boolean(errors.address)}
                  >
                    <FieldLabel htmlFor="request-address">
                      Proposed complete address
                    </FieldLabel>
                    <Textarea id="request-address" {...register("address")} />
                    <FieldError>{errors.address?.message}</FieldError>
                  </Field>
                  <Field
                    className="md:col-span-2"
                    data-invalid={Boolean(errors.reason)}
                  >
                    <FieldLabel htmlFor="request-reason">
                      Reason for change
                    </FieldLabel>
                    <Textarea id="request-reason" {...register("reason")} />
                    <FieldDescription>
                      Explain what needs correction and bring supporting
                      documents to Admission.
                    </FieldDescription>
                    <FieldError>{errors.reason?.message}</FieldError>
                  </Field>
                  <DataUseNotice
                    className="md:col-span-2"
                    notice="profile-change"
                  />
                  {(create.isError || revise.isError) && (
                    <Alert className="md:col-span-2" variant="destructive">
                      <AlertDescription>
                        The request could not be saved. Review the values and
                        try again.
                      </AlertDescription>
                    </Alert>
                  )}
                  <div className="flex flex-wrap gap-2 md:col-span-2">
                    <Button
                      type="submit"
                      disabled={create.isPending || revise.isPending}
                    >
                      {pending
                        ? "Save revised request"
                        : "Submit change request"}
                    </Button>
                    {pending && (
                      <Button
                        type="button"
                        variant="outline"
                        disabled={cancel.isPending}
                        onClick={() => void cancel.mutateAsync(pending.id)}
                      >
                        Cancel pending request
                      </Button>
                    )}
                  </div>
                </FieldGroup>
              </form>
            </AccordionCard>

            {requests.length > 0 && (
              <AccordionCard
                id="student-info-history"
                title="Request history"
                collapseOnMobile
                contentClassName="space-y-3"
              >
                {requests.map((request) => (
                  <div
                    key={request.id}
                    className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-start sm:justify-between"
                  >
                    <div>
                      <p className="font-medium">{request.reason}</p>
                      <p className="text-sm text-muted-foreground">
                        {request.decision_notes ??
                          (request.status === "pending"
                            ? "Visit Admission for verification."
                            : "No decision notes.")}
                      </p>
                    </div>
                    <Badge variant={statusVariant(request.status)}>
                      {request.status_label}
                    </Badge>
                  </div>
                ))}
              </AccordionCard>
            )}
          </div>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
