import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { compactMoney, money, shortDate } from '../lib/format'
import { invalidatePortfolioMoney } from '../lib/portfolioQueries'
import { Card, ErrorBox, Npr, Spinner } from './ui'
import { Explain } from './Explain'
import { SetupNeeded } from './GettingStarted'
import type { ChannelOverview, ChannelProfit, ChannelTransaction } from '../lib/types'

/**
 * Set how each parcel was acquired, and see what it does to the profit.
 *
 * The point of the screen: a blended return mixes an IPO allotted at par by
 * lottery with a scrip that was actually chosen, and only the second says
 * anything about the investor. Marking a parcel IPO or rights also records the
 * subscription money that never passed through the broker — un-marking removes
 * it again.
 */
export function AcquisitionChannelCard({ portfolioId }: { portfolioId: number }) {
  const client = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [onlyUnconfirmed, setOnlyUnconfirmed] = useState(false)

  const overview = useQuery({
    queryKey: ['channels', portfolioId],
    queryFn: async () =>
      (await api.get<{ data: ChannelOverview }>(`/portfolios/${portfolioId}/channels`)).data.data,
  })

  const profit = useQuery({
    queryKey: ['channel-profit', portfolioId],
    queryFn: async () =>
      (await api.get<{ data: ChannelProfit }>(`/portfolios/${portfolioId}/channels/profit`)).data.data,
  })

  function afterChange() {
    setError(null)
    void client.invalidateQueries({ queryKey: ['channels', portfolioId] })
    void client.invalidateQueries({ queryKey: ['channel-profit', portfolioId] })
    invalidatePortfolioMoney(client, portfolioId)
  }

  const setChannel = useMutation({
    mutationFn: async ({ id, channel, price }: { id: number; channel: string; price?: number }) =>
      api.patch(`/portfolios/${portfolioId}/channels/${id}`, { channel, price }),
    onSuccess: afterChange,
    onError: (caught) => setError(errorMessage(caught)),
  })

  const detect = useMutation({
    mutationFn: async () => api.post(`/portfolios/${portfolioId}/channels/detect`),
    onSuccess: afterChange,
    onError: (caught) => setError(errorMessage(caught)),
  })

  if (overview.isLoading) return <Spinner label="Loading acquisition channels…" />
  if (overview.isError) {
    return <ErrorBox message={errorMessage(overview.error)} onRetry={() => overview.refetch()} />
  }
  if (overview.data === undefined) return null

  const data = overview.data

  // An empty portfolio, not a filtered-away table. Without this the page showed
  // "0 of 0 parcels not yet confirmed" over an empty grid and a disabled
  // button, which reads as a broken screen rather than as nothing to do yet.
  if (data.transactions.length === 0) {
    return (
      <SetupNeeded
        title="Nothing to classify yet"
        because="This page separates shares allotted to you at par from shares you chose to buy, which is what makes a trading result mean anything. It needs parcels to separate."
      />
    )
  }

  const rows = onlyUnconfirmed ? data.transactions.filter((t) => !t.is_confirmed) : data.transactions

  return (
    <div className="space-y-6">
      <Card
        title="IPO or traded"
        subtitle="An allotment at par and a scrip you chose are different activities. Only the second is evidence about your stock picking."
        action={
          <button
            type="button"
            className="btn-secondary"
            disabled={detect.isPending || data.broker_traded_symbols.length === 0}
            onClick={() => detect.mutate()}
          >
            {detect.isPending ? 'Classifying…' : 'Suggest from evidence'}
          </button>
        }
      >
        {error !== null && <ErrorBox message={error} />}

        {data.note !== null && (
          <p className="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            {data.note}
          </p>
        )}

        {data.capital_summary.rows > 0 && (
          <p className="mb-4 rounded border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
            <strong>{data.capital_summary.rows}</strong> subscription payment(s) totalling{' '}
            <strong>{money(data.capital_summary.capital)}</strong> have been added to your ledger,
            because IPO and rights money is paid from your bank and never appears in a broker
            statement. Change a parcel away from IPO or rights and its payment is removed again.
          </p>
        )}

        <div className="mb-3 flex items-center justify-between gap-3">
          <p className="text-xs text-slate-500">
            {data.unconfirmed_count} of {data.transactions.length} parcels not yet confirmed by you.
          </p>
          <label className="flex items-center gap-2 text-xs text-slate-500">
            <input
              type="checkbox"
              checked={onlyUnconfirmed}
              onChange={(event) => setOnlyUnconfirmed(event.target.checked)}
            />
            Only unconfirmed
          </label>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-2 py-2">Scrip</th>
                <th className="px-2 py-2 text-right">Units</th>
                <th className="px-2 py-2 text-right"><Explain term="par-value">Price</Explain></th>
                <th className="px-2 py-2">Date</th>
                <th className="px-2 py-2"><Explain term="acquisition-channel">How acquired</Explain></th>
                <th className="px-2 py-2">Capital</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <ChannelRow
                  key={row.id}
                  row={row}
                  options={data.channels}
                  saving={setChannel.isPending}
                  onSelect={(channel) => setChannel.mutate({ id: row.id, channel })}
                  onPrice={(price) => setChannel.mutate({ id: row.id, channel: row.channel, price })}
                />
              ))}
            </tbody>
          </table>
        </div>

        {rows.length === 0 && (
          <p className="py-4 text-sm text-slate-500">Nothing to show with this filter.</p>
        )}

        <p className="mt-4 text-xs text-slate-400">
          A choice you make here is final — later suggestions leave it alone. Anything the evidence
          cannot settle stays <em>unknown</em> rather than being guessed at.
        </p>
      </Card>

      <ChannelProfitCard profit={profit.data} isLoading={profit.isLoading} />
    </div>
  )
}

