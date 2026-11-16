import Link from "next/link";

export default function PrivacyPage() {
  return (
    <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
      <Link href="/" className="text-sm text-brand-700 hover:underline">
        Back to ClaimTrail
      </Link>
      <h1 className="mt-4 text-3xl font-semibold text-slate-900">Privacy Policy</h1>
      <div className="mt-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Placeholder document, pending legal review. This page does not yet
        constitute a privacy policy. Do not rely on it for compliance
        decisions.
      </div>
      <div className="mt-6 space-y-4 text-sm leading-relaxed text-slate-600">
        <p>
          ClaimTrail stores account information, organization data, shipment
          records, uploaded documents, and claim records on behalf of its
          customers. This placeholder will be replaced with a full privacy
          policy covering what data is collected, why, how long it is kept,
          who it is shared with, and how to request access or deletion.
        </p>
        <p>
          Until the final policy is published, the working principles are:
          customer data stays inside the customer&apos;s organization, documents
          are stored privately and never shared publicly, and account data is
          used only to operate the service.
        </p>
      </div>
    </div>
  );
}
