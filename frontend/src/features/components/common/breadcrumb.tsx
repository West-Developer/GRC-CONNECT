import Link from "next/link"
import { Fragment } from "react"

export interface BreadcrumbItem {
  label: string
  /** Omit for the current page — it renders as text with aria-current="page", not a link. */
  href?: string
}

/**
 * Replaces the portal topbar's hardcoded `<p>Role workspace / {roleLabel}</p>`
 * with a real breadcrumb landmark (PRD §12.6's "accessible breadcrumb or page
 * context").
 */
export function Breadcrumb({ items }: { items: BreadcrumbItem[] }) {
  return (
    <nav aria-label="Breadcrumb">
      <ol className="flex flex-wrap items-center gap-1 text-sm">
        {items.map((item, index) => {
          const isLast = index === items.length - 1

          return (
            <Fragment key={`${item.label}-${index}`}>
              {index > 0 && (
                <li aria-hidden="true" className="text-muted-foreground">
                  /
                </li>
              )}
              <li>
                {item.href && !isLast ? (
                  <Link
                    href={item.href}
                    className="text-muted-foreground hover:text-foreground"
                  >
                    {item.label}
                  </Link>
                ) : (
                  <span
                    aria-current={isLast ? "page" : undefined}
                    className="font-medium text-foreground"
                  >
                    {item.label}
                  </span>
                )}
              </li>
            </Fragment>
          )
        })}
      </ol>
    </nav>
  )
}
