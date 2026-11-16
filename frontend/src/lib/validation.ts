/**
 * Client-side form validation. These checks mirror the backend rules so
 * users get fast feedback; the backend remains the authority and its
 * error messages are shown when it disagrees.
 */

export interface LoginForm {
  email: string;
  password: string;
}

export interface RegisterForm {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export type FieldErrors = Record<string, string>;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function validateLoginForm(form: LoginForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.email.trim()) {
    errors.email = "Email is required.";
  } else if (!EMAIL_PATTERN.test(form.email.trim())) {
    errors.email = "Enter a valid email address.";
  }

  if (!form.password) {
    errors.password = "Password is required.";
  }

  return errors;
}

export function validateRegisterForm(form: RegisterForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.name.trim()) {
    errors.name = "Name is required.";
  }

  if (!form.email.trim()) {
    errors.email = "Email is required.";
  } else if (!EMAIL_PATTERN.test(form.email.trim())) {
    errors.email = "Enter a valid email address.";
  }

  if (!form.password) {
    errors.password = "Password is required.";
  } else if (form.password.length < 8) {
    errors.password = "Password must be at least 8 characters.";
  }

  if (form.password_confirmation !== form.password) {
    errors.password_confirmation = "Passwords do not match.";
  }

  return errors;
}

export function validateEmailField(email: string): string | undefined {
  if (!email.trim()) {
    return "Email is required.";
  }
  if (!EMAIL_PATTERN.test(email.trim())) {
    return "Enter a valid email address.";
  }
  return undefined;
}

export interface ShipmentForm {
  internal_reference: string;
  broker_or_customer_name: string;
  base_rate: string;
  currency: string;
}

export function validateShipmentForm(form: ShipmentForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.internal_reference.trim()) {
    errors.internal_reference = "Internal reference is required.";
  }

  if (!form.broker_or_customer_name.trim()) {
    errors.broker_or_customer_name = "Broker or customer name is required.";
  }

  if (form.base_rate.trim()) {
    const amount = Number(form.base_rate.trim());
    if (Number.isNaN(amount) || amount < 0) {
      errors.base_rate = "Base rate must be a number of 0 or more.";
    }
  }

  if (form.currency.trim() && !/^[A-Za-z]{3}$/.test(form.currency.trim())) {
    errors.currency = "Currency must be a 3-letter code, for example USD.";
  }

  return errors;
}

export interface EventForm {
  event_category_id: string;
  event_date: string;
  start_time: string;
  end_time: string;
  estimated_amount: string;
}

export function validateEventForm(form: EventForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.event_category_id) {
    errors.event_category_id = "Event category is required.";
  }

  if (!form.event_date) {
    errors.event_date = "Event date is required.";
  }

  if (form.start_time && form.end_time && form.end_time <= form.start_time) {
    errors.end_time = "End time must be after the start time.";
  }

  if (form.estimated_amount.trim()) {
    const amount = Number(form.estimated_amount.trim());
    if (Number.isNaN(amount) || amount < 0) {
      errors.estimated_amount = "Estimated amount must be a number of 0 or more.";
    }
  }

  return errors;
}

export interface ClaimForm {
  shipment_id: string;
  requested_amount: string;
  currency: string;
}

export function validateClaimForm(form: ClaimForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.shipment_id) {
    errors.shipment_id = "Choose a shipment for this claim.";
  }

  if (!form.requested_amount.trim()) {
    errors.requested_amount = "Requested amount is required.";
  } else {
    const amount = Number(form.requested_amount.trim());
    if (Number.isNaN(amount) || amount <= 0) {
      errors.requested_amount = "Requested amount must be greater than 0.";
    }
  }

  if (form.currency.trim() && !/^[A-Za-z]{3}$/.test(form.currency.trim())) {
    errors.currency = "Currency must be a 3-letter code, for example USD.";
  }

  return errors;
}

export interface RecordPaymentForm {
  recovered_amount: string;
  payment_date: string;
}

export function validateRecordPaymentForm(form: RecordPaymentForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.recovered_amount.trim()) {
    errors.recovered_amount = "Recovered amount is required.";
  } else {
    const amount = Number(form.recovered_amount.trim());
    if (Number.isNaN(amount) || amount <= 0) {
      errors.recovered_amount = "Recovered amount must be greater than 0.";
    }
  }

  if (!form.payment_date) {
    errors.payment_date = "Payment date is required.";
  }

  return errors;
}

export interface DeadlineForm {
  deadline_at: string;
  timezone: string;
}

export function validateDeadlineForm(form: DeadlineForm): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.deadline_at) {
    errors.deadline_at = "Deadline date and time are required.";
  }

  if (!form.timezone) {
    errors.timezone = "Timezone is required.";
  }

  return errors;
}

export interface EvidenceRequirementForm {
  document_category: string;
}

export function validateEvidenceRequirementForm(
  form: EvidenceRequirementForm,
): FieldErrors {
  const errors: FieldErrors = {};

  if (!form.document_category) {
    errors.document_category = "Document category is required.";
  }

  return errors;
}

export interface TransitionForm {
  to_status: string;
  accepted_amount: string;
  requested_amount: string;
  rejection_reason: string;
  reason: string;
}

export function validateTransitionForm(form: TransitionForm): FieldErrors {
  const errors: FieldErrors = {};

  if (form.to_status === "accepted" || form.to_status === "partially_accepted") {
    if (!form.accepted_amount.trim()) {
      errors.accepted_amount = "Accepted amount is required.";
    } else {
      const accepted = Number(form.accepted_amount.trim());
      if (Number.isNaN(accepted) || accepted <= 0) {
        errors.accepted_amount = "Accepted amount must be greater than 0.";
      } else if (form.to_status === "partially_accepted") {
        const requested = Number(form.requested_amount);
        if (!Number.isNaN(requested) && accepted >= requested) {
          errors.accepted_amount =
            "A partially accepted amount must be less than the requested amount.";
        }
      }
    }
  }

  if (form.to_status === "rejected" && !form.rejection_reason.trim()) {
    errors.rejection_reason = "A rejection reason is required.";
  }

  if (form.to_status === "closed" && !form.reason.trim()) {
    errors.reason = "A reason is required to close a claim.";
  }

  return errors;
}
