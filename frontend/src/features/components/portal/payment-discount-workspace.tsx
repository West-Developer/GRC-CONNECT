"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { CheckCircle2, Percent } from "lucide-react"
import { useState } from "react"
import { useForm } from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { AsyncBoundary } from "@/features/components/portal/async-boundary"
import { WorkspacePage } from "@/features/components/portal/workspace-page"
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
import {
  usePaymentDiscountQuery,
  useUpdatePaymentDiscountMutation,
} from "@/features/hooks/use-payment-discount"
import {
  paymentDiscountFormSchema,
  type PaymentDiscountFormValues,
  type PaymentDiscountSetting,
} from "@/features/schemas/payment-discount-schema"

const STATUS_LABEL: Record<PaymentDiscountSetting["status"], string> = {
  active: "Active",
  scheduled: "Scheduled",
  expired: "Expired",
  off: "Off",
}

const STATUS_VARIANT: Record<
  PaymentDiscountSetting["status"],
  "default" | "secondary" | "outline" | "destructive"
> = {
  active: "default",
  scheduled: "secondary",
  expired: "outline",
  off: "outline",
}

function toFormValues(setting: PaymentDiscountSetting): PaymentDiscountFormValues {
  return {
    percentage: setting.percentage,
    valid_from: setting.valid_from ?? "",
    valid_until: setting.valid_until ?? "",
  }
}

function PaymentDiscountForm({ setting }: { setting: PaymentDiscountSetting }) {
  // `useForm` is called directly here and the form is reset after a save: compiled by the React
  // Compiler, the memoized `register()` props would keep stale ref callbacks after `reset`.
  "use no memo"

  const mutation = useUpdatePaymentDiscountMutation()
  const [saved, setSaved] = useState(false)
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isDirty },
  } = useForm<PaymentDiscountFormValues>({
    resolver: zodResolver(paymentDiscountFormSchema),
    defaultValues: toFormValues(setting),
  })

  const submit = handleSubmit((values) => {
    setSaved(false)
    mutation.mutate(
      {
        percentage: values.percentage,
        valid_from: values.valid_from === "" ? null : values.valid_from,
        valid_until: values.valid_until === "" ? null : values.valid_until,
      },
      {
        onSuccess: (updated) => {
          reset(toFormValues(updated))
          setSaved(true)
        },
      },
    )
  })

  return (
    <div className="grid gap-6">
      {saved && (
        <Alert className="border-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
          <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400" />
          <AlertTitle>Discount saved</AlertTitle>
          <AlertDescription>
            New full payments use these settings. Payments and Certificates of
            Registration that already exist are not changed.
          </AlertDescription>
        </Alert>
      )}
      {mutation.isError && (
        <Alert variant="destructive">
          <AlertTitle>The discount could not be saved</AlertTitle>
          <AlertDescription>
            {mutation.error instanceof Error
              ? mutation.error.message
              : "Check the connection and try again."}
          </AlertDescription>
        </Alert>
      )}

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <Percent className="size-5 text-primary" />
              <CardTitle level={2}>Full-payment discount</CardTitle>
            </div>
            <Badge variant={STATUS_VARIANT[setting.status]}>
              {STATUS_LABEL[setting.status]}
            </Badge>
          </div>
          <CardDescription>
            Students who pay their whole assessment in one payment get this
            percentage off tuition and miscellaneous fees. An installment has no
            discount and no extra fee. A scholar does not get it on top of their
            scholarship.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={(event) => void submit(event)} noValidate>
            <FieldGroup>
              <Field data-invalid={errors.percentage !== undefined}>
                <FieldLabel htmlFor="discount-percentage">
                  Discount percentage
                </FieldLabel>
                <Input
                  id="discount-percentage"
                  inputMode="decimal"
                  aria-invalid={errors.percentage !== undefined}
                  className="max-w-[160px] font-mono"
                  {...register("percentage")}
                />
                <FieldDescription>
                  From 0 to 100, with at most one decimal. Enter 0 to turn the
                  discount off.
                </FieldDescription>
                {errors.percentage && (
                  <FieldError>{errors.percentage.message}</FieldError>
                )}
              </Field>

              <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={errors.valid_from !== undefined}>
                  <FieldLabel htmlFor="discount-valid-from">Valid from</FieldLabel>
                  <Input
                    id="discount-valid-from"
                    type="date"
                    aria-invalid={errors.valid_from !== undefined}
                    {...register("valid_from")}
                  />
                  <FieldDescription>
                    Leave empty for no start date. This day is included.
                  </FieldDescription>
                  {errors.valid_from && (
                    <FieldError>{errors.valid_from.message}</FieldError>
                  )}
                </Field>
                <Field data-invalid={errors.valid_until !== undefined}>
                  <FieldLabel htmlFor="discount-valid-until">Valid until</FieldLabel>
                  <Input
                    id="discount-valid-until"
                    type="date"
                    aria-invalid={errors.valid_until !== undefined}
                    {...register("valid_until")}
                  />
                  <FieldDescription>
                    Leave empty for no end date. This day is included.
                  </FieldDescription>
                  {errors.valid_until && (
                    <FieldError>{errors.valid_until.message}</FieldError>
                  )}
                </Field>
              </div>

              <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={!isDirty || mutation.isPending}>
                  {mutation.isPending ? "Saving discount" : "Save discount"}
                </Button>
                {setting.updated_by && setting.updated_at && (
                  <span className="text-xs text-muted-foreground">
                    Last changed by {setting.updated_by}
                  </span>
                )}
              </div>
            </FieldGroup>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}

export function PaymentDiscountWorkspace() {
  const { session } = useAuth()
  const authorized = session?.role === "registrar_head"
  const query = usePaymentDiscountQuery(authorized)

  // The form is deliberately not keyed on the saved values: a key change would remount it after a
  // save and lose its "Discount saved" notice. `reset(updated)` re-seeds it instead.
  return (
    <WorkspacePage
      title="Payment Discount"
      description="Set the discount for students who pay in full, and the dates it is valid. Changes apply to new payments only."
      unauthorized={!authorized}
      lastUpdated={query.dataUpdatedAt}
    >
      <AsyncBoundary query={query} loadingLabel="Loading the payment discount…">
        {(setting) => <PaymentDiscountForm setting={setting} />}
      </AsyncBoundary>
    </WorkspacePage>
  )
}