function ChannelRow({
  row,
  options,
  saving,
  onSelect,
  onPrice,
}: {
  row: ChannelTransaction
  options: { value: string; label: string }[]
  saving: boolean
  onSelect: (channel: string) => void
  onPrice: (price: number) => void
}) {
  return (
    <tr className="border-b border-slate-100">
      <td className="px-2 py-2 font-medium text-slate-900">{row.symbol}</td>
      <td className="num px-2 py-2 text-right">{row.quantity}</td>
      <td className="px-2 py-2 text-right">
        {row.price > 0 ? (
          <span className="num">{money(row.price)}</span>
        ) : (
          <PriceEntry saving={saving} onSave={onPrice} />
        )}
      </td>
      <td className="px-2 py-2 text-slate-500">{shortDate(row.transacted_on)}</td>
      <td className="px-2 py-2">
        <select
          className="input py-1 text-xs"
          value={row.channel}
          disabled={saving}
          onChange={(event) => onSelect(event.target.value)}
        >
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
          {row.is_confirmed ? (
            <span className="text-emerald-700">confirmed by you</span>
          ) : row.channel_basis !== null ? (
            <span className="text-slate-400">inferred: {row.channel_basis.replace(/_/g, ' ')}</span>
          ) : (
            <span className="text-amber-700">not determined</span>
          )}
          {row.differs_from_suggestion && !row.is_confirmed && (
            <button
              type="button"
              className="text-sky-700 underline"
              disabled={saving}
              onClick={() => onSelect(row.suggested_channel)}
            >
              suggestion: {row.suggested_channel}
            </button>
          )}
        </div>
      </td>
      <td className="px-2 py-2 text-xs">
        {row.capital_unknowable ? (
          <span className="text-amber-700" title="No price was recorded for this parcel, so its cost cannot be derived.">
            cost not recorded
          </span>
        ) : row.funded_outside_broker ? (
          <span className={clsx(row.capital_recorded ? 'text-emerald-700' : 'text-slate-400')}>
            {row.capital_recorded ? 'added: ' : 'would add: '}
            <Npr value={row.capital ?? 0} />
          </span>
        ) : row.channel === 'secondary' ? (
          row.cash_unaccounted ? (
            /*
             * "via broker" asserted a settlement that is not in the ledger.
             * These parcels were bought through a second broker, and their
             * cost is the whole of the contributed-capital shortfall.
             */
            <span
              className="text-amber-700"
              title="Bought on the market, but no settlement for it is in your cash ledger. Mutual funds have no broker statement, so this money has to be entered by hand before it counts as capital you contributed."
            >
              cash not recorded
            </span>
          ) : (
            <span className="text-slate-400">via broker</span>
          )
        ) : (
          /*
           * An unset channel is not the same as "the broker paid for it".
           * Saying "via broker" here asserted something about three mutual
           * funds the statement never touched.
           */
          <span className="text-slate-400" title="Set a channel to say where this money came from.">
            —
          </span>
        )}
      </td>
    </tr>
  )
}

