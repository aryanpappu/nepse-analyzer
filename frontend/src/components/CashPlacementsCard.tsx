import { Fragment, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { money, percent, shortDate } from '../lib/format'
import { invalidatePortfolioMoney } from '../lib/portfolioQueries'
import { Banner, Card, EmptyState, ErrorBox, Spinner } from './ui'
import type {
  CashAccount,
  CashPlacement,
  CashPlacementListResponse,
  CashPlacementPayload,
  CashPlacementStatus,
  CashPlacementWithdrawPayload,
  CompoundingBasis,
  PayoutHandling,
  PlacementValuation,
} from '../lib/types'

const COMPOUNDING_OPTIONS: { value: CompoundingBasis; label: string }[] = [
  { value: 'simple', label: 'Simple (no compounding)' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'semi_annually', label: 'Half-yearly' },
  { value: 'annually', label: 'Yearly' },
]

const PAYOUT_OPTIONS: { value: PayoutHandling; label: string }[] = [
  { value: 'paid_out', label: 'Interest paid out to me' },
  { value: 'reinvested', label: 'Interest left to compound' },
]

/** Only the statuses a plain edit may set — closing goes through withdraw. */
const EDITABLE_STATUSES: { value: CashPlacementStatus; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'matured', label: 'Matured (not yet withdrawn)' },
  { value: 'broken_early', label: 'Broken early' },
]

const STATUS_STYLES: Record<CashPlacementStatus, string> = {
  active: 'bg-emerald-100 text-emerald-800',
  matured: 'bg-amber-100 text-amber-800',
  withdrawn: 'bg-slate-200 text-slate-600',
  broken_early: 'bg-red-100 text-red-800',
}

const STATUS_LABELS: Record<CashPlacementStatus, string> = {
  active: 'active',
  matured: 'matured',
  withdrawn: 'withdrawn',
  broken_early: 'broken early',
}

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

/** Whole days between two yyyy-mm-dd dates, or null if either is unparseable. */
function dayDiff(from: string, to: string): number | null {
  const start = Date.parse(`${from}T00:00:00Z`)
  const end = Date.parse(`${to}T00:00:00Z`)

  if (Number.isNaN(start) || Number.isNaN(end)) return null

  return Math.round((end - start) / 86_400_000)
}

function emptyToNull(value: string): string | null {
  return value.trim() === '' ? null : value.trim()
}

/**
 * The fixed-deposit register — money parked outside the equity book.
 *
 * The control that matters here is `settle_cash`. Recording a deposit does not
 * have to move any cash: without a settlement nothing is debited, so that
 * money is still counted as idle cash and the placement is deliberately kept
 * out of net worth. Counting both would count the same rupees twice. The API
 * says so in plain English and this card repeats it rather than burying it,
 * because a user who cannot see the rule reads the exclusion as a bug.
 */
