"use client"

import { useMutation, useQueryClient } from "@tanstack/react-query"
import {
  KeyRound,
  LogOut,
  Plus,
  RotateCcw,
  ShieldAlert,
  Trash2,
  UserCheck,
  UserCog,
  UserX,
} from "lucide-react"
import { type FormEvent, useState } from "react"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { Paginator } from "@/features/components/portal/paginator"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/features/components/ui/table"
import { Textarea } from "@/features/components/ui/textarea"
import {
  useManagedUsersQuery,
} from "@/features/hooks/use-super-admin-accounts"
import {
  collegeCodes,
  superAdminInvitableRoles,
  type CollegeCode,
  type ManagedUser,
  type ManagedUserFilters,
  type SuperAdminInvitableRole,
} from "@/features/schemas/super-admin-schema"
import { isApiClientError } from "@/features/services/api-client"
import {
  changeUserRole,
  deleteUserAccount,
  inviteUserAccount,
  resendSetupInvitation,
  revokeUserSessions,
  sendPasswordReset,
  setUserAccountStatus,
} from "@/features/services/super-admin-service"

const ROLE_OPTIONS: { value: SuperAdminInvitableRole; label: string }[] = [
  { value: "faculty", label: "Professor / Faculty" },
  { value: "program_chair", label: "Program Head" },
  { value: "dean", label: "Dean" },
  { value: "executive_director", label: "Executive Director" },
  { value: "registrar_head", label: "Registrar Head" },
  { value: "registrar_staff", label: "Registrar Staff" },
  { value: "accounting_staff", label: "Accounting Staff" },
  { value: "it_admin", label: "IT Control" },
  { value: "admission_staff", label: "Admission Staff" },
]

const COLLEGE_OPTIONS: { value: CollegeCode; label: string }[] = [
  { value: "ccs", label: "CCS - College of Computer Studies" },
  { value: "coe", label: "COE - College of Education" },
  { value: "coa", label: "COA - College of Accountancy" },
  { value: "cbae", label: "CBAE - College of Business Administration" },
]

const ALL_FILTER_VALUE = "all"

function getErrorMessage(error: unknown, fallback: string): string {
  if (isApiClientError(error)) {
    const fieldErrors = Object.values(error.fieldErrors ?? {}).flat()
    if (fieldErrors.length > 0) return fieldErrors[0]
    return error.message || fallback
  }
  if (error instanceof Error) return error.message
  return fallback
}

