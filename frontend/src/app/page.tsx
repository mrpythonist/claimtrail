import Link from "next/link";

const WORKFLOW_STEPS = [
  {
    title: "Record the shipment",
    description:
      "Log the load, the broker, the rate, and the appointments in one place instead of across spreadsheets and messages.",
  },
  {
    title: "Capture the event",
    description:
      "Detention, layover, TONU, lumper, extra stops and extra miles are recorded against the shipment as potential opportunities, never as guaranteed money.",
  },
  {
    title: "Collect the evidence",
    description:
      "Rate confirmations, bills of lading, proof of delivery, receipts, and photos stay attached to the shipment and the claim.",
  },
  {
    title: "Prepare and track the claim",
    description:
      "Check what evidence is still missing, watch the submission deadline, generate the claim package, and record what was actually paid.",
  },
];

export default function Home() {
  return (
    <div className="flex min-h-screen flex-col bg-white">
      <header className="border-b border-slate-200">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
          <Link href="/" className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-md bg-amber-500 text-sm font-bold text-brand-950">
              CT
            </span>
            <span className="text-lg font-semibold tracking-tight text-slate-900">
              ClaimTrail
            </span>
          </Link>
          <nav className="flex items-center gap-3">
            <Link
              href="/login"
              className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
              Sign in
            </Link>
            <Link
              href="/register"
              className="rounded-md bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
            >
              Get started
            </Link>
          </nav>
        </div>
      </header>

      <main className="flex-1">
        <section className="border-b border-slate-200 bg-slate-50">
          <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
            <h1 className="max-w-3xl text-4xl font-semibold leading-tight tracking-tight text-slate-900 sm:text-5xl">
              Every shipment. Every entitlement. Every dollar accounted for.
            </h1>
            <p className="mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
              ClaimTrail helps trucking companies and dispatchers keep track of
              additional shipment charges: detention, layover, TONU, lumper,
              and the rest. One workflow for the shipment, the event, the
              evidence, the deadline, and the payment.
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Link
                href="/register"
                className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
              >
                Create your organization
              </Link>
              <Link
                href="/login"
                className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
              >
                Sign in
              </Link>
            </div>
            <p className="mt-6 max-w-2xl text-sm text-slate-500">
              ClaimTrail tracks potential charges and the evidence behind them.
              It never guarantees that a charge is recoverable. Contractual
              eligibility and submission requirements are yours to verify.
            </p>
          </div>
        </section>

        <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
            How it works
          </h2>
          <div className="mt-8 grid gap-6 sm:grid-cols-2">
            {WORKFLOW_STEPS.map((step, index) => (
              <div
                key={step.title}
                className="rounded-lg border border-slate-200 bg-white p-6"
              >
                <p className="text-sm font-semibold text-brand-600">
                  Step {index + 1}
                </p>
                <h3 className="mt-2 text-lg font-semibold text-slate-900">
                  {step.title}
                </h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                  {step.description}
                </p>
              </div>
            ))}
          </div>
        </section>

        <section className="border-t border-slate-200 bg-slate-50">
          <div className="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <h2 className="text-xl font-semibold text-slate-900">
              Built for operations teams
            </h2>
            <p className="mt-3 max-w-2xl text-sm leading-relaxed text-slate-600">
              Owners, admins, operations managers, dispatchers, billing staff,
              and read-only viewers each get the access their role needs and
              nothing more. Every organization&apos;s data stays separate, and
              every important change is recorded in an audit trail.
            </p>
          </div>
        </section>
      </main>

      <footer className="border-t border-slate-200">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-6 sm:px-6">
          <p className="text-sm text-slate-500">
            ClaimTrail v1.1.0. Transportation revenue recovery, done carefully.
          </p>
          <nav className="flex gap-4 text-sm">
            <Link href="/privacy" className="text-slate-500 hover:text-slate-900">
              Privacy
            </Link>
            <Link href="/terms" className="text-slate-500 hover:text-slate-900">
              Terms
            </Link>
          </nav>
        </div>
      </footer>
    </div>
  );
}
