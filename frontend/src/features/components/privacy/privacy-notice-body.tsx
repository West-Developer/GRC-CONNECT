import { ShieldCheck } from "lucide-react"

import {
  DATA_USES,
  PRIVACY_LAW,
  RETENTION,
  RIGHTS,
  SECURITY,
  SENSITIVE_SUMMARY,
  SHARING,
  STAFF_DUTIES,
  WHO_IS_COVERED,
  YOUR_PART,
} from "@/features/lib/privacy/notice-content"
import type { PrivacyNoticeInfo } from "@/features/schemas/auth-schema"

function Section({
  id,
  title,
  children,
}: {
  id: string
  title: string
  children: React.ReactNode
}) {
  return (
    <section aria-labelledby={`privacy-${id}`} className="grid gap-3">
      <h2
        id={`privacy-${id}`}
        className="text-lg font-semibold tracking-tight text-foreground"
      >
        {title}
      </h2>
      {children}
    </section>
  )
}

function BulletList({ items }: { items: readonly string[] }) {
  return (
    <ul className="grid gap-1.5 pl-5 text-sm leading-6 text-foreground/90 [&>li]:list-disc">
      {items.map((item) => (
        <li key={item}>{item}</li>
      ))}
    </ul>
  )
}

function Contact({ info }: { info: PrivacyNoticeInfo | undefined }) {
  if (!info) {
    return (
      <p className="text-sm text-muted-foreground">
        Loading the contact details…
      </p>
    )
  }

  return (
    <dl className="grid gap-1 text-sm">
      {info.contact.name ? (
        <div className="flex flex-wrap gap-x-2">
          <dt className="font-medium">Data Protection Officer</dt>
          <dd>{info.contact.name}</dd>
        </div>
      ) : null}
      <div className="flex flex-wrap gap-x-2">
        <dt className="font-medium">Email</dt>
        <dd>
          <a
            className="underline underline-offset-4"
            href={`mailto:${info.contact.email}`}
          >
            {info.contact.email}
          </a>
        </dd>
      </div>
      {info.contact.phone ? (
        <div className="flex flex-wrap gap-x-2">
          <dt className="font-medium">Phone</dt>
          <dd>{info.contact.phone}</dd>
        </div>
      ) : null}
    </dl>
  )
}

/**
 * The whole Data Privacy Notice (ADR 0055). One body for the public `/privacy` page and for the read-and-agree
 * screen, so what a person reads before agreeing is exactly what the page publishes.
 */
export function PrivacyNoticeBody({
  info,
}: {
  info: PrivacyNoticeInfo | undefined
}) {
  const controller = info?.controller ?? "Global Reciprocal Colleges"

  return (
    <div className="grid gap-8">
      <div className="flex gap-3 rounded-lg border border-border bg-muted/40 p-4">
        <ShieldCheck
          aria-hidden="true"
          className="mt-0.5 size-5 shrink-0 text-primary"
        />
        <div className="grid gap-2 text-sm leading-6">
          <p className="font-medium">In short</p>
          <BulletList
            items={[
              `${controller} keeps the personal data in this system to enroll students, keep their records, collect payments and run the school. It uses the data for nothing else.`,
              "Each form tells you what it asks for, why, and who can see it.",
              "You decide whether to agree. You also have rights over your data, listed below.",
            ]}
          />
        </div>
      </div>

      <Section id="who" title="Who this notice is for">
        <p className="text-sm leading-6 text-foreground/90">
          {controller} (“GRC”, “the school”) is responsible for the personal
          data in this system under the {PRIVACY_LAW.name}. This notice is for:
        </p>
        <dl className="grid gap-3 sm:grid-cols-2">
          {WHO_IS_COVERED.map((group) => (
            <div
              key={group.id}
              className="rounded-lg border border-border bg-card p-3"
            >
              <dt className="text-sm font-medium">{group.title}</dt>
              <dd className="mt-1 text-sm leading-6 text-muted-foreground">
                {group.body}
              </dd>
            </div>
          ))}
        </dl>
      </Section>

      <Section id="what" title="What we keep, why, and who sees it">
        <p className="text-sm leading-6 text-foreground/90">
          {SENSITIVE_SUMMARY}
        </p>
        <div className="grid gap-4">
          {DATA_USES.map((use) => (
            <article
              key={use.id}
              className="grid gap-3 rounded-lg border border-border bg-card p-4"
            >
              <h3 className="text-base font-semibold">{use.label}</h3>
              <div className="grid gap-4 md:grid-cols-3">
                <div className="grid content-start gap-1.5">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    What we keep
                  </p>
                  <BulletList items={use.data} />
                </div>
                <div className="grid content-start gap-1.5">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Why we keep it
                  </p>
                  <BulletList items={use.purposes} />
                </div>
                <div className="grid content-start gap-1.5">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Who sees it
                  </p>
                  <BulletList items={use.seenBy} />
                </div>
              </div>
              {use.sensitive ? (
                <p className="text-sm font-medium text-foreground">
                  {use.sensitive}
                </p>
              ) : null}
            </article>
          ))}
        </div>
      </Section>

      <Section id="sharing" title="Sharing your data">
        <BulletList items={SHARING} />
      </Section>

      <Section id="retention" title="How long we keep your data">
        <BulletList items={RETENTION} />
      </Section>

      <Section id="security" title="How we protect it">
        <BulletList items={SECURITY} />
      </Section>

      <Section id="your-part" title="What we ask of you">
        <BulletList items={YOUR_PART} />
      </Section>

      <Section id="staff" title="If you work with student records">
        <BulletList items={STAFF_DUTIES} />
      </Section>

      <Section id="rights" title="Your rights">
        <dl className="grid gap-3 sm:grid-cols-2">
          {RIGHTS.map((right) => (
            <div
              key={right.id}
              className="rounded-lg border border-border bg-card p-3"
            >
              <dt className="text-sm font-medium">{right.title}</dt>
              <dd className="mt-1 text-sm leading-6 text-muted-foreground">
                {right.body}
              </dd>
            </div>
          ))}
        </dl>
        <p className="text-sm leading-6 text-foreground/90">
          You can also file a complaint with the {PRIVACY_LAW.regulator} at{" "}
          <a
            className="underline underline-offset-4"
            href={PRIVACY_LAW.regulatorUrl}
            rel="noreferrer"
            target="_blank"
          >
            privacy.gov.ph
          </a>
          .
        </p>
      </Section>

      <Section id="contact" title="Who to ask">
        <p className="text-sm leading-6 text-foreground/90">
          To use any of your rights, to ask a question about this notice, or to
          report a problem with personal data, write to:
        </p>
        <Contact info={info} />
      </Section>
    </div>
  )
}
