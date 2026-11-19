/** Shapes of the API responses the website uses (see backend resources). */

export interface Money {
  amount: string;
  currency: string;
}

/** Laravel paginated resource collection. */
export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number; per_page?: number };
}

/** Simpler list envelope used by some endpoints ({data, meta:{total, current_page}}). */
export interface Listed<T> {
  data: T[];
  meta: { total: number; current_page?: number };
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  status: 'active' | 'suspended';
  email_verified: boolean;
  phone_verified: boolean;
  roles: string[];
  last_login_at: string | null;
  created_at: string;
}

export interface Organizer {
  id: number;
  name: string;
  slug: string;
  status: 'pending' | 'approved' | 'rejected' | 'suspended';
  status_reason: string | null;
  contact_email: string;
  contact_phone: string | null;
  business_registration_no: string | null;
  approved_at: string | null;
  members?: { user_id: number; name: string; email: string; role: string }[];
  created_at: string;
}

export interface Venue {
  id: number;
  organizer_id: number | null;
  name: string;
  address: string;
  city: string;
  country: string;
  timezone: string;
  capacity: number | null;
}

export interface TicketType {
  id: number;
  name: string;
  kind: string;
  description: string | null;
  price: Money;
  max_per_order: number;
  sale_starts_at: string | null;
  sale_ends_at: string | null;
  sale_state: string;
  available: number;
  status?: 'active' | 'hidden';
  quantity_total?: number;
  quantity_sold?: number;
  quantity_reserved?: number;
}

export interface EventItem {
  id: number;
  slug: string;
  title: string;
  description: string | null;
  category: string;
  image_url: string | null;
  starts_at: string;
  starts_at_local?: string;
  ends_at: string | null;
  doors_open_at: string | null;
  min_age: number | null;
  terms: string | null;
  venue?: { id: number; name: string; address: string; city: string; country: string; timezone: string };
  organizer?: { name: string; slug: string };
  ticket_types: TicketType[];
  status: string;
  review_note: string | null;
  published_at: string | null;
}

export interface RentalPackage {
  id: number;
  name: string;
  quality: string;
  price: Money;
  watch_hours: number;
  start_within_days: number;
  status: 'active' | 'inactive';
}

export interface Movie {
  id: number;
  slug: string;
  title: string;
  synopsis: string | null;
  release_year: number | null;
  runtime_minutes: number | null;
  classification: string | null;
  genres: string[] | null;
  languages: string[] | null;
  poster_url: string | null;
  backdrop_url: string | null;
  trailer_url: string | null;
  rental_packages: RentalPackage[];
  status: 'draft' | 'published' | 'unpublished';
  assets: { id: number; kind: string; provider: string; status: string; duration_seconds: number | null; subtitles: unknown[] }[];
  published_at: string | null;
}

export interface Order {
  reference: string;
  status: string;
  subtotal: Money;
  discount_total: Money;
  fee_total: Money;
  total: Money;
  exchange_rate: { base_currency: string; rate: string; base_total: Money } | null;
  items: { type: string; name: string; unit_price: Money; quantity: number; line_total: Money }[];
  payment: { status: string; provider: string } | null;
  customer?: { id: number; email: string | null };
  expires_at: string | null;
  paid_at: string | null;
  created_at: string;
}

export interface Refund {
  id: number;
  order_reference: string | null;
  status: string;
  amount: Money;
  base_amount: Money;
  includes_fees: boolean;
  reason: string | null;
  review_note: string | null;
  failure_reason: string | null;
  ticket_ids: number[] | null;
  rental_ids: number[] | null;
  created_at: string;
  completed_at: string | null;
}

export interface Balance {
  organizer_id: number;
  total: string;
  available: string;
  pending: string;
  paid_out: string;
  currency: string;
  commission_bps: number;
}

export interface LedgerEntry {
  id: number;
  type: string;
  amount: Money;
  event_id: number | null;
  order_id: number | null;
  payout_id: number | null;
  description: string | null;
  created_at: string;
}

export interface Payout {
  id: number;
  organizer_id: number;
  organizer_name?: string | null;
  amount: string;
  currency: string;
  reference: string;
  note: string | null;
  created_at: string;
}

export interface Setting {
  key: string;
  value: number | string;
  description: string;
}

export interface Currency {
  code: string;
  name: string;
  symbol: string;
  decimals: number;
  rate_per_base: string | null;
  is_base: boolean;
  is_active: boolean;
  rate_updated_at: string | null;
}

export interface Attendee {
  ticket_id: number;
  number: number;
  name: string | null;
  email: string | null;
  ticket_type: string | null;
  status: string;
  checked_in_at: string | null;
  order: string | null;
}

export interface EventSales {
  currency: string;
  ticket_types: { id: number; name: string; price: Money; quantity_total: number; sold: number; held_in_checkout: number; available: number }[];
  gross_sales: string;
  refunds: string;
  platform_commission: string;
  net_to_organizer: string;
  orders: number;
}

export interface EventStats {
  tickets_issued: number;
  checked_in: number;
  still_to_arrive: number;
  admitted_last_15_min: number;
  by_ticket_type: { ticket_type: string; issued: number; checked_in: number }[];
  by_gate: { gate: string; admitted: number }[];
  rejected_scans: Record<string, number>;
  as_of: string;
}

export interface ScannerDevice {
  id: number;
  name: string;
  device_uid: string;
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string;
}