export function CashPlacementsCard({
  portfolioId,
  valuation,
}: {
  portfolioId: number
  /** From the portfolio summary — carries which placements were cash-settled. */
  valuation: PlacementValuation | undefined
}) {
  const [mode, setMode] = useState<'none' | 'single' | 'bulk'>('none')
  const [editingId, setEditingId] = useState<number | null>(null)
  const [closingId, setClosingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const placements = useQuery({
    queryKey: ['cash-placements', portfolioId],
    queryFn: async () =>
      (
        await api.get<CashPlacementListResponse>(`/portfolios/${portfolioId}/cash-placements`)
      ).data,
  })

  const rows = placements.data?.data ?? []
  const meta = placements.data?.meta
  const asOf = meta?.as_of ?? todayIso()

  /* Whether a placement's principal actually left a cash account is only
     reported by the valuation on the portfolio summary, not by the placement
     list, so the two are cross-referenced here by id. */
  const settled = new Map(
    (valuation?.placements ?? []).map((row) => [row.id, row]),
  )

  const hasUnsettled = valuation !== undefined && valuation.unsettled_count > 0

  function closeForms(message: string | null) {
    setMode('none')
    setEditingId(null)
    setClosingId(null)
    setDeletingId(null)
    setNotice(message)
  }

  return (
    <Card
      title="Fixed deposits"
      subtitle={
        meta === undefined
          ? 'Money parked outside the equity book.'
          : `${meta.count} on record · interest accrued to ${shortDate(meta.as_of)} on an Actual/365 basis`
      }
      action={
        <div className="flex gap-2">
          <button
            type="button"
            className="btn-secondary"
            onClick={() => {
              closeForms(null)
              setMode(mode === 'single' ? 'none' : 'single')
            }}
          >
            Add a deposit
          </button>
          <button
            type="button"
            className="btn-secondary"
            onClick={() => {
              closeForms(null)
              setMode(mode === 'bulk' ? 'none' : 'bulk')
            }}
          >
            Add many
          </button>
        </div>
      }
    >
      {notice !== null && (
        <div className="mb-3">
          <Banner tone="success">{notice}</Banner>
        </div>
      )}

      {meta !== undefined && meta.count > 0 && (
        <dl className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <Tile label="Principal placed" value={`NPR ${money(meta.total_principal)}`} />
          <Tile label="Interest accrued" value={`NPR ${money(meta.total_accrued_interest)}`} />
          <Tile
            label="Weighted avg rate"
            value={
              meta.weighted_average_rate === null ? 'n/a' : percent(meta.weighted_average_rate, 3)
            }
            hint={
              meta.weighted_average_rate === null
                ? 'No active interest-bearing capital placed'
                : 'Across active deposits only'
            }
          />
          <Tile label="Matured" value={String(meta.matured_count)} />
          <Tile
            label={`Maturing in ${meta.maturing_soon_within_days}d`}
            value={String(meta.maturing_soon_count)}
            tone={meta.maturing_soon_count > 0 ? 'amber' : undefined}
          />
        </dl>
      )}

      {/* The settle-cash rule, stated where the deposits are, not only on the
          net-worth card. */}
      {valuation !== undefined && valuation.count > 0 && valuation.note !== null && (
        <div className="mb-4">
          <Banner tone={hasUnsettled ? 'warning' : 'info'}>
            <p className="font-semibold">
              {hasUnsettled
                ? 'Some deposits are not counted in net worth'
                : 'These deposits are counted in net worth'}
            </p>
            <p className="mt-1">{valuation.note}</p>
          </Banner>
        </div>
      )}

      {mode === 'single' && (
        <div className="mb-4">
          <PlacementForm
            portfolioId={portfolioId}
            onCancel={() => setMode('none')}
            onDone={(message) => closeForms(message)}
          />
        </div>
      )}

      {mode === 'bulk' && (
        <div className="mb-4">
          <BulkPlacementForm
            portfolioId={portfolioId}
            onCancel={() => setMode('none')}
            onDone={(message) => closeForms(message)}
          />
        </div>
      )}

      {placements.isLoading ? (
        <Spinner label="Loading your deposits…" />
      ) : placements.isError ? (
        <ErrorBox message={errorMessage(placements.error)} onRetry={() => placements.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No fixed deposits recorded"
          hint="Add one to have its interest accrued daily and reported next to the equity return. Recording several at once is quicker with “Add many”."
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[60rem]">
            <thead className="border-b border-slate-200 bg-slate-50">
              <tr>
                <th className="th">Deposit</th>
                <th className="th text-right">Principal</th>
                <th className="th text-right">Rate</th>
                <th className="th">Term</th>
                <th className="th text-right">To maturity</th>
                <th className="th text-right">Interest earned</th>
                <th className="th">In net worth</th>
                <th className="th text-right">Manage</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => {
                const valued = settled.get(row.id)
                const diff = row.maturity_date === null ? null : dayDiff(asOf, row.maturity_date)
                const soon =
                  diff !== null &&
                  diff > 0 &&
                  meta !== undefined &&
                  diff <= meta.maturing_soon_within_days

                return (
                  <Fragment key={row.id}>
                    <tr className="align-top hover:bg-slate-50">
                      <td className="td">
                        <span className="block font-semibold text-slate-800">
                          {row.institution ?? `Deposit #${row.id}`}
                        </span>
                        <span className="block text-xs text-slate-500">
                          {row.reference ?? '—'}
                        </span>
                        <span className="mt-1 flex flex-wrap gap-1">
                          <span className={clsx('badge', STATUS_STYLES[row.status])}>
                            {STATUS_LABELS[row.status]}
                          </span>
                          {row.payout === 'reinvested' && (
                            <span
                              className="badge bg-sky-100 text-sky-800"
                              title="Interest is added back and itself earns"
                            >
                              compounding
                            </span>
                          )}
                          {row.kind !== 'fixed_deposit' && (
                            <span className="badge bg-violet-100 text-violet-800">{row.kind}</span>
                          )}
                        </span>
                      </td>
                      <td className="td num text-right font-medium">{money(row.principal)}</td>
                      <td className="td num text-right">{percent(row.interest_rate)}</td>
                      <td className="td text-xs text-slate-500">
                        {shortDate(row.start_date)} →{' '}
                        {row.maturity_date === null ? (
                          <span className="text-slate-300" title="No maturity date recorded">
                            n/a
                          </span>
                        ) : (
                          shortDate(row.maturity_date)
                        )}
                        {row.closed_on !== null && (
                          <span className="block">closed {shortDate(row.closed_on)}</span>
                        )}
                        <span className="block">
                          {COMPOUNDING_OPTIONS.find((o) => o.value === row.compounding_basis)
                            ?.label ?? row.compounding_basis}
                        </span>
                      </td>
                      <td
                        className={clsx(
                          'td num text-right',
                          soon && 'font-semibold text-amber-700',
                        )}
                      >
                        {diff === null ? (
                          <span className="text-slate-300">n/a</span>
                        ) : diff > 0 ? (
                          `${diff}d`
                        ) : diff === 0 ? (
                          'today'
                        ) : (
                          <span className="text-slate-500">{-diff}d ago</span>
                        )}
                      </td>
                      <td className="td num text-right">
                        {row.accrual === undefined ? (
                          <span className="text-slate-300">n/a</span>
                        ) : (
                          <>
                            <span className="font-medium text-gain">
                              {money(row.accrual.accrued_interest)}
                            </span>
                            <span className="block text-xs text-slate-400">
                              {row.accrual.days_accrued}d
                              {row.accrual.is_capped_at_maturity && ' · to maturity'}
                            </span>
                          </>
                        )}
                      </td>
                      <td className="td">
                        {valued === undefined ? (
                          <span
                            className="text-xs text-slate-300"
                            title="The portfolio summary has not reported on this placement yet"
                          >
                            n/a
                          </span>
                        ) : valued.counts_toward_net_worth ? (
                          <span className="badge bg-emerald-100 text-emerald-800">counted</span>
                        ) : (
                          <span
                            className="badge bg-amber-100 text-amber-800"
                            title={valued.reason ?? undefined}
                          >
                            not counted
                          </span>
                        )}
                      </td>
                      <td className="td text-right">
                        <span className="inline-flex flex-wrap justify-end gap-1">
                          <button
                            type="button"
                            className="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                            onClick={() => {
                              closeForms(null)
                              setEditingId(editingId === row.id ? null : row.id)
                            }}
                          >
                            Edit
                          </button>
                          {row.status !== 'withdrawn' && (
                            <button
                              type="button"
                              className="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                              onClick={() => {
                                closeForms(null)
                                setClosingId(closingId === row.id ? null : row.id)
                              }}
                            >
                              Close
                            </button>
                          )}
                          <button
                            type="button"
                            className="rounded border border-red-200 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-50"
                            onClick={() => {
                              closeForms(null)
                              setDeletingId(deletingId === row.id ? null : row.id)
                            }}
                          >
                            Delete
                          </button>
                        </span>
                      </td>
                    </tr>

                    {valued !== undefined && valued.reason !== null && (
                      <tr className="bg-amber-50/60">
                        <td colSpan={8} className="px-3 py-1.5 text-xs text-amber-900">
                          {valued.reason}
                        </td>
                      </tr>
                    )}

                    {row.notes !== null && (
                      <tr>
                        <td colSpan={8} className="px-3 pb-2 text-xs text-slate-500">
                          {row.notes}
                        </td>
                      </tr>
                    )}

                    {editingId === row.id && (
                      <tr className="bg-slate-50">
                        <td colSpan={8} className="px-3 py-3">
                          <PlacementForm
                            portfolioId={portfolioId}
                            placement={row}
                            onCancel={() => setEditingId(null)}
                            onDone={(message) => closeForms(message)}
                          />
                        </td>
                      </tr>
                    )}

                    {closingId === row.id && (
                      <tr className="bg-slate-50">
                        <td colSpan={8} className="px-3 py-3">
                          <WithdrawForm
                            portfolioId={portfolioId}
                            placement={row}
                            settledOnOpen={valued?.cash_settled}
                            onCancel={() => setClosingId(null)}
                            onDone={(message) => closeForms(message)}
                          />
                        </td>
                      </tr>
                    )}

                    {deletingId === row.id && (
                      <tr className="bg-red-50/60">
                        <td colSpan={8} className="px-3 py-3">
                          <DeleteConfirm
                            portfolioId={portfolioId}
                            placement={row}
                            onCancel={() => setDeletingId(null)}
                            onDone={(message) => closeForms(message)}
                          />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

function Tile({
  label,
  value,
  hint,
  tone,
}: {
  label: string
  value: string
  hint?: string
  tone?: 'amber'
}) {
  return (
    <div className={clsx('rounded-lg p-3', tone === 'amber' ? 'bg-amber-50' : 'bg-slate-50')}>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd
        className={clsx(
          'num mt-1 text-lg font-semibold',
          tone === 'amber' ? 'text-amber-800' : 'text-slate-900',
        )}
      >
        {value}
      </dd>
      {hint !== undefined && <dd className="mt-0.5 text-xs text-slate-400">{hint}</dd>}
    </div>
  )
}

/**
 * Create one deposit, or edit an existing one.
 *
 * Editing deliberately does not move cash: it changes what the deposit says,
 * not where the money is. Amending a settled deposit's principal therefore
 * leaves its original cash debit at the old figure — correct that in the cash
 * ledger, which replays the account rather than patching it.
 */
function PlacementForm({
  portfolioId,
  placement,
  onDone,
  onCancel,
}: {
  portfolioId: number
  placement?: CashPlacement
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const queryClient = useQueryClient()
  const editing = placement !== undefined

  const [institution, setInstitution] = useState(placement?.institution ?? '')
  const [reference, setReference] = useState(placement?.reference ?? '')
  const [principal, setPrincipal] = useState(
    placement === undefined ? '' : String(placement.principal),
  )
  const [rate, setRate] = useState(placement === undefined ? '' : String(placement.interest_rate))
  const [startDate, setStartDate] = useState(placement?.start_date ?? todayIso())
  const [maturityDate, setMaturityDate] = useState(placement?.maturity_date ?? '')
  const [compounding, setCompounding] = useState<CompoundingBasis>(
    placement?.compounding_basis ?? 'simple',
  )
  const [payout, setPayout] = useState<PayoutHandling>(placement?.payout ?? 'paid_out')
  const [status, setStatus] = useState<CashPlacementStatus>(placement?.status ?? 'active')
  const [notes, setNotes] = useState(placement?.notes ?? '')
  const [settleCash, setSettleCash] = useState(false)
  const [cashAccount, setCashAccount] = useState<CashAccount>('bank')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: CashPlacementPayload = {
        institution: emptyToNull(institution),
        reference: emptyToNull(reference),
        principal: Number(principal),
        start_date: startDate,
        maturity_date: emptyToNull(maturityDate),
        compounding_basis: compounding,
        payout,
        notes: emptyToNull(notes),
      }

      // Left blank, the rate falls back to the configured deposit benchmark
      // rate rather than to a fabricated zero.
      if (rate.trim() !== '') {
        payload.interest_rate = Number(rate)
      }

      if (editing) {
        payload.status = status

        return (
          await api.patch<{ message: string }>(
            `/portfolios/${portfolioId}/cash-placements/${placement.id}`,
            payload,
          )
        ).data
      }

      payload.settle_cash = settleCash
      payload.cash_account = cashAccount

      return (
        await api.post<{ message: string }>(
          `/portfolios/${portfolioId}/cash-placements`,
          payload,
        )
      ).data
    },
    onSuccess: (data) => {
      invalidatePortfolioMoney(queryClient, portfolioId)
      onDone(data.message)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const idPrefix = editing ? `edit-${placement.id}` : 'new'

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        mutation.mutate()
      }}
      className="space-y-3"
    >
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label className="label" htmlFor={`${idPrefix}-institution`}>
            Bank / institution
          </label>
          <input
            id={`${idPrefix}-institution`}
            className="input"
            maxLength={120}
            value={institution}
            onChange={(event) => setInstitution(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-reference`}>
            Receipt / account no.
          </label>
          <input
            id={`${idPrefix}-reference`}
            className="input"
            maxLength={120}
            value={reference}
            onChange={(event) => setReference(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-principal`}>
            Principal (NPR)
          </label>
          <input
            id={`${idPrefix}-principal`}
            type="number"
            step="0.01"
            min="0.01"
            className="input"
            value={principal}
            onChange={(event) => setPrincipal(event.target.value)}
            required
          />
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-rate`}>
            Annual rate (%)
          </label>
          <input
            id={`${idPrefix}-rate`}
            type="number"
            step="0.01"
            min="0"
            max="100"
            className="input"
            placeholder="benchmark rate"
            value={rate}
            onChange={(event) => setRate(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-start`}>
            Start date
          </label>
          <input
            id={`${idPrefix}-start`}
            type="date"
            className="input"
            max={todayIso()}
            value={startDate}
            onChange={(event) => setStartDate(event.target.value)}
            required
          />
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-maturity`}>
            Maturity date
          </label>
          <input
            id={`${idPrefix}-maturity`}
            type="date"
            className="input"
            value={maturityDate}
            onChange={(event) => setMaturityDate(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-compounding`}>
            Compounding
          </label>
          <select
            id={`${idPrefix}-compounding`}
            className="input"
            value={compounding}
            onChange={(event) => setCompounding(event.target.value as CompoundingBasis)}
          >
            {COMPOUNDING_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="label" htmlFor={`${idPrefix}-payout`}>
            Interest handling
          </label>
          <select
            id={`${idPrefix}-payout`}
            className="input"
            value={payout}
            onChange={(event) => setPayout(event.target.value as PayoutHandling)}
          >
            {PAYOUT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        {editing && (
          <div>
            <label className="label" htmlFor={`${idPrefix}-status`}>
              Status
            </label>
            <select
              id={`${idPrefix}-status`}
              className="input"
              value={status}
              onChange={(event) => setStatus(event.target.value as CashPlacementStatus)}
            >
              {EDITABLE_STATUSES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        )}

        <div className={clsx(editing ? 'lg:col-span-3' : 'lg:col-span-4')}>
          <label className="label" htmlFor={`${idPrefix}-notes`}>
            Notes
          </label>
          <input
            id={`${idPrefix}-notes`}
            className="input"
            maxLength={1000}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
        </div>
      </div>

      {editing ? (
        <p className="text-xs leading-relaxed text-slate-500">
          Editing changes what the deposit says, not where the money is — no cash is moved. If this
          deposit was opened with a cash settlement and you change the principal, amend the matching
          row in the cash ledger too. To close it, use <strong>Close</strong> rather than setting a
          status here: closing stops the accrual on a date and returns the money.
        </p>
      ) : (
        <div className="rounded-lg border border-sky-200 bg-sky-50 p-3">
          <label className="flex items-start gap-2 text-sm text-sky-900">
            <input
              type="checkbox"
              className="mt-1"
              checked={settleCash}
              onChange={(event) => setSettleCash(event.target.checked)}
            />
            <span>
              <span className="font-semibold">
                Take the principal out of my cash on the start date
              </span>
              <span className="mt-1 block text-xs leading-relaxed">
                Leave this unticked and nothing is debited: the money stays in your cash balance,
                so it is already counted there and this deposit is deliberately left{' '}
                <strong>out of net worth</strong> — otherwise the same rupees would be counted
                twice. Tick it and the principal moves out of the account below, and the deposit is
                counted as a deposit. Either way its interest is reported separately as income.
              </span>
            </span>
          </label>

          {settleCash && (
            <div className="mt-3 max-w-xs">
              <label className="label" htmlFor={`${idPrefix}-account`}>
                Debit from
              </label>
              <select
                id={`${idPrefix}-account`}
                className="input"
                value={cashAccount}
                onChange={(event) => setCashAccount(event.target.value as CashAccount)}
              >
                <option value="bank">Bank</option>
                <option value="cash">Cash in hand</option>
              </select>
              <p className="mt-1 text-xs text-sky-800">
                Refused if it would overdraw the account — the deposit is rolled back rather than
                recorded against money you did not have.
              </p>
            </div>
          )}
        </div>
      )}

      {error !== null && <ErrorBox message={error} />}

      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={mutation.isPending}>
          {mutation.isPending ? 'Saving…' : editing ? 'Save changes' : 'Record deposit'}
        </button>
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}

interface BulkRow {
  key: number
  institution: string
  reference: string
  principal: string
  rate: string
  startDate: string
  maturityDate: string
}

function blankRow(key: number): BulkRow {
  return {
    key,
    institution: '',
    reference: '',
    principal: '',
    rate: '',
    startDate: todayIso(),
    maturityDate: '',
  }
}

/**
 * Enter a stack of existing deposits in one pass.
 *
 * One request, and the API validates every row before writing any of them —
 * a single bad row fails the whole batch with the offending row named, rather
 * than leaving half a book entered.
 */
function BulkPlacementForm({
  portfolioId,
  onDone,
  onCancel,
}: {
  portfolioId: number
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const queryClient = useQueryClient()
  const [rows, setRows] = useState<BulkRow[]>([blankRow(1), blankRow(2), blankRow(3)])
  const [nextKey, setNextKey] = useState(4)
  const [compounding, setCompounding] = useState<CompoundingBasis>('simple')
  const [payout, setPayout] = useState<PayoutHandling>('paid_out')
  const [settleCash, setSettleCash] = useState(false)
  const [cashAccount, setCashAccount] = useState<CashAccount>('bank')
  const [error, setError] = useState<string | null>(null)

  function update(key: number, patch: Partial<BulkRow>) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)))
  }

  const mutation = useMutation({
    mutationFn: async () => {
      const placements: CashPlacementPayload[] = rows.map((row) => {
        const payload: CashPlacementPayload = {
          institution: emptyToNull(row.institution),
          reference: emptyToNull(row.reference),
          principal: Number(row.principal),
          start_date: row.startDate,
          maturity_date: emptyToNull(row.maturityDate),
          compounding_basis: compounding,
          payout,
          settle_cash: settleCash,
          cash_account: cashAccount,
        }

        if (row.rate.trim() !== '') {
          payload.interest_rate = Number(row.rate)
        }

        return payload
      })

      return (
        await api.post<{ message: string }>(
          `/portfolios/${portfolioId}/cash-placements/bulk`,
          { placements },
        )
      ).data
    },
    onSuccess: (data) => {
      invalidatePortfolioMoney(queryClient, portfolioId)
      onDone(data.message)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        mutation.mutate()
      }}
      className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3"
    >
      <p className="text-sm font-semibold text-slate-800">Add several deposits at once</p>
      <p className="text-xs leading-relaxed text-slate-500">
        Compounding, interest handling and the cash settlement below apply to every row. Either the
        whole batch is recorded or none of it is, so one wrong figure cannot leave a half-entered
        book.
      </p>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[48rem]">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-2 py-1">Bank</th>
              <th className="px-2 py-1">Reference</th>
              <th className="px-2 py-1">Principal</th>
              <th className="px-2 py-1">Rate %</th>
              <th className="px-2 py-1">Start</th>
              <th className="px-2 py-1">Maturity</th>
              <th className="px-2 py-1" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={row.key}>
                <td className="px-2 py-1">
                  <input
                    className="input"
                    aria-label={`Bank for row ${index + 1}`}
                    maxLength={120}
                    value={row.institution}
                    onChange={(event) => update(row.key, { institution: event.target.value })}
                  />
                </td>
                <td className="px-2 py-1">
                  <input
                    className="input"
                    aria-label={`Reference for row ${index + 1}`}
                    maxLength={120}
                    value={row.reference}
                    onChange={(event) => update(row.key, { reference: event.target.value })}
                  />
                </td>
                <td className="px-2 py-1">
                  <input
                    type="number"
                    step="0.01"
                    min="0.01"
                    className="input"
                    aria-label={`Principal for row ${index + 1}`}
                    value={row.principal}
                    onChange={(event) => update(row.key, { principal: event.target.value })}
                    required
                  />
                </td>
                <td className="px-2 py-1">
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    className="input"
                    aria-label={`Rate for row ${index + 1}`}
                    placeholder="default"
                    value={row.rate}
                    onChange={(event) => update(row.key, { rate: event.target.value })}
                  />
                </td>
                <td className="px-2 py-1">
                  <input
                    type="date"
                    className="input"
                    aria-label={`Start date for row ${index + 1}`}
                    max={todayIso()}
                    value={row.startDate}
                    onChange={(event) => update(row.key, { startDate: event.target.value })}
                    required
                  />
                </td>
                <td className="px-2 py-1">
                  <input
                    type="date"
                    className="input"
                    aria-label={`Maturity date for row ${index + 1}`}
                    value={row.maturityDate}
                    onChange={(event) => update(row.key, { maturityDate: event.target.value })}
                  />
                </td>
                <td className="px-2 py-1 text-right">
                  <button
                    type="button"
                    className="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-white disabled:opacity-40"
                    disabled={rows.length === 1}
                    onClick={() =>
                      setRows((current) => current.filter((item) => item.key !== row.key))
                    }
                  >
                    Remove
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <button
        type="button"
        className="btn-secondary"
        onClick={() => {
          setRows((current) => [...current, blankRow(nextKey)])
          setNextKey((key) => key + 1)
        }}
      >
        Add another row
      </button>

      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className="label" htmlFor="bulk-compounding">
            Compounding (all rows)
          </label>
          <select
            id="bulk-compounding"
            className="input"
            value={compounding}
            onChange={(event) => setCompounding(event.target.value as CompoundingBasis)}
          >
            {COMPOUNDING_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label className="label" htmlFor="bulk-payout">
            Interest handling (all rows)
          </label>
          <select
            id="bulk-payout"
            className="input"
            value={payout}
            onChange={(event) => setPayout(event.target.value as PayoutHandling)}
          >
            {PAYOUT_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="rounded-lg border border-sky-200 bg-sky-50 p-3">
        <label className="flex items-start gap-2 text-sm text-sky-900">
          <input
            type="checkbox"
            className="mt-1"
            checked={settleCash}
            onChange={(event) => setSettleCash(event.target.checked)}
          />
          <span>
            <span className="font-semibold">
              Take each principal out of my cash on its start date
            </span>
            <span className="mt-1 block text-xs leading-relaxed">
              Unticked, nothing is debited: that money stays in your cash balance and is already
              counted there, so these deposits are deliberately left out of net worth rather than
              counted twice. Ticked, every principal is debited and the deposits count as deposits
              — and any row that would overdraw the account fails the whole batch.
            </span>
          </span>
        </label>

        {settleCash && (
          <div className="mt-3 max-w-xs">
            <label className="label" htmlFor="bulk-account">
              Debit from
            </label>
            <select
              id="bulk-account"
              className="input"
              value={cashAccount}
              onChange={(event) => setCashAccount(event.target.value as CashAccount)}
            >
              <option value="bank">Bank</option>
              <option value="cash">Cash in hand</option>
            </select>
          </div>
        )}
      </div>

      {error !== null && <ErrorBox message={error} />}

      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={mutation.isPending}>
          {mutation.isPending ? 'Recording…' : `Record ${rows.length} deposits`}
        </button>
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}

/**
 * Close a deposit — at maturity, or broken early.
 *
 * Not an edit: it stops the accrual on a date and, when the deposit was opened
 * with a cash settlement, returns the principal (and reinvested interest) to
 * the cash ledger as its own rows.
 */
function WithdrawForm({
  portfolioId,
  placement,
  settledOnOpen,
  onDone,
  onCancel,
}: {
  portfolioId: number
  placement: CashPlacement
  /** Undefined when the summary has not reported on this placement yet. */
  settledOnOpen: boolean | undefined
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const queryClient = useQueryClient()
  const [closedOn, setClosedOn] = useState(todayIso())
  const [settleCash, setSettleCash] = useState(settledOnOpen ?? false)
  const [cashAccount, setCashAccount] = useState<CashAccount>('bank')
  const [includeInterest, setIncludeInterest] = useState(placement.payout === 'reinvested')
  const [notes, setNotes] = useState(placement.notes ?? '')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: CashPlacementWithdrawPayload = {
        closed_on: closedOn,
        settle_cash: settleCash,
        cash_account: cashAccount,
        include_interest: includeInterest,
        notes: emptyToNull(notes),
      }

      return (
        await api.post<{ message: string }>(
          `/portfolios/${portfolioId}/cash-placements/${placement.id}/withdraw`,
          payload,
        )
      ).data
    },
    onSuccess: (data) => {
      invalidatePortfolioMoney(queryClient, portfolioId)
      onDone(data.message)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        setError(null)
        mutation.mutate()
      }}
      className="space-y-3"
    >
      <p className="text-sm font-semibold text-slate-800">
        Close {placement.institution ?? `deposit #${placement.id}`}
      </p>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label className="label" htmlFor={`close-date-${placement.id}`}>
            Closed on
          </label>
          <input
            id={`close-date-${placement.id}`}
            type="date"
            className="input"
            max={todayIso()}
            min={placement.start_date}
            value={closedOn}
            onChange={(event) => setClosedOn(event.target.value)}
            required
          />
          <p className="mt-1 text-xs text-slate-500">Interest accrues up to this date, no further.</p>
        </div>
        <div>
          <label className="label" htmlFor={`close-account-${placement.id}`}>
            Return the money to
          </label>
          <select
            id={`close-account-${placement.id}`}
            className="input"
            value={cashAccount}
            disabled={!settleCash}
            onChange={(event) => setCashAccount(event.target.value as CashAccount)}
          >
            <option value="bank">Bank</option>
            <option value="cash">Cash in hand</option>
          </select>
        </div>
        <div className="lg:col-span-2">
          <label className="label" htmlFor={`close-notes-${placement.id}`}>
            Notes
          </label>
          <input
            id={`close-notes-${placement.id}`}
            className="input"
            maxLength={1000}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
        </div>
      </div>

      <label className="flex items-start gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          className="mt-1"
          checked={settleCash}
          onChange={(event) => setSettleCash(event.target.checked)}
        />
        <span>
          Credit the money back into my cash account
          <span className="mt-0.5 block text-xs text-slate-500">
            {settledOnOpen === true
              ? 'This deposit was opened with a cash debit, so returning the money keeps the account reconciled.'
              : settledOnOpen === false
                ? 'This deposit never debited cash, so crediting it back now would create money that never left. Normally leave this unticked.'
                : 'Whether this deposit debited cash on opening is not known here — tick this only if it did.'}
          </span>
        </span>
      </label>

      <label className="flex items-start gap-2 text-sm text-slate-700">
        <input
          type="checkbox"
          className="mt-1"
          checked={includeInterest}
          onChange={(event) => setIncludeInterest(event.target.checked)}
        />
        <span>
          Also credit the interest earned
          <span className="mt-0.5 block text-xs text-slate-500">
            {placement.payout === 'reinvested'
              ? 'Interest was left to compound, so it is still inside the deposit and comes back with the principal.'
              : 'Interest on this deposit was paid out during the term and is already in the ledger — crediting it again would pay it twice.'}
          </span>
        </span>
      </label>

      {error !== null && <ErrorBox message={error} />}

      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={mutation.isPending}>
          {mutation.isPending ? 'Closing…' : 'Close deposit'}
        </button>
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}

/** Hard delete, unlike voiding a cash row — so it says so. */
function DeleteConfirm({
  portfolioId,
  placement,
  onDone,
  onCancel,
}: {
  portfolioId: number
  placement: CashPlacement
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const queryClient = useQueryClient()
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () =>
      (
        await api.delete<{ message: string }>(
          `/portfolios/${portfolioId}/cash-placements/${placement.id}`,
        )
      ).data,
    onSuccess: (data) => {
      invalidatePortfolioMoney(queryClient, portfolioId)
      onDone(data.message)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  return (
    <div className="space-y-3">
      <p className="text-sm text-red-900">
        Delete this deposit record outright? Unlike voiding a cash entry, nothing is kept — it
        disappears from the register and from every interest figure. Any cash movement it made
        stays in the ledger and must be corrected there. If the deposit really ended, use{' '}
        <strong>Close</strong> instead, which dates the exit and keeps the history.
      </p>

      {error !== null && <ErrorBox message={error} />}

      <div className="flex gap-2">
        <button
          type="button"
          className="btn-danger"
          disabled={mutation.isPending}
          onClick={() => {
            setError(null)
            mutation.mutate()
          }}
        >
          {mutation.isPending ? 'Deleting…' : 'Delete record'}
        </button>
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </div>
  )
}
