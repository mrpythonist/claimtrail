import Link from "next/link";

export default function PrivacyPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
      <Link href="/" className="text-sm text-brand-700 hover:underline">
        Back to ClaimTrail
      </Link>
      <h1 className="mt-4 text-3xl font-semibold text-slate-900">Privacy Policy</h1>
      <p className="mt-2 text-sm text-slate-500">Last reviewed internally: 26 November 2026 (ClaimTrail v1.3.1).</p>
      <div className="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Reviewed internally against how the app actually handles data.
        This is not legal advice, and this policy has not been reviewed
        by a lawyer. Do not rely on it for compliance decisions.
      </div>
      <div className="mt-6 space-y-4 text-sm leading-relaxed text-slate-600">
        <h2 className="text-base font-semibold text-slate-900">What we collect</h2>
        <p>
          To run the service we store account information (name, email,
          and a password hash; we never store a password in plain
          text), organization and membership details, carrier contact
          details you enter, shipment and claim records, files you
          upload as evidence, claim packages we generate, billing
          records (plan, invoices, and the brand and last four digits
          of a payment method; no card numbers), support tickets, and
          demo-request details (name, email, company, fleet size).
        </p>
        <p>
          We do not store IP addresses or user agents for demo requests
          or analytics, we do not collect location data, and we do not
          sell data or use it for advertising.
        </p>
        <h2 className="text-base font-semibold text-slate-900">How we use it</h2>
        <p>
          Your data is used only to operate the service: signing you
          in, running your organization&apos;s workspace, tracking your
          shipments and claims, billing your subscription, and
          answering your support tickets. Customer data stays inside
          the customer&apos;s organization; members of one organization
          cannot see another organization&apos;s data.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Who we share it with</h2>
        <p>
          No one, in the current deployment. Billing runs on a mock
          provider inside the application, email is logged on the
          server rather than sent through a provider, and analytics
          is log-only. Before any real payment, email, or analytics
          provider is connected, this policy will be updated to name
          it.
        </p>
        <h2 className="text-base font-semibold text-slate-900">How long we keep it</h2>
        <p>
          Audit logs are kept 365 days, notifications 90 days, job
          records 30 days, failed documents 30 days, and security
          events 180 days. Ready claim packages are never deleted by
          retention. Shipments, claims, carriers, and evidence are
          kept until your organization deletes them or asks us to.
          Backups are full copies of the database and files; they are
          not covered by the retention settings and are removed when
          the backup itself is deleted.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Security</h2>
        <p>
          Passwords are stored as bcrypt hashes, access is controlled
          by six roles, uploaded files are stored privately and served
          only after an authorization check, and the service sets
          content-security and transport-security headers. Known
          limits: API tokens do not expire yet, access tokens are
          stored in the browser&apos;s local storage, uploaded files
          are not scanned for malware, and backups and stored files
          are not encrypted by the application itself.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Access, correction, and deletion</h2>
        <p>
          There is no self-service account or organization deletion
          yet. To request access to, correction of, or deletion of
          your data, open a support ticket from inside the app; the
          operator handles the request manually. Deletion removes your
          organization&apos;s shipments, claims, evidence, tickets,
          and billing records; audit and security records are kept
          for their retention period and then purged on schedule.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Children</h2>
        <p>
          The service is intended for business users. We do not
          knowingly collect data from children, and we do not
          enforce an age check.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Changes</h2>
        <p>
          This policy changes when the app&apos;s actual practices
          change: a new provider, a new kind of data, a new
          retention rule, or a new deletion flow. The review date
          at the top says which release it describes.
        </p>
        <p className="text-slate-500">
          Still to be completed by the operator before this policy can
          be final: the operating company name, governing law, and a
          contact address.
        </p>
      </div>
    </div>
  );
}
