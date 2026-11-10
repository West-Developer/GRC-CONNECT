import { useId, type ComponentProps, type ReactNode } from "react"
import type { LucideIcon } from "lucide-react"

import { Badge } from "@/features/components/ui/badge"
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"

type BadgeVariant = ComponentProps<typeof Badge>["variant"]

interface ServiceBoundaryCardProps {
  badge: string
  badgeVariant?: BadgeVariant
  children: ReactNode
  description: string
  footer: ReactNode
  icon: LucideIcon
  index: string
  title: string
}

export function ServiceBoundaryCard({
  badge,
  badgeVariant = "outline",
  children,
  description,
  footer,
  icon: Icon,
  index,
  title,
}: ServiceBoundaryCardProps) {
  const titleId = useId()

  return (
    <Card className="min-h-full" role="group" aria-labelledby={titleId}>
      <CardHeader>
        <div className="boundary-card__marker" aria-hidden="true">
          <span>{index}</span>
          <Icon />
        </div>
        <CardTitle>
          <h3 id={titleId}>{title}</h3>
        </CardTitle>
        <CardDescription>{description}</CardDescription>
        <CardAction>
          <Badge variant={badgeVariant}>{badge}</Badge>
        </CardAction>
      </CardHeader>
      <CardContent>{children}</CardContent>
      <CardFooter>
        <div className="boundary-card__footer">{footer}</div>
      </CardFooter>
    </Card>
  )
}