function ChannelProfitCard({
  profit,
  isLoading,
}: {
  profit: ChannelProfit | undefined
  isLoading: boolean
}) {
  if (isLoading) {
    return (
      <Card title="Profit by how it was acquired">
        <Spinner label="Splitting profit by channel…" />
      </Card>
    )
  }

  if (profit === undefined) return null

  const priced = profit.market_priced

  return (
    <Card
      title="Profit by how it was acquired"
      subtitle="Open positions only, split by the channel the remaining units actually came from."
    >
      {!profit.coverage.complete && profit.coverage.note !== null && (
        <p className="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <strong>Partial.</strong> {profit.coverage.note} Counting them at cost would report a
          silent 0% on those parcels.
        </p>
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
              <th className="px-2 py-2">Channel</th>
              <th className="px-2 py-2 text-right">Cost</th>
              <th className="px-2 py-2 text-right">Value</th>
              <th className="px-2 py-2 text-right">Gain</th>
              <th className="px-2 py-2 text-right">Return</th>
              <th className="px-2 py-2 text-right">Share</th>
            </tr>
          </thead>
          <tbody>
            {profit.channels.map((bucket) => (
              <tr
                key={bucket.channel}
                className={clsx('border-b border-slate-100', bucket.is_market_priced && 'bg-slate-50')}
              >
                <td className="px-2 py-2 font-medium text-slate-900">
                  {bucket.short_label}
                  {bucket.is_market_priced && (
                    <span className="ml-2 text-xs font-normal text-slate-400">your picking</span>
                  )}
                </td>
                <td className="num px-2 py-2 text-right">{compactMoney(bucket.cost_basis)}</td>
                <td className="num px-2 py-2 text-right">{compactMoney(bucket.market_value)}</td>
                <td
                  className={clsx(
                    'num px-2 py-2 text-right font-semibold',
                    bucket.gain >= 0 ? 'text-gain' : 'text-loss',
                  )}
                >
                  {compactMoney(bucket.gain)}
                </td>
                <td className="num px-2 py-2 text-right">
                  {bucket.return_percent === null ? (
                    <span className="text-slate-400">n/a</span>
                  ) : (
                    `${bucket.return_percent}%`
                  )}
                </td>
                <td className="num px-2 py-2 text-right text-slate-500">
                  {bucket.share_of_cost_percent === null ? 'n/a' : `${bucket.share_of_cost_percent}%`}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-4 rounded border border-slate-200 bg-white p-3">
        <p className="text-xs uppercase tracking-wide text-slate-400">
          Your stock picking, on its own
        </p>
        <p
          className={clsx(
            'num mt-1 text-2xl font-semibold',
            (priced.gain ?? 0) >= 0 ? 'text-gain' : 'text-loss',
          )}
        >
          {priced.gain === null ? 'n/a' : compactMoney(priced.gain)}
          {priced.return_percent !== null && (
            <span className="ml-2 text-base font-normal text-slate-500">
              {priced.return_percent}%
            </span>
          )}
        </p>
        <p className="mt-2 text-xs text-slate-500">{priced.note}</p>
      </div>

      {!profit.reconciles && (
        <p className="mt-3 rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800">
          The channel gains do not sum to the total — off by {money(profit.reconciliation_error)}.
          A parcel is unaccounted for; treat these figures as unreliable.
        </p>
      )}
    </Card>
  )
}

/**
 * Enter what an unpriced parcel actually cost.
 *
 * A parcel at zero is not a cosmetic gap. It contributes nothing to the
 * money-weighted return, so it reads as free shares, and its entire sale
 * proceeds count as gain, which overstates the capital-gains bill — on this
 * portfolio, across seven parcels.
 *
 * Deliberately not defaulted to par. A bonus share really did cost nothing and
 * an IPO lot cost Rs 100, and only the holder knows which this was; filling in
 * par for both would swap a visible wrong number for an invisible one. Zero is
 * accepted as a real answer, which is why the field starts empty rather than
 * at 0 — an empty field means "not yet answered".
 */
function PriceEntry({ saving, onSave }: { saving: boolean; onSave: (price: number) => void }) {
  const [value, setValue] = useState('')
  const [open, setOpen] = useState(false)

  if (!open) {
    return (
      <button
        type="button"
        className="text-xs text-sky-700 underline decoration-dotted"
        onClick={() => setOpen(true)}
        title="No price was recorded for this parcel, so its cost and tax are wrong until you set one."
      >
        set price
      </button>
    )
  }

  const parsed = Number(value)
  const valid = value.trim() !== '' && Number.isFinite(parsed) && parsed >= 0

  return (
    <form
      className="flex items-center justify-end gap-1"
      onSubmit={(event) => {
        event.preventDefault()
        if (valid) {
          onSave(parsed)
          setOpen(false)
        }
      }}
    >
      <input
        autoFocus
        className="input num w-24 py-1 text-right text-xs"
        inputMode="decimal"
        placeholder="per unit"
        value={value}
        disabled={saving}
        onChange={(event) => setValue(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Escape') setOpen(false)
        }}
      />
      <button type="submit" className="text-xs text-sky-700 underline" disabled={!valid || saving}>
        save
      </button>
    </form>
  )
}
