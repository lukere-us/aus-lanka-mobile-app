import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ActionForm } from '@/components/ActionForm';
import { Status, humanize } from '@/components/Status';
import { ApiErrorPanel, Card, Forbidden, PageHeader } from '@/components/ui';
import { api, load } from '@/lib/api';
import { dateTime, money } from '@/lib/format';
import { can, requireUser } from '@/lib/session';
import type { Order } from '@/lib/types';
import { refundOrder } from '../actions';

export const metadata: Metadata = { title: 'Order' };

export default async function OrderPage({ params }: { params: Promise<{ reference: string }> }) {
  const me = await requireUser();
  if (!can(me, 'orders.view')) return <Forbidden />;

  const reference = decodeURIComponent((await params).reference).toUpperCase();
  if (!/^[A-Z0-9-]{4,20}$/.test(reference)) notFound();
  const [result, error] = await load(api<{ data: Order }>(`admin/orders/${reference}`));
  if (error?.status === 404) notFound();
  const order = result?.data;
  const refundable = order && ['paid', 'partially_refunded'].includes(order.status);

  return (
    <>
      <PageHeader title={`Order ${reference}`} subtitle={<Link href="/orders">← All orders</Link>} />
      {error && <ApiErrorPanel error={error} />}
      {order && (
        <div className="stack-lg">
          <div className="grid-2">
            <Card title="Summary">
              <dl className="details">
                <dt>Status</dt>
                <dd>
                  <Status value={order.status} />
                </dd>
                <dt>Customer</dt>
                <dd>
                  {order.customer?.id ? <Link href={`/users/${order.customer.id}`}>{order.customer.email}</Link> : '—'}
                </dd>
                <dt>Created</dt>
                <dd>{dateTime(order.created_at)}</dd>
                <dt>Paid</dt>
                <dd>{dateTime(order.paid_at)}</dd>
                {order.status === 'pending_payment' && (
                  <>
                    <dt>Expires</dt>
                    <dd>{dateTime(order.expires_at)}</dd>
                  </>
                )}
              </dl>
            </Card>
            <Card title="Payment">
              {order.payment ? (
                <dl className="details">
                  <dt>Status</dt>
                  <dd>
                    <Status value={order.payment.status} />
                  </dd>
                  <dt>Gateway</dt>
                  <dd>{humanize(order.payment.provider)}</dd>
                  {order.exchange_rate && (
                    <>
                      <dt>Exchange rate</dt>
                      <dd>
                        1 {order.exchange_rate.base_currency} = {Number(order.exchange_rate.rate)} {order.total.currency}
                      </dd>
                      <dt>Total in {order.exchange_rate.base_currency}</dt>
                      <dd>{money(order.exchange_rate.base_total.amount, order.exchange_rate.base_currency)}</dd>
                    </>
                  )}
                </dl>
              ) : (
                <p className="muted">No payment started.</p>
              )}
              {order.payment?.status === 'review' && (
                <p className="alert alert-info small">
                  The amount the gateway reported didn’t match the order, so nothing was issued. Check it in the gateway’s dashboard.
                </p>
              )}
            </Card>
          </div>

          <Card title="Items">
            <table className="table table-compact">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Type</th>
                  <th className="num">Price</th>
                  <th className="num">Qty</th>
                  <th className="num">Line total</th>
                </tr>
              </thead>
              <tbody>
                {order.items.map((i, n) => (
                  <tr key={n}>
                    <td>{i.name}</td>
                    <td>{i.type === 'ticket_type' ? 'Event ticket' : i.type === 'rental_package' ? 'Movie rental' : i.type}</td>
                    <td className="num">{money(i.unit_price.amount, i.unit_price.currency)}</td>
                    <td className="num">{i.quantity}</td>
                    <td className="num">{money(i.line_total.amount, i.line_total.currency)}</td>
                  </tr>
                ))}
                <tr>
                  <th scope="row" colSpan={4}>
                    Booking fee
                  </th>
                  <td className="num">{money(order.fee_total.amount, order.fee_total.currency)}</td>
                </tr>
                {Number(order.discount_total.amount) > 0 && (
                  <tr>
                    <th scope="row" colSpan={4}>
                      Discount
                    </th>
                    <td className="num">−{money(order.discount_total.amount, order.discount_total.currency)}</td>
                  </tr>
                )}
                <tr className="row-total">
                  <th scope="row" colSpan={4}>
                    Total
                  </th>
                  <td className="num">{money(order.total.amount, order.total.currency)}</td>
                </tr>
              </tbody>
            </table>
          </Card>

          {can(me, 'refunds.approve') && refundable && (
            <Card title="Refund this order">
              <ActionForm
                action={refundOrder}
                submitLabel="Refund now"
                variant="danger"
                confirm={`Refund everything still refundable on ${order.reference}? The money goes back to the customer's card.`}
              >
                <input type="hidden" name="reference" value={order.reference} />
                <p className="small">
                  Refunds every ticket and unwatched rental on this order that isn’t used or already refunded. Tickets are cancelled
                  straight away. For a single ticket, ask the customer to request it in the app, or use the refund queue.
                </p>
                <label className="field">
                  <span>Reason (kept in the audit log)</span>
                  <textarea name="reason" rows={2} required maxLength={500} />
                </label>
                <label className="check">
                  <input type="checkbox" name="include_fees" />
                  <span>Also refund the booking fee</span>
                </label>
              </ActionForm>
            </Card>
          )}
        </div>
      )}
    </>
  );
}
