"use client"

import { useMemo, useState } from "react"
import { Calculator, CheckCircle2, Plus, Receipt, Trash2 } from "lucide-react"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
import { Alert, AlertDescription, AlertTitle } from "@/features/components/ui/alert"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
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
import {
  useFeeSchedulesQuery,
  useUpdateFeeSchedulesMutation,
} from "@/features/hooks/use-fee-schedules"
import { useProgramsQuery } from "@/features/hooks/use-reference-data"

interface EditableMiscFee {
  id?: number
  label: string
  amount: string
  program_code: string // "ALL" or program code like "BSIT"
  semester: string // "ALL", "1st", or "2nd"
  is_active: boolean
}

export function FeeSettingsWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "accounting_staff"
  const feeQuery = useFeeSchedulesQuery(authorized)
  const programsQuery = useProgramsQuery({ enabled: authorized })
  const updateMutation = useUpdateFeeSchedulesMutation()

  const [tuitionRate, setTuitionRate] = useState<string>("200.00")
  const [miscFees, setMiscFees] = useState<EditableMiscFee[]>([])
  const [saveSuccess, setSaveSuccess] = useState(false)

  // Initialize form state when (new) fee data arrives — adjusted during
  // render rather than in an effect, which committed the defaults first.
  // `undefined` start (not `feeQuery.data`) so cached data present at mount
  // still initializes the form.
  const [initializedFrom, setInitializedFrom] =
    useState<typeof feeQuery.data>(undefined)
  if (feeQuery.data && initializedFrom !== feeQuery.data) {
    setInitializedFrom(feeQuery.data)

    const tuition = feeQuery.data.find((f) => f.category === "tuition")
    if (tuition) {
      setTuitionRate(tuition.amount)
    }

    const misc = feeQuery.data
      .filter((f) => f.category === "miscellaneous")
      .map((f) => ({
        id: f.id,
        label: f.label,
        amount: f.amount,
        program_code: f.program_codes && f.program_codes.length > 0 ? f.program_codes[0] : "ALL",
        semester: f.semester ?? "ALL",
        is_active: f.is_active,
      }))
    setMiscFees(misc)
  }

  const totalOtherFeesAll = useMemo(() => {
    return miscFees
      .filter((f) => f.is_active && f.program_code === "ALL")
      .reduce((sum, f) => sum + (parseFloat(f.amount) || 0), 0)
  }, [miscFees])

  const handleAddMiscFee = () => {
    setMiscFees((prev) => [
      ...prev,
      {
        label: "New Fee Particular",
        amount: "0.00",
        program_code: "ALL",
        semester: "ALL",
        is_active: true,
      },
    ])
    setSaveSuccess(false)
  }

  const handleRemoveMiscFee = (index: number) => {
    setMiscFees((prev) => prev.filter((_, i) => i !== index))
    setSaveSuccess(false)
  }

  const handleUpdateMiscFee = (
    index: number,
    field: keyof EditableMiscFee,
    value: string | boolean,
  ) => {
    setMiscFees((prev) =>
      prev.map((fee, i) => (i === index ? { ...fee, [field]: value } : fee)),
    )
    setSaveSuccess(false)
  }

  const handleSave = () => {
    setSaveSuccess(false)
    updateMutation.mutate(
      {
        tuition_rate_per_unit: tuitionRate,
        miscellaneous_fees: miscFees.map((f, index) => ({
          id: f.id,
          label: f.label.trim(),
          amount: (parseFloat(f.amount) || 0).toFixed(2),
          program_codes: f.program_code === "ALL" ? null : [f.program_code],
          semester: f.semester === "1st" || f.semester === "2nd" ? f.semester : null,
          is_active: f.is_active,
          sort_order: index + 2,
        })),
      },
      {
        onSuccess: () => {
          setSaveSuccess(true)
          setTimeout(() => setSaveSuccess(false), 5000)
        },
      },
    )
  }

  return (
    <WorkspacePage
      title="Fee Settings"
      description="Configure tuition fee rates and miscellaneous fee particulars that appear on students' Certificates of Registration (COR)."
      unauthorized={!authorized}
      lastUpdated={feeQuery.dataUpdatedAt}
    >
      <AsyncBoundary query={feeQuery} loadingLabel="Loading fee schedule configuration…">
        {() => (
          <div className="grid gap-6">
            {saveSuccess && (
              <Alert className="border-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" />
                <AlertTitle>Settings Saved Successfully</AlertTitle>
                <AlertDescription>
                  Tuition rate and other fees particulars have been updated. All new and re-generated student COR assessments will use these amounts.
                </AlertDescription>
              </Alert>
            )}

            {updateMutation.isError && (
              <Alert variant="destructive">
                <AlertTitle>Failed to Save Fee Settings</AlertTitle>
                <AlertDescription>
                  {updateMutation.error instanceof Error
                    ? updateMutation.error.message
                    : "An unexpected error occurred while saving fee settings."}
                </AlertDescription>
              </Alert>
            )}

            {/* Tuition Rate Card */}
            <Card>
              <CardHeader>
                <div className="flex items-center gap-2">
                  <Calculator className="size-5 text-primary" />
                  <CardTitle level={2}>Tuition Fee Assessment Setup</CardTitle>
                </div>
                <CardDescription>
                  Set the standard cost per academic unit. The student&apos;s total tuition fee will automatically be computed as (Enrolled Units × Rate Per Unit).
                </CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                  <label htmlFor="tuition-rate-input" className="text-sm font-medium">
                    Tuition Rate Per Academic Unit (₱)
                  </label>
                  <div className="flex items-center gap-2">
                    <span className="text-lg font-semibold text-muted-foreground">₱</span>
                    <Input
                      id="tuition-rate-input"
                      type="number"
                      step="0.50"
                      min="0"
                      value={tuitionRate}
                      onChange={(e) => {
                        setTuitionRate(e.target.value)
                        setSaveSuccess(false)
                      }}
                      className="max-w-[200px] font-mono text-lg font-semibold"
                    />
                    <span className="text-sm text-muted-foreground">/ unit</span>
                  </div>
                </div>

                <div className="rounded-lg border bg-muted/20 p-4 text-sm">
                  <p className="font-medium text-foreground">Assessment Calculation Preview:</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    • 13.5 Units × ₱{parseFloat(tuitionRate) || 0} = <strong className="text-foreground">₱{((parseFloat(tuitionRate) || 0) * 13.5).toLocaleString("en-PH", { minimumFractionDigits: 2 })}</strong>
                  </p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    • 20.0 Units × ₱{parseFloat(tuitionRate) || 0} = <strong className="text-foreground">₱{((parseFloat(tuitionRate) || 0) * 20.0).toLocaleString("en-PH", { minimumFractionDigits: 2 })}</strong>
                  </p>
                </div>
              </CardContent>
            </Card>

            {/* Miscellaneous & Other Fees Card */}
            <Card>
              <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-2">
                    <Receipt className="size-5 text-primary" />
                    <div>
                      <CardTitle level={2}>Other Fees &amp; Miscellaneous Particulars</CardTitle>
                      <CardDescription>
                        Set the individual fee particulars and amounts that appear in the &quot;OTHER FEES&quot; section of the official COR.
                      </CardDescription>
                    </div>
                  </div>
                  <Button type="button" size="sm" variant="outline" onClick={handleAddMiscFee} className="gap-1.5">
                    <Plus className="size-4" />
                    Add Fee Particular
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4">
                <div className="overflow-x-auto rounded-lg border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-[35%]">Fee Particular / Label</TableHead>
                        <TableHead className="w-[20%]">Amount (₱)</TableHead>
                        <TableHead className="w-[15%]">Applies To</TableHead>
                        <TableHead className="w-[15%]">Semester</TableHead>
                        <TableHead className="w-[10%] text-right">Action</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {miscFees.map((fee, index) => (
                        <TableRow key={index}>
                          <TableCell>
                            <Input
                              value={fee.label}
                              onChange={(e) => handleUpdateMiscFee(index, "label", e.target.value)}
                              placeholder="e.g. Medical and Dental"
                              className="font-medium"
                            />
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center gap-1.5">
                              <span className="text-sm font-semibold text-muted-foreground">₱</span>
                              <Input
                                type="number"
                                step="10.00"
                                min="0"
                                value={fee.amount}
                                onChange={(e) => handleUpdateMiscFee(index, "amount", e.target.value)}
                                className="font-mono text-sm font-semibold"
                              />
                            </div>
                          </TableCell>
                          <TableCell>
                            <Select
                              value={fee.program_code}
                              onValueChange={(val) => handleUpdateMiscFee(index, "program_code", val)}
                            >
                              <SelectTrigger className="w-full text-xs">
                                <SelectValue placeholder="All Students" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="ALL">All Students</SelectItem>
                                {programsQuery.data?.map((p) => (
                                  <SelectItem key={p.id} value={p.code}>
                                    {p.code} Only
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </TableCell>
                          <TableCell>
                            <Select
                              value={fee.semester}
                              onValueChange={(val) => handleUpdateMiscFee(index, "semester", val)}
                            >
                              <SelectTrigger className="w-full text-xs">
                                <SelectValue placeholder="Every Semester" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="ALL">Every Semester</SelectItem>
                                <SelectItem value="1st">1st Semester Only</SelectItem>
                                <SelectItem value="2nd">2nd Semester Only</SelectItem>
                              </SelectContent>
                            </Select>
                          </TableCell>
                          <TableCell className="text-right">
                            <Button
                              type="button"
                              variant="ghost"
                              size="icon-sm"
                              onClick={() => handleRemoveMiscFee(index)}
                              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            >
                              <Trash2 className="size-4" />
                              <span className="sr-only">Remove fee</span>
                            </Button>
                          </TableCell>
                        </TableRow>
                      ))}
                      {miscFees.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                            No miscellaneous fees configured. Click &quot;Add Fee Particular&quot; above.
                          </TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/20 p-4">
                  <div>
                    <p className="text-sm font-semibold">Standard Other Fees Total:</p>
                    <p className="text-xs text-muted-foreground">
                      Sum of all active general fees charged to students:
                    </p>
                  </div>
                  <Badge variant="secondary" className="px-3 py-1.5 font-mono text-base font-bold tabular-nums">
                    ₱{totalOtherFeesAll.toLocaleString("en-PH", { minimumFractionDigits: 2 })}
                  </Badge>
                </div>
              </CardContent>
            </Card>

            {/* Save Button Bar */}
            <div className="flex justify-end gap-3">
              <Button
                type="button"
                size="lg"
                onClick={handleSave}
                disabled={updateMutation.isPending}
                className="min-w-[180px]"
              >
                {updateMutation.isPending ? "Saving Settings…" : "Save Fee Settings"}
              </Button>
            </div>
          </div>
        )}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