export function SuperAdminAccountsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "super_admin"
  const queryClient = useQueryClient()

  const [filters, setFilters] = useState<ManagedUserFilters>({
    page: 1,
    per_page: 20,
  })
  const [searchDraft, setSearchDraft] = useState("")
  const [roleFilter, setRoleFilter] = useState(ALL_FILTER_VALUE)
  const [statusFilter, setStatusFilter] = useState(ALL_FILTER_VALUE)
  const [collegeFilter, setCollegeFilter] = useState(ALL_FILTER_VALUE)
  const [setupFilter, setSetupFilter] = useState(ALL_FILTER_VALUE)

  const usersQuery = useManagedUsersQuery(filters, authorized)

  // Dialog states
  const [isInviteOpen, setIsInviteOpen] = useState(false)
  const [inviteEmail, setInviteEmail] = useState("")
  const [inviteRole, setInviteRole] =
    useState<SuperAdminInvitableRole>("faculty")
  const [inviteCollege, setInviteCollege] = useState<CollegeCode | "">("ccs")
  const [inviteError, setInviteError] = useState<string | null>(null)

  const [roleChangeTarget, setRoleChangeTarget] = useState<ManagedUser | null>(
    null,
  )
  const [newRole, setNewRole] = useState<SuperAdminInvitableRole>("faculty")
  const [newRoleCollege, setNewRoleCollege] = useState<CollegeCode | "">("ccs")
  const [roleChangeReason, setRoleChangeReason] = useState("")
  const [roleChangeError, setRoleChangeError] = useState<string | null>(null)

  const [statusChangeTarget, setStatusChangeTarget] =
    useState<ManagedUser | null>(null)
  const [statusChangeReason, setStatusChangeReason] = useState("")
  const [statusChangeError, setStatusChangeError] = useState<string | null>(
    null,
  )

  const [deleteTarget, setDeleteTarget] = useState<ManagedUser | null>(null)
  const [deleteConfirmEmail, setDeleteConfirmEmail] = useState("")
  const [deleteError, setDeleteError] = useState<string | null>(null)

  const invalidate = () =>
    queryClient.invalidateQueries({
      queryKey: ["super-admin-users"],
    })

  const applyFilters = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    setFilters({
      page: 1,
      per_page: 20,
      q: searchDraft.trim() || undefined,
      role: roleFilter !== ALL_FILTER_VALUE ? roleFilter : undefined,
      status: statusFilter !== ALL_FILTER_VALUE ? statusFilter : undefined,
      college: collegeFilter !== ALL_FILTER_VALUE ? collegeFilter : undefined,
      pending_setup:
        setupFilter === "pending"
          ? true
          : setupFilter === "complete"
            ? false
            : undefined,
    })
  }

  const resetFilters = () => {
    setSearchDraft("")
    setRoleFilter(ALL_FILTER_VALUE)
    setStatusFilter(ALL_FILTER_VALUE)
    setCollegeFilter(ALL_FILTER_VALUE)
    setSetupFilter(ALL_FILTER_VALUE)
    setFilters({ page: 1, per_page: 20 })
  }

  // Mutations
  const inviteMutation = useMutation({
    mutationFn: async () => {
      const isCollegeRequired = ["faculty", "program_chair", "dean"].includes(
        inviteRole,
      )
      return inviteUserAccount({
        email: inviteEmail.trim(),
        role: inviteRole,
        college: isCollegeRequired ? (inviteCollege as CollegeCode) : null,
      })
    },
    onSuccess: (data) => {
      toast.success(`Invitation sent to ${data.data.email}`)
      setIsInviteOpen(false)
      setInviteEmail("")
      setInviteRole("faculty")
      setInviteCollege("ccs")
      setInviteError(null)
      void invalidate()
    },
    onError: (err) => {
      setInviteError(
        getErrorMessage(err, "Failed to send invitation. Check fields."),
      )
    },
  })

  const changeRoleMutation = useMutation({
    mutationFn: async () => {
      if (!roleChangeTarget) return
      const isCollegeRequired = ["faculty", "program_chair", "dean"].includes(
        newRole,
      )
      return changeUserRole(roleChangeTarget.id, {
        role: newRole,
        college: isCollegeRequired ? (newRoleCollege as CollegeCode) : null,
        reason: roleChangeReason.trim(),
      })
    },
    onSuccess: (data) => {
      if (!data) return
      toast.success(
        `Role changed for ${data.data.name} to ${data.data.role_label}`,
      )
      setRoleChangeTarget(null)
      setRoleChangeReason("")
      setRoleChangeError(null)
      void invalidate()
    },
    onError: (err) => {
      setRoleChangeError(
        getErrorMessage(err, "Failed to change user role. Please try again."),
      )
    },
  })

  const updateStatusMutation = useMutation({
    mutationFn: async () => {
      if (!statusChangeTarget) return
      const nextStatus =
        statusChangeTarget.status === "active" ? "disabled" : "active"
      return setUserAccountStatus(statusChangeTarget.id, {
        status: nextStatus,
        reason: statusChangeReason.trim(),
      })
    },
    onSuccess: (data) => {
      if (!data) return
      toast.success(
        `Account status for ${data.data.name} updated to ${data.data.status_label}`,
      )
      setStatusChangeTarget(null)
      setStatusChangeReason("")
      setStatusChangeError(null)
      void invalidate()
    },
    onError: (err) => {
      setStatusChangeError(
        getErrorMessage(err, "Failed to update account status."),
      )
    },
  })

  const resendInvitationMutation = useMutation({
    mutationFn: (userId: number) => resendSetupInvitation(userId),
    onSuccess: (data) => {
      toast.success(`Setup invitation resent to ${data.data.email}`)
      void invalidate()
    },
    onError: (err) => {
      toast.error(
        getErrorMessage(err, "Failed to resend setup invitation."),
      )
    },
  })

  const passwordResetMutation = useMutation({
    mutationFn: (userId: number) => sendPasswordReset(userId),
    onSuccess: () => {
      toast.success("Password reset code sent successfully.")
      void invalidate()
    },
    onError: (err) => {
      toast.error(getErrorMessage(err, "Failed to send password reset code."))
    },
  })

  const revokeSessionsMutation = useMutation({
    mutationFn: (userId: number) => revokeUserSessions(userId),
    onSuccess: () => {
      toast.success("All active sessions revoked for this account.")
      void invalidate()
    },
    onError: (err) => {
      toast.error(getErrorMessage(err, "Failed to revoke active sessions."))
    },
  })

  const deleteUserMutation = useMutation({
    mutationFn: async () => {
      if (!deleteTarget) return
      return deleteUserAccount(deleteTarget.id)
    },
    onSuccess: () => {
      toast.success("User account permanently deleted.")
      setDeleteTarget(null)
      setDeleteConfirmEmail("")
      setDeleteError(null)
      void invalidate()
    },
    onError: (err) => {
      const msg = getErrorMessage(
        err,
        "Failed to delete account. Deactivate instead if account has history.",
      )
      setDeleteError(msg)
      toast.error(msg)
    },
  })

  const isInviteCollegeRequired = ["faculty", "program_chair", "dean"].includes(
    inviteRole,
  )
  const isNewRoleCollegeRequired = [
    "faculty",
    "program_chair",
    "dean",
  ].includes(newRole)

  return (
    <WorkspacePage
      title="Accounts & Access"
      description="Manage institutional user accounts, roles, access statuses, and active sessions."
      unauthorized={!authorized}
      lastUpdated={usersQuery.dataUpdatedAt}
    >
      <div className="flex flex-col gap-6">
        {/* Top actions & filters */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <Button
            type="button"
            onClick={() => {
              setIsInviteOpen(true)
              setInviteError(null)
            }}
          >
            <Plus className="mr-2 h-4 w-4" />
            Invite Staff Account
          </Button>
        </div>

        {/* Filter Card */}
        <Card>
          <CardHeader>
            <CardTitle>Filter User Accounts</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={applyFilters} className="space-y-4">
              <FieldGroup className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                <Field>
                  <FieldLabel htmlFor="account-search">Search</FieldLabel>
                  <Input
                    id="account-search"
                    placeholder="Name or email..."
                    value={searchDraft}
                    onChange={(e) => setSearchDraft(e.target.value)}
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="account-role-filter">Role</FieldLabel>
                  <Select
                    value={roleFilter}
                    onValueChange={setRoleFilter}
                  >
                    <SelectTrigger id="account-role-filter">
                      <SelectValue placeholder="All Roles" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ALL_FILTER_VALUE}>All Roles</SelectItem>
                      {ROLE_OPTIONS.map((opt) => (
                        <SelectItem key={opt.value} value={opt.value}>
                          {opt.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>

                <Field>
                  <FieldLabel htmlFor="account-status-filter">Status</FieldLabel>
                  <Select
                    value={statusFilter}
                    onValueChange={setStatusFilter}
                  >
                    <SelectTrigger id="account-status-filter">
                      <SelectValue placeholder="All Statuses" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ALL_FILTER_VALUE}>All Statuses</SelectItem>
                      <SelectItem value="active">Active</SelectItem>
                      <SelectItem value="disabled">Disabled</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>

                <Field>
                  <FieldLabel htmlFor="account-college-filter">College</FieldLabel>
                  <Select
                    value={collegeFilter}
                    onValueChange={setCollegeFilter}
                  >
                    <SelectTrigger id="account-college-filter">
                      <SelectValue placeholder="All Colleges" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ALL_FILTER_VALUE}>All Colleges</SelectItem>
                      {COLLEGE_OPTIONS.map((col) => (
                        <SelectItem key={col.value} value={col.value}>
                          {col.value.toUpperCase()}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Field>

                <Field>
                  <FieldLabel htmlFor="account-setup-filter">Setup</FieldLabel>
                  <Select
                    value={setupFilter}
                    onValueChange={setSetupFilter}
                  >
                    <SelectTrigger id="account-setup-filter">
                      <SelectValue placeholder="All Setup Statuses" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ALL_FILTER_VALUE}>All</SelectItem>
                      <SelectItem value="pending">Pending Setup Only</SelectItem>
                      <SelectItem value="complete">Setup Completed</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
              </FieldGroup>

              <div className="flex items-center gap-2">
                <Button type="submit" variant="default" size="sm">
                  Apply Filters
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={resetFilters}
                >
                  Reset
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>

        {/* User Accounts Table */}
        <AsyncBoundary query={usersQuery}>
          {(usersData) => (
            <div className="space-y-4">
              <div className="rounded-md border bg-card">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead scope="col">User</TableHead>
                      <TableHead scope="col">Role</TableHead>
                      <TableHead scope="col">College</TableHead>
                      <TableHead scope="col">Status</TableHead>
                      <TableHead scope="col">Setup</TableHead>
                      <TableHead scope="col">Sessions</TableHead>
                      <TableHead scope="col" className="text-right">
                        Actions
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {usersData.data.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={7} className="h-24 text-center">
                          No user accounts found matching the criteria.
                        </TableCell>
                      </TableRow>
                    ) : (
                      usersData.data.map((user) => (
                        <TableRow key={user.id}>
                          <TableCell>
                            <div className="flex flex-col">
                              <span className="font-medium text-foreground">
                                {user.name}
                              </span>
                              <span className="text-xs text-muted-foreground">
                                {user.email}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell>{user.role_label}</TableCell>
                          <TableCell>
                            {user.college_label ? (
                              <span>{user.college_label}</span>
                            ) : (
                              <span className="text-muted-foreground">—</span>
                            )}
                          </TableCell>
                          <TableCell>
                            <Badge
                              variant={
                                user.status === "active" ? "default" : "secondary"
                              }
                            >
                              {user.status_label}
                            </Badge>
                          </TableCell>
                          <TableCell>
                            {user.pending_setup ? (
                              <Badge variant="outline" className="border-amber-500 text-amber-600 dark:text-amber-400">
                                Pending Setup
                              </Badge>
                            ) : (
                              <span className="text-xs text-muted-foreground">
                                Completed
                              </span>
                            )}
                          </TableCell>
                          <TableCell>
                            <span className="text-xs font-mono">
                              {user.active_session_count}
                            </span>
                          </TableCell>
                          <TableCell className="text-right">
                            {user.manageable ? (
                              <div className="flex flex-wrap items-center justify-end gap-1">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  title="Change Role"
                                  onClick={() => {
                                    setRoleChangeTarget(user)
                                    const matchingRole =
                                      superAdminInvitableRoles.find(
                                        (r) => r === user.role,
                                      ) ?? "faculty"
                                    setNewRole(matchingRole)
                                    const matchingCol = collegeCodes.find(
                                      (c) => c === user.college,
                                    )
                                    setNewRoleCollege(matchingCol ?? "ccs")
                                    setRoleChangeReason("")
                                    setRoleChangeError(null)
                                  }}
                                >
                                  <UserCog className="h-4 w-4" />
                                  <span className="sr-only">Change Role</span>
                                </Button>

                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  title={
                                    user.status === "active"
                                      ? "Deactivate Account"
                                      : "Reactivate Account"
                                  }
                                  onClick={() => {
                                    setStatusChangeTarget(user)
                                    setStatusChangeReason("")
                                    setStatusChangeError(null)
                                  }}
                                >
                                  {user.status === "active" ? (
                                    <UserX className="h-4 w-4 text-destructive" />
                                  ) : (
                                    <UserCheck className="h-4 w-4 text-emerald-600" />
                                  )}
                                  <span className="sr-only">
                                    {user.status === "active"
                                      ? "Deactivate"
                                      : "Reactivate"}
                                  </span>
                                </Button>

                                {user.pending_setup && (
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    title="Resend Setup Invitation"
                                    disabled={resendInvitationMutation.isPending}
                                    onClick={() =>
                                      resendInvitationMutation.mutate(user.id)
                                    }
                                  >
                                    <RotateCcw className="h-4 w-4" />
                                    <span className="sr-only">
                                      Resend Setup
                                    </span>
                                  </Button>
                                )}

                                {user.status === "active" &&
                                  !user.pending_setup && (
                                    <Button
                                      type="button"
                                      variant="ghost"
                                      size="sm"
                                      title="Send Password Reset Code"
                                      disabled={passwordResetMutation.isPending}
                                      onClick={() =>
                                        passwordResetMutation.mutate(user.id)
                                      }
                                    >
                                      <KeyRound className="h-4 w-4" />
                                      <span className="sr-only">
                                        Password Reset
                                      </span>
                                    </Button>
                                  )}

                                {user.active_session_count > 0 && (
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    title="Revoke Active Sessions"
                                    disabled={revokeSessionsMutation.isPending}
                                    onClick={() =>
                                      revokeSessionsMutation.mutate(user.id)
                                    }
                                  >
                                    <LogOut className="h-4 w-4 text-amber-600" />
                                    <span className="sr-only">
                                      Revoke Sessions
                                    </span>
                                  </Button>
                                )}

                                {user.pending_setup && (
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    title="Delete Unused Account"
                                    onClick={() => {
                                      setDeleteTarget(user)
                                      setDeleteConfirmEmail("")
                                      setDeleteError(null)
                                    }}
                                  >
                                    <Trash2 className="h-4 w-4 text-destructive" />
                                    <span className="sr-only">Delete</span>
                                  </Button>
                                )}
                              </div>
                            ) : (
                              <span className="text-xs text-muted-foreground">
                                System Protected
                              </span>
                            )}
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </div>

              {/* Pagination */}
              {usersData.meta.last_page > 1 && (
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted-foreground">
                    Showing {usersData.meta.from ?? 0} to{" "}
                    {usersData.meta.to ?? 0} of{" "}
                    {usersData.meta.total} users
                  </span>
                  <Paginator
                    currentPage={usersData.meta.current_page}
                    lastPage={usersData.meta.last_page}
                    onPageChange={(page) =>
                      setFilters((prev) => ({ ...prev, page }))
                    }
                  />
                </div>
              )}
            </div>
          )}
        </AsyncBoundary>
      </div>

      {/* Invite Dialog */}
      <Dialog open={isInviteOpen} onOpenChange={setIsInviteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Invite Staff Account</DialogTitle>
            <DialogDescription>
              Create an invitation for a staff member. An email setup link will
              be dispatched.
            </DialogDescription>
          </DialogHeader>

          {inviteError && (
            <Alert variant="destructive">
              <AlertDescription>{inviteError}</AlertDescription>
            </Alert>
          )}

          <form
            onSubmit={(e) => {
              e.preventDefault()
              inviteMutation.mutate()
            }}
            className="space-y-4"
          >
            <Field>
              <FieldLabel htmlFor="invite-email">Institutional Email</FieldLabel>
              <Input
                id="invite-email"
                type="email"
                required
                placeholder="staff@grc.edu.ph"
                value={inviteEmail}
                onChange={(e) => setInviteEmail(e.target.value)}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="invite-role">Assigned Role</FieldLabel>
              <Select
                value={inviteRole}
                onValueChange={(val) =>
                  setInviteRole(val as SuperAdminInvitableRole)
                }
              >
                <SelectTrigger id="invite-role">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ROLE_OPTIONS.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value}>
                      {opt.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            {isInviteCollegeRequired && (
              <Field>
                <FieldLabel htmlFor="invite-college">Assigned College</FieldLabel>
                <Select
                  value={inviteCollege}
                  onValueChange={(val) =>
                    setInviteCollege(val as CollegeCode)
                  }
                >
                  <SelectTrigger id="invite-college">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {COLLEGE_OPTIONS.map((col) => (
                      <SelectItem key={col.value} value={col.value}>
                        {col.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            )}

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsInviteOpen(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={inviteMutation.isPending || !inviteEmail.trim()}
              >
                {inviteMutation.isPending ? "Sending..." : "Send Invitation"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Change Role Dialog */}
      <Dialog
        open={roleChangeTarget !== null}
        onOpenChange={(open) => {
          if (!open) setRoleChangeTarget(null)
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Change User Role</DialogTitle>
            <DialogDescription>
              Assign a new role to {roleChangeTarget?.name} ({roleChangeTarget?.email}).
              Any active sessions will be invalidated.
            </DialogDescription>
          </DialogHeader>

          {roleChangeError && (
            <Alert variant="destructive">
              <AlertDescription>{roleChangeError}</AlertDescription>
            </Alert>
          )}

          <form
            onSubmit={(e) => {
              e.preventDefault()
              changeRoleMutation.mutate()
            }}
            className="space-y-4"
          >
            <Field>
              <FieldLabel htmlFor="change-role-select">New Role</FieldLabel>
              <Select
                value={newRole}
                onValueChange={(val) =>
                  setNewRole(val as SuperAdminInvitableRole)
                }
              >
                <SelectTrigger id="change-role-select">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ROLE_OPTIONS.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value}>
                      {opt.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            {isNewRoleCollegeRequired && (
              <Field>
                <FieldLabel htmlFor="change-role-college">College</FieldLabel>
                <Select
                  value={newRoleCollege}
                  onValueChange={(val) =>
                    setNewRoleCollege(val as CollegeCode)
                  }
                >
                  <SelectTrigger id="change-role-college">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {COLLEGE_OPTIONS.map((col) => (
                      <SelectItem key={col.value} value={col.value}>
                        {col.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            )}

            <Field>
              <FieldLabel htmlFor="change-role-reason">
                Reason for Change (Required)
              </FieldLabel>
              <Textarea
                id="change-role-reason"
                required
                placeholder="Institutional realignment, promotion, etc."
                value={roleChangeReason}
                onChange={(e) => setRoleChangeReason(e.target.value)}
              />
            </Field>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setRoleChangeTarget(null)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={
                  changeRoleMutation.isPending || !roleChangeReason.trim()
                }
              >
                {changeRoleMutation.isPending ? "Updating..." : "Save Role"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Change Status Dialog */}
      <Dialog
        open={statusChangeTarget !== null}
        onOpenChange={(open) => {
          if (!open) setStatusChangeTarget(null)
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {statusChangeTarget?.status === "active"
                ? "Deactivate Account"
                : "Reactivate Account"}
            </DialogTitle>
            <DialogDescription>
              {statusChangeTarget?.status === "active"
                ? `Deactivating ${statusChangeTarget?.name} will revoke all active sessions immediately.`
                : `Reactivate access for ${statusChangeTarget?.name}.`}
            </DialogDescription>
          </DialogHeader>

          {statusChangeError && (
            <Alert variant="destructive">
              <AlertDescription>{statusChangeError}</AlertDescription>
            </Alert>
          )}

          <form
            onSubmit={(e) => {
              e.preventDefault()
              updateStatusMutation.mutate()
            }}
            className="space-y-4"
          >
            <Field>
              <FieldLabel htmlFor="change-status-reason">
                Reason for {statusChangeTarget?.status === "active" ? "Deactivation" : "Reactivation"} (Required)
              </FieldLabel>
              <Textarea
                id="change-status-reason"
                required
                placeholder="Specify the reason..."
                value={statusChangeReason}
                onChange={(e) => setStatusChangeReason(e.target.value)}
              />
            </Field>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setStatusChangeTarget(null)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                variant={
                  statusChangeTarget?.status === "active"
                    ? "destructive"
                    : "default"
                }
                disabled={
                  updateStatusMutation.isPending || !statusChangeReason.trim()
                }
              >
                {updateStatusMutation.isPending
                  ? "Saving..."
                  : statusChangeTarget?.status === "active"
                    ? "Deactivate Account"
                    : "Reactivate Account"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Permanent Delete AlertDialog */}
      <AlertDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => {
          if (!open) {
            setDeleteTarget(null)
            setDeleteConfirmEmail("")
          }
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle className="flex items-center gap-2 text-destructive">
              <ShieldAlert className="h-5 w-5" />
              Permanently Delete Account
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="space-y-2 text-sm text-muted-foreground">
                <p>
                  This action is permanent and completely erases this unused account.
                  Accounts that have ever completed setup or generated activity cannot be deleted and must be deactivated instead.
                </p>
                <p className="font-semibold text-foreground">
                  To confirm deletion, please type the account email address below:
                </p>
                <p className="font-mono text-xs text-muted-foreground select-all">
                  {deleteTarget?.email}
                </p>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>

          {deleteError && (
            <Alert variant="destructive">
              <AlertDescription>{deleteError}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-2 py-2">
            <Input
              id="confirm-delete-email"
              placeholder={deleteTarget?.email}
              value={deleteConfirmEmail}
              onChange={(e) => setDeleteConfirmEmail(e.target.value)}
            />
          </div>

          <AlertDialogFooter>
            <AlertDialogCancel
              disabled={deleteUserMutation.isPending}
              onClick={() => {
                setDeleteTarget(null)
                setDeleteConfirmEmail("")
              }}
            >
              Cancel
            </AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={
                deleteUserMutation.isPending ||
                deleteConfirmEmail.trim() !== deleteTarget?.email
              }
              onClick={(e) => {
                e.preventDefault()
                deleteUserMutation.mutate()
              }}
            >
              {deleteUserMutation.isPending ? "Deleting..." : "Permanently Delete"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </WorkspacePage>
  )
}
