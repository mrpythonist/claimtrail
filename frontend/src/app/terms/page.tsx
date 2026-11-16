import Link from "next/link";

export default function TermsPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
      <Link href="/" className="text-sm text-brand-700 hover:underline">
        Back to ClaimTrail
      </Link>
      <h1 className="mt-4 text-3xl font-semibold text-slate-900">Terms of Service</h1>
      <div className="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Placeholder document, pending legal review. This page does not yet
        constitute terms of service. Do not rely on it for legal decisions.
      </div>
      <div className="mt-6 space-y-4 text-sm leading-relaxed text-slate-600">
        <p>
          ClaimTrail is a tool for tracking potential additional shipment
          charges and the evidence behind them. It does not guarantee that any
          charge is recoverable, and it does not provide legal or contractual
          advice. Customers are responsible for verifying contractual
          eligibility, submission requirements, and deadlines with their
          brokers and customers.
        </p>
        <p>
          This placeholder will be replaced with full terms covering accounts,
          acceptable use, data ownership, availability, liability, and
          termination.
        </p>
      </div>
    </div>
  );
}
