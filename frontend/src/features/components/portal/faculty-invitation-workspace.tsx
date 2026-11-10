"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import { Mail, Plus, RotateCcw, X } from "lucide-react"
import { type FormEvent, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { DataUseNotice } from "@/features/components/privacy/data-use-notice"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/features/components/ui/card"
import { Field, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import {
  facultyInvitationsQueryKey,
  useFacultyInvitationsQuery,
} from "@/features/hooks/use-faculty-invitations"
import { isApiClientError } from "@/features/services/api-client"
import {
  inviteFacultyAccount,
  resendFacultyInvitation,
} from "@/features/services/faculty-invitation-service"

const statusBadgeVariant = {
  pending: "warning",
  activated: "success",
  failed: "destructive",
} as const
const statusLabel = {
  pending: "Pending",
  activated: "Activated",
  failed: "Delivery failed",
} as const

function errorMessage(error: unknown): string {
  if (isApiClientError(error)) {
    const fieldErrors = Object.values(error.fieldErrors ?? {}).flat()
    if (fieldErrors.length > 0) return fieldErrors[0]
    return error.message
  }
  if (error instanceof Error) return error.message
  return "The invitation could not be sent. Try again."
}

export function FacultyInvitationWorkspace() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const invitationsQuery = useFacultyInvitationsQuery()
  const [draftEmail, setDraftEmail] = useState("")
  const [queuedEmails, setQueuedEmails] = useState<string[]>([])
  const [sendErrors, setSendErrors] = useState<string[]>([])

  const invalidate = () =>
    queryClient.invalidateQueries({
      queryKey: facultyInvitationsQueryKey(session?.userId ?? null),
      exact: true,
    })

  const sendMutation = useMutation({
    mutationFn: async (emails: string[]) => {
      const results = await Promise.allSettled(
        emails.map((email) => inviteFacultyAccount(email)),
      )
      return results
        .map((result, index) => ({ result, email: emails[index] }))
        .filter(
          (entry): entry is { result: PromiseRejectedResult; email: string } =>
            entry.result.status === "rejected",
        )
        .map((entry) => `${entry.email}: ${errorMessage(entry.result.reason)}`)
    },
    onSuccess: (failures) => {
      setSendErrors(failures)
      setQueuedEmails([])
      void invalidate()
    },
  })

  const resendMutation = useMutation({
    mutationFn: (id: number) => resendFacultyInvitation(id),
    onSuccess: () => void invalidate(),
  })

  const addEmail = (event: FormEvent) => {
    event.preventDefault()
    const trimmed = draftEmail.trim()
    if (trimmed === "" || queuedEmails.includes(trimmed)) return
    setQueuedEmails((current) => [...current, trimmed])
    setDraftEmail("")
  }

  const removeEmail = (email: string) => {
    setQueuedEmails((current) => current.filter((entry) => entry !== email))
  }

  const invitations = invitationsQuery.data ?? []

  return (
    <WorkspacePage
      title="Invite Professors"
      description="Invite a professor by email — they'll get a one-time code to set up their own account."
      unauthorized={session?.role !== "program_chair"}
      lastUpdated={invitationsQuery.dataUpdatedAt}
    >
      <AsyncBoundary
        query={{
          isPending: invitationsQuery.isPending,
          isError: invitationsQuery.isError,
          error: invitationsQuery.error,
          data: true as const,
          refetch: () => void invitationsQuery.refetch(),
        }}
        loadingLabel="Loading invitations…"
      >
        {() => (
          <div className="grid gap-5">
            <Card>
              <CardHeader>
                <CardTitle level={2}>Invite by email</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  Add one or more emails, then send — each professor sets their own name and password.
                </p>
              </CardHeader>
              <CardContent className="grid gap-4">
                <DataUseNotice notice="invite-faculty" />
                <form onSubmit={addEmail} className="flex flex-wrap items-end gap-2">
                  <Field className="min-w-64 flex-1">
                    <FieldLabel htmlFor="invite-email">Professor&apos;s email</FieldLabel>
                    <Input
                      id="invite-email"
                      type="email"
                      value={draftEmail}
                      onChange={(event) => setDraftEmail(event.target.value)}
                      placeholder="professor@example.com"
                    />
                  </Field>
                  <Button type="submit" variant="outline">
                    <Plus data-icon="inline-start" aria-hidden="true" />
                    Add
                  </Button>
                </form>

                {queuedEmails.length > 0 && (
                  <div className="flex flex-wrap gap-2">
                    {queuedEmails.map((email) => (
                      <Badge key={email} variant="secondary" className="gap-1 pr-1">
                        {email}
                        <button
                          type="button"
                          onClick={() => removeEmail(email)}
                          aria-label={`Remove ${email}`}
                          className="rounded-full p-0.5 hover:bg-foreground/10"
                        >
                          <X className="size-3" aria-hidden="true" />
                        </button>
                      </Badge>
                    ))}
                  </div>
                )}

                {sendErrors.length > 0 && (
                  <Alert variant="destructive">
                    <AlertDescription>
                      <ul className="list-disc pl-4">
                        {sendErrors.map((message) => (
                          <li key={message}>{message}</li>
                        ))}
                      </ul>
                    </AlertDescription>
                  </Alert>
                )}

                <Button
                  type="button"
                  onClick={() => {
                    setSendErrors([])
                    sendMutation.mutate(queuedEmails)
                  }}
                  disabled={queuedEmails.length === 0 || sendMutation.isPending}
                  className="w-fit"
                >
                  <Mail data-icon="inline-start" aria-hidden="true" />
                  {sendMutation.isPending
                    ? "Sending…"
                    : `Send ${queuedEmails.length} invitation${queuedEmails.length === 1 ? "" : "s"}`}
                </Button>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <CardTitle level={2}>Your faculty roster</CardTitle>
                  <Badge variant="outline">
                    {invitations.length} account{invitations.length === 1 ? "" : "s"}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent>
                <div className="overflow-x-auto rounded-lg border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Email</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Invited</TableHead>
                        <TableHead />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {invitations.map((invitation) => (
                        <TableRow key={invitation.id}>
                          <TableCell className="font-medium">{invitation.email}</TableCell>
                          <TableCell>{invitation.name}</TableCell>
                          <TableCell>
                            <Badge variant={statusBadgeVariant[invitation.status]}>
                              {statusLabel[invitation.status]}
                            </Badge>
                          </TableCell>
                          <TableCell>
                            {invitation.invitation_sent_at
                              ? new Date(invitation.invitation_sent_at).toLocaleDateString()
                              : "—"}
                          </TableCell>
                          <TableCell className="text-right">
                            {invitation.status !== "activated" && (
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={resendMutation.isPending}
                                onClick={() => resendMutation.mutate(invitation.id)}
                              >
                                <RotateCcw data-icon="inline-start" aria-hidden="true" />
                                Resend
                              </Button>
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                      {invitations.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={5} className="py-9 text-center text-muted-foreground">
                            No professors invited yet.
                          </TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          </div>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
