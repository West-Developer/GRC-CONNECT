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
import { useItControlStudentAccountsQuery } from "@/features/hooks/use-it-control-accounts"
import {
  studentAccountFilterFormSchema,
  type ItControlStudentAccount,
  type StudentAccountFilterForm,
  type StudentAccountFilters,
} from "@/features/schemas/it-control-schema"

const ALL_FILTER_VALUE = "all"
const defaults: StudentAccountFilters = { page: 1, per_page: 20 }
const collegeOptions = [
  ["ccs", "College of Computer Studies"],
  ["coe", "College of Education"],
  ["coa", "College of Accountancy"],
  ["cbae", "College of Business Administration and Entrepreneurship"],
] as const
const yearLevelOptions = [
  ["1", "1st Year"],
  ["2", "2nd Year"],
  ["3", "3rd Year"],
  ["4", "4th Year"],
] as const

function enrollmentStatusLabel(status: string | null) {
  if (status === null) return "No current enrollment"

  const label = status.replaceAll("_", " ")

  return label[0].toUpperCase() + label.slice(1)
}

export function ItControlStudentsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "it_admin"
  const [filters, setFilters] = useState<StudentAccountFilters>(defaults)
  const form = useForm<StudentAccountFilterForm>({
    resolver: zodResolver(studentAccountFilterFormSchema),
    defaultValues: { q: "" },
  })
  const accountsQuery = useItControlStudentAccountsQuery(filters, authorized)
  const columns: DataTableColumn<ItControlStudentAccount>[] = [
    {
      key: "student-number",
      header: "Student number",
      render: (row) => row.student_number,
    },
    { key: "name", header: "Name", render: (row) => row.name },
    {
      key: "email",
      header: "Email",
      render: (row) => <ItControlEmailCell name={row.name} email={row.email} />,
    },
    {
      key: "program",
      header: "Program",
      render: (row) => row.program_code,
    },
    {
      key: "year",
      header: "Year",
      render: (row) => String(row.year_level),
    },
    {
      key: "category",
      header: "Category",
      render: (row) => row.enrollment_category ?? "Not recorded",
    },
    {
      key: "status",
      header: "Account status",
      render: (row) => (row.is_archived ? "Archived" : row.status),
    },
    {
      key: "current-enrollment",
      header: "Current enrollment",
      render: (row) =>
        enrollmentStatusLabel(row.current_term_enrollment_status),
    },
    {
      key: "actions",
      header: "Actions",
      render: (row) => (
        <ItControlAccountActions
          account={{
            id: row.user_id,
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

  const apply = (values: StudentAccountFilterForm) => {
    setFilters({
      q: values.q.trim() || undefined,
      college: values.college,
      year_level: values.year_level,
      enrollment_category: values.enrollment_category,
      status: values.status,
      page: 1,
      per_page: 20,
    })
  }

  return (
    <WorkspacePage
      title="IT Control student accounts"
      description="Find student accounts, copy a test login email, and edit, archive or delete an account. Archive an account that has records; delete only one that was never used."
      unauthorized={!authorized}
      lastUpdated={accountsQuery.dataUpdatedAt}
    >
      <form onSubmit={(event) => void form.handleSubmit(apply)(event)}>
        <FieldGroup className="grid gap-3 md:grid-cols-3">
          <Field>
            <FieldLabel htmlFor="it-control-student-search">
              Search student accounts
            </FieldLabel>
            <Input id="it-control-student-search" {...form.register("q")} />
          </Field>
          <Field>
            <FieldLabel htmlFor="it-control-student-college">
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
                    id="it-control-student-college"
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
            <FieldLabel htmlFor="it-control-student-year">
              Year level
            </FieldLabel>
            <Controller
              control={form.control}
              name="year_level"
              render={({ field }) => (
                <Select
                  value={
                    field.value === undefined
                      ? ALL_FILTER_VALUE
                      : String(field.value)
                  }
                  onValueChange={(value) =>
                    field.onChange(
                      value === ALL_FILTER_VALUE ? undefined : Number(value),
                    )
                  }
                >
                  <SelectTrigger
                    id="it-control-student-year"
                    className="w-full"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL_FILTER_VALUE}>
                      All year levels
                    </SelectItem>
                    {yearLevelOptions.map(([value, label]) => (
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
            <FieldLabel htmlFor="it-control-student-category">
              Enrollment category
            </FieldLabel>
            <Controller
              control={form.control}
              name="enrollment_category"
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
                    id="it-control-student-category"
                    className="w-full"
                  >
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL_FILTER_VALUE}>
                      All categories
                    </SelectItem>
                    <SelectItem value="regular">Regular</SelectItem>
                    <SelectItem value="irregular">Irregular</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="it-control-student-status">
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
                    id="it-control-student-status"
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
            Apply student filters
          </Button>
        </FieldGroup>
      </form>
      <AsyncBoundary
        query={accountsQuery}
        isEmpty={(accounts) => accounts.data.length === 0}
        emptyMessage="No student accounts match these filters."
        loadingLabel="Loading student accounts…"
      >
        {(accounts) => (
          <Card>
            <CardHeader>
              <CardTitle level={2}>Student account browser</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable
                caption="IT Control student accounts"
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
