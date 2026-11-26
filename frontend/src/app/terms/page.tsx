import Link from "next/link";

export default function TermsPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
      <Link href="/" className="text-sm text-brand-700 hover:underline">
        Back to ClaimTrail
      </Link>
      <h1 className="mt-4 text-3xl font-semibold text-slate-900">Terms of Service</h1>
      <p className="mt-2 text-sm text-slate-500">Last reviewed internally: 26 November 2026 (ClaimTrail v1.3.1).</p>
      <div className="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Reviewed internally against how the app actually works. This
        is not legal advice, and these terms have not been reviewed
        by a lawyer. Do not rely on them for legal decisions.
      </div>
      <div className="mt-6 space-y-4 text-sm leading-relaxed text-slate-600">
        <h2 className="text-base font-semibold text-slate-900">What ClaimTrail does</h2>
        <p>
          ClaimTrail is a tool for tracking potential additional
          shipment charges (detention, layover, tonu, lumper) and
          the evidence behind them. It does not guarantee that any
          charge is recoverable, and it does not provide legal or
          contractual advice. Customers are responsible for
          verifying contractual eligibility, submission
          requirements, and deadlines with their brokers and
          customers.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Accounts and data</h2>
        <p>
          You are responsible for keeping your account credentials
          safe and for what members of your organization do in the
          service. Your organization&apos;s data belongs to your
          organization; we use it only to operate the service and
          we do not share it with third parties in the current
          deployment. What we collect and how long we keep it is
          described in the privacy policy.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Acceptable use</h2>
        <p>
          Use the service only for lawful business purposes. Do not
          upload malware or unlawful material, do not try to access
          another organization&apos;s data, and do not abuse the
          service in a way that harms other customers. We may
          suspend an account that does any of these things.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Plans, billing, and refunds</h2>
        <p>
          Plans and prices are listed on the pricing page. Monthly
          billing is charged at the start of the period; annual
          billing is ten times the monthly price. Upgrades apply
          immediately with a prorated credit; downgrades apply at
          the end of the current period. A 30-day trial on the
          Growth tier needs no card and converts to paid Growth
          only when a payment method is added; otherwise the
          organization returns to Free and nothing is deleted.
        </p>
        <p>
          The success-fee plan charges $0 a month plus 15 percent
          of amounts recorded as paid against claims created in
          ClaimTrail, within 12 months of the claim&apos;s creation,
          net of refunds, capped at $49 a month for the first year.
        </p>
        <p>
          To cancel, use the billing page or open a support ticket:
          cancelling moves the organization to Free at the end of
          the current period and the data stays. To request a
          refund, open a support ticket within 14 days of the
          charge and state the invoice number and the reason. The
          operator reviews the request and, where the provider
          supports it, refunds to the original payment method.
          Refunds are issued for the unused part of a period after
          a duplicate charge or a billing error; a change of mind
          after the service was used is not refunded. Billing
          currently runs on a mock provider; real payment
          processing through Stripe will be described here before
          it goes live.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Availability</h2>
        <p>
          We run the service with daily backups and hourly
          monitoring, but we make no uptime guarantee and no
          promise about response times. Backups are verified
          weekly. If the service is unavailable, the status is
          visible on the operations page for organization admins.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Liability</h2>
        <p>
          The service is provided as-is. We are not liable for
          charges that are not recovered, for deadlines missed
          because a claim was not submitted, or for losses from
          data you entered incorrectly. Our total liability is
          limited to the fees you paid in the 12 months before the
          event giving rise to the claim.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Termination</h2>
        <p>
          You may cancel at any time as described above. We may
          terminate an account for breach of these terms or for
          non-payment after the dunning period (14 days past due).
          On termination, your organization&apos;s data is kept
          for its retention period and then purged; ready claim
          packages are kept as the record of submitted claims.
          Export your data before cancelling if you need it.
        </p>
        <h2 className="text-base font-semibold text-slate-900">Changes</h2>
        <p>
          These terms change when the service changes. The review
          date at the top says which release they describe.
        </p>
        <p className="text-slate-500">
          Still to be completed by the operator before these terms
          can be final: the operating company name, governing law,
          and a contact address.
        </p>
      </div>
    </div>
  );
}
