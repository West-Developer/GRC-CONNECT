import { ExternalLink } from "lucide-react"

export function PublicFooter() {
  return (
    <footer className="site-footer public-footer">
      <div>
        <strong>Global Reciprocal Colleges</strong>
        <span>Automated Enrollment System</span>
      </div>
      <div>
        <a href="https://grc.edu.ph/about-us/">About GRC</a>
        <a href="https://grc.edu.ph/contact-us/">Contact GRC</a>
        <a href="/privacy">Data Privacy</a>
        <a href="https://grc.edu.ph/">
          Official GRC website
          <ExternalLink data-icon="inline-end" aria-hidden="true" />
        </a>
      </div>
    </footer>
  )
}
