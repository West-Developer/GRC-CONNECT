"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { Controller, useForm } from "react-hook-form"
import { useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import {
  DataTable,
  type DataTableColumn,
} from "@/features/components/portal/data-table"
import { ItControlAccountActions } from "@/features/components/portal/it-control-account-actions"
import { ItControlEmailCell } from "@/features/components/portal/it-control-email-cell"
import { Paginator } from "@/features/components/portal/paginator"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { Field, FieldGroup, FieldLabel } from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/features/components/ui/select"
import { useItControlFacultyAccountsQuery } from "@/features/hooks/use-it-control-accounts"
import {
  facultyAccountFilterFormSchema,
  type FacultyAccountFilterForm,
  type FacultyAccountFilters,
  type ItControlFacultyAccount,
} from "@/features/schemas/it-control-schema"

const ALL_FILTER_VALUE = "all"
const defaults: FacultyAccountFilters = { page: 1, per_page: 20 }
const collegeOptions = [
  ["ccs", "College of Computer Studies"],
  ["coe", "College of Education"],
  ["coa", "College of Accountancy"],
  ["cbae", "College of Business Administration and Entrepreneurship"],
] as const

export function ItControlFacultyWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "it_admin"
  const [filters, setFilters] = useState<FacultyAccountFilters>(defaults)
  const form = useForm<FacultyAccountFilterForm>({
    resolver: zodResolver(facultyAccountFilterFormSchema),
    defaultValues: { q: "" },
  })
  const accountsQuery = useItControlFacultyAccountsQuery(filters, authorized)
  const columns: DataTableColumn<ItControlFacultyAccount>[] = [
    { key: "name", header: "Name", render: (row) => row.name },
    {
      key: "email",
      header: "Email",
      render: (row) => <ItControlEmailCell name={row.name} email={row.email} />,
    },
    {
      key: "college",
      header: "College",
      render: (row) => row.college ?? "Unassigned",
    },
    {
      key: "employment",
      header: "Employment",
      render: (row) => row.employment_type ?? "Not recorded",
    },
    {
      key: "status",
      header: "Account status",
      render: (row) => (row.is_archived ? "Archived" : row.status),
    },
    {
      key: "availability",
      header: "Availability windows",
      render: (row) => String(row.availability_window_count),
    },
    {
      key: "preferences",
      header: "Subject preferences",
      render: (row) => String(row.subject_preference_count),
    },
    {
      key: "specializations",
      header: "Specializations",
      render: (row) => String(row.specialization_count),
    },
    {
      key: "actions",
      header: "Actions",
      render: (row) => (
        <ItControlAccountActions
          account={{
            id: row.id,
            name: row.name,
            firstName: row.first_name,
            lastName: row.last_name,
            suffix: row.suffix,
            email: row.email,
            isArchived: row.is_archived,
          }}
        />
      ),
    },
  ]

  const apply = (values: FacultyAccountFilterForm) => {
    setFilters({
      q: values.q.trim() || undefined,
      college: values.college,
      employment_type: values.employment_type,
      status: values.status,
      page: 1,
      per_page: 20,
    })
  }

  return (
    <WorkspacePage
      title="IT Control faculty accounts"
      description="Find faculty accounts, copy a test login email, and edit, archive or delete an account. Archive an account that has records; delete only one that was never used."
      unauthorized={!authorized}
      lastUpdated={accountsQuery.dataUpdatedAt}
    >
      <form onSubmit={(event) => void form.handleSubmit(apply)(event)}>
        <FieldGroup className="grid gap-3 md:grid-cols-3">
          <Field>
            <FieldLabel htmlFor="it-control-faculty-search">
              Search faculty accounts
            </FieldLabel>
            <Input id="it-control-faculty-search" {...form.register("q")} />
          </Field>
          <Field>
            <FieldLabel htmlFor="it-control-faculty-college">
              College
            </FieldLabel>
            <Controller
              control={form.control}
              name="college"
              render={({ field }) => (
                <Select
                  value={field.value ?? ALL_FILTER_VALUE}
                  onValueChange={(value) =>
                    field.onChange(
                      value === ALL_FILTER_VALUE ? undefined : value,
                    )
                  }
                >
                  <SelectTrigger
                    id="it-control-faculty-college"
                    className="w-full"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL_FILTER_VALUE}>
                      All colleges
                    </SelectItem>
                    {collegeOptions.map(([value, label]) => (
                      <SelectItem key={value} value={value}>
                        {label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="it-control-faculty-employment">
              Employment type
            </FieldLabel>
            <Controller
              control={form.control}
              name="employment_type"
              render={({ field }) => (
                <Select
                  value={field.value ?? ALL_FILTER_VALUE}
                  onValueChange={(value) =>
                    field.onChange(
                      value === ALL_FILTER_VALUE ? undefined : value,
                    )
                  }
                >
                  <SelectTrigger
                    id="it-control-faculty-employment"
                    className="w-full"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL_FILTER_VALUE}>
                      All employment types
                    </SelectItem>
                    <SelectItem value="full_time">Full-time</SelectItem>
                    <SelectItem value="part_time">Part-time</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="it-control-faculty-status">
              Account status
            </FieldLabel>
            <Controller
              control={form.control}
              name="status"
              render={({ field }) => (
                <Select
                  value={field.value ?? ALL_FILTER_VALUE}
                  onValueChange={(value) =>
                    field.onChange(
                      value === ALL_FILTER_VALUE ? undefined : value,
                    )
                  }
                >
                  <SelectTrigger
                    id="it-control-faculty-status"
                    className="w-full"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL_FILTER_VALUE}>
                      All account statuses
                    </SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="disabled">Inactive</SelectItem>
                    <SelectItem value="archived">Archived</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </Field>
          <Button type="submit" className="self-end">
            Apply faculty filters
          </Button>
        </FieldGroup>
      </form>
      <AsyncBoundary
        query={accountsQuery}
        isEmpty={(accounts) => accounts.data.length === 0}
        emptyMessage="No faculty accounts match these filters."
        loadingLabel="Loading faculty accounts…"
      >
        {(accounts) => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>Faculty account browser</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable
                caption="IT Control faculty accounts"
                columns={columns}
                rows={accounts.data}
                rowKey={(row) => row.id}
              />
              <div className="mt-4">
                <Paginator
                  currentPage={accounts.meta.current_page}
                  lastPage={accounts.meta.last_page}
                  onPageChange={(page) =>
                    setFilters((current) => ({ ...current, page }))
                  }
                />
              </div>
            </CardContent>
          </Card>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
