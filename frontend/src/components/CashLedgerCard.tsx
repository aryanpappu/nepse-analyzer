import { Fragment, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { money, shortDate, titleCase } from '../lib/format'
import { invalidatePortfolioMoney } from '../lib/portfolioQueries'
import { Banner, Card, EmptyState, ErrorBox, Spinner } from './ui'
import type {
  CashAccount,
  CashAmendPayload,
  CashLedgerReport,
  CashTransactionRow,
} from '../lib/types'

/*
 * Types the ledger writes itself. `titleCase` handles anything new, so an
 * unrecognised type degrades to a readable label rather than to a blank cell.
 */
const TYPE_LABELS: Record<string, string> = {
  deposit: 'Deposit',
  withdrawal: 'Withdrawal',
  trade_debit: 'Trade — paid',
  trade_credit: 'Trade — received',
  placement_debit: 'Deposit placed',
  placement_credit: 'Deposit returned',
  placement_interest: 'Deposit interest',
}

const ACCOUNT_LABELS: Record<CashAccount, string> = {
  bank: 'Bank',
  cash: 'Cash in hand',
}

function accountLabel(account: CashAccount): string {
  return ACCOUNT_LABELS[account] ?? account
}

/** Signed rupees: the ledger stores the sign, an amendment sends a magnitude. */
function signedMoney(value: number): string {
  return `${value > 0 ? '+' : ''}${money(value)}`
}

/**
 * The cash ledger, editable in place.
 *
 * The reason this exists: the only previous way to fix a wrong balance was to
 * post a second, offsetting entry, which left two rows where there should have
 * been one and a history that no longer described what happened. A row is now
 * amended (its later balances replayed from the ledger, never patched) or
 * voided with a reason — and a voided row stays on screen, struck through,
 * precisely so the balance remains explainable.
 */
export function CashLedgerCard({ portfolioId }: { portfolioId: number }) {
  const [includeVoided, setIncludeVoided] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [voidingId, setVoidingId] = useState<number | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const ledger = useQuery({
    queryKey: ['cash-ledger', portfolioId, includeVoided],
    queryFn: async () =>
      (
        await api.get<{ data: CashLedgerReport }>(`/portfolios/${portfolioId}/cash`, {
          params: { include_voided: includeVoided ? 1 : undefined, limit: 200 },
        })
      ).data.data,
  })

  const rows = ledger.data?.transactions ?? []

  function closeForms(message: string | null) {
    setEditingId(null)
    setVoidingId(null)
    setNotice(message)
  }

  return (
    <Card
      title="Cash ledger"
      subtitle={
        ledger.data === undefined
          ? 'Every movement, amendable in place.'
          : `Bank NPR ${money(ledger.data.bank_balance)} · Cash in hand NPR ${money(
              ledger.data.cash_in_hand,
            )} · Investable NPR ${money(ledger.data.investable_cash)}`
      }
      action={
        <label className="flex items-center gap-2 text-xs text-slate-500">
          <input
            type="checkbox"
            checked={includeVoided}
            onChange={(event) => {
              setIncludeVoided(event.target.checked)
              closeForms(null)
            }}
          />
          Show voided rows
        </label>
      }
    >
      {notice !== null && (
        <div className="mb-3">
          <Banner tone="success">{notice}</Banner>
        </div>
      )}

      <p className="mb-3 text-xs leading-relaxed text-slate-500">
        Fix a wrong entry by editing it, not by posting an opposite one. Editing rewrites every
        later running balance for that account from the ledger. Voiding keeps the row visible with
        its reason so the balance stays explainable — that is the point of voiding rather than
        deleting.
      </p>

      {ledger.isLoading ? (
        <Spinner label="Loading the ledger…" />
      ) : ledger.isError ? (
        <ErrorBox message={errorMessage(ledger.error)} onRetry={() => ledger.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title={includeVoided ? 'No cash movements recorded' : 'No live cash movements'}
          hint={
            includeVoided
              ? 'Use "Manage cash" above to record a deposit, a withdrawal or a transfer.'
              : 'Every row here has been voided — tick "Show voided rows" to see them.'
          }
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[52rem]">
            <thead className="border-b border-slate-200 bg-slate-50">
              <tr>
                <th className="th">Date</th>
                <th className="th">Type</th>
                <th className="th">Account</th>
                <th className="th text-right">Amount</th>
                <th className="th text-right">Balance after</th>
                <th className="th">Reference / notes</th>
                <th className="th text-right">Correct</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row) => (
                <Fragment key={row.id}>
                  <tr className={clsx(row.voided ? 'text-slate-400' : 'hover:bg-slate-50')}>
                    <td className={clsx('td', row.voided && 'line-through')}>
                      {shortDate(row.transacted_on)}
                    </td>
                    <td className={clsx('td', row.voided && 'line-through')}>
                      {TYPE_LABELS[row.type] ?? titleCase(row.type)}
                    </td>
                    <td className={clsx('td', row.voided && 'line-through')}>
                      {accountLabel(row.account)}
                    </td>
                    <td
                      className={clsx(
                        'td num text-right font-medium',
                        row.voided && 'line-through',
                        !row.voided && row.amount < 0 && 'text-loss',
                        !row.voided && row.amount > 0 && 'text-gain',
                      )}
                    >
                      {signedMoney(row.amount)}
                    </td>
                    <td className="td num text-right text-slate-500">
                      {row.voided ? (
                        <span className="text-slate-300" title="A voided row no longer carries a balance">
                          n/a
                        </span>
                      ) : row.balance_after === null ? (
                        <span className="text-slate-300" title="No running balance was stored for this row">
                          n/a
                        </span>
                      ) : (
                        money(row.balance_after)
                      )}
                    </td>
                    <td className="td max-w-[16rem] whitespace-normal text-xs text-slate-500">
                      {row.reference !== null && (
                        <span className="mr-1 font-medium text-slate-600">{row.reference}</span>
                      )}
                      {row.notes}
                      <span className="mt-1 flex flex-wrap gap-1">
                        {row.voided && (
                          <span className="badge bg-slate-200 text-slate-600">voided</span>
                        )}
                        {row.corrected && (
                          <span className="badge bg-amber-100 text-amber-800">corrected</span>
                        )}
                      </span>
                    </td>
                    <td className="td text-right">
                      {row.voided ? (
                        <span className="text-xs text-slate-400">—</span>
                      ) : (
                        <span className="inline-flex gap-1">
                          <button
                            type="button"
                            className="rounded border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                            onClick={() => {
                              setVoidingId(null)
                              setEditingId(editingId === row.id ? null : row.id)
                            }}
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            className="rounded border border-red-200 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-50"
                            onClick={() => {
                              setEditingId(null)
                              setVoidingId(voidingId === row.id ? null : row.id)
                            }}
                          >
                            Void
                          </button>
                        </span>
                      )}
                    </td>
                  </tr>

                  {row.corrected && row.original_values !== null && (
                    <tr className="bg-amber-50/60">
                      <td colSpan={7} className="px-3 py-1.5 text-xs text-amber-900">
                        Amended. It was originally{' '}
                        <span className="num font-medium">
                          NPR {money(Math.abs(row.original_values.amount))}
                        </span>{' '}
                        on {shortDate(row.original_values.transacted_on)} against{' '}
                        {accountLabel(row.original_values.account)}
                        {row.original_values.reference !== null &&
                          ` · ref ${row.original_values.reference}`}
                        {row.original_values.notes !== null && ` · ${row.original_values.notes}`}.
                      </td>
                    </tr>
                  )}

                  {row.voided && (
                    <tr className="bg-slate-50">
                      <td colSpan={7} className="px-3 py-1.5 text-xs text-slate-500">
                        Voided — does not count toward any balance.{' '}
                        {row.void_reason ?? 'No reason was recorded.'}
                      </td>
                    </tr>
                  )}

                  {editingId === row.id && (
                    <tr className="bg-slate-50">
                      <td colSpan={7} className="px-3 py-3">
                        <AmendForm
                          portfolioId={portfolioId}
                          row={row}
                          onCancel={() => setEditingId(null)}
                          onDone={(message) => closeForms(message)}
                        />
                      </td>
                    </tr>
                  )}

                  {voidingId === row.id && (
                    <tr className="bg-red-50/60">
                      <td colSpan={7} className="px-3 py-3">
                        <VoidForm
                          portfolioId={portfolioId}
                          row={row}
                          onCancel={() => setVoidingId(null)}
                          onDone={(message) => closeForms(message)}
                        />
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

/**
 * Amend one row. Only the fields actually changed are sent, so a PATCH that
 * touches the notes cannot silently re-date the entry.
 *
 * The amount is entered as a magnitude: the ledger keeps whichever sign the
 * entry type implies (a withdrawal stays negative), so a user cannot turn a
 * withdrawal into a deposit by typing a minus.
 */
function AmendForm({
  portfolioId,
  row,
  onDone,
  onCancel,
}: {
  portfolioId: number
  row: CashTransactionRow
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const queryClient = useQueryClient()
  const [amount, setAmount] = useState(Math.abs(row.amount).toFixed(2))
  const [date, setDate] = useState(row.transacted_on)
  const [account, setAccount] = useState<CashAccount>(row.account)
  const [reference, setReference] = useState(row.reference ?? '')
  const [notes, setNotes] = useState(row.notes ?? '')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: CashAmendPayload = {}

      if (Number(amount) !== Math.abs(row.amount)) payload.amount = Number(amount)
      if (date !== row.transacted_on) payload.transacted_on = date
      if (account !== row.account) payload.account = account
      if (reference !== (row.reference ?? '')) payload.reference = reference === '' ? null : reference
      if (notes !== (row.notes ?? '')) payload.notes = notes === '' ? null : notes

      if (Object.keys(payload).length === 0) {
        throw new Error('Nothing has changed yet — edit a field first.')
      }

      return (
        await api.patch<{ message: string }>(
          `/portfolios/${portfolioId}/cash/${row.id}`,
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
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
          <label className="label" htmlFor={`amend-amount-${row.id}`}>
            Amount (NPR)
          </label>
          <input
            id={`amend-amount-${row.id}`}
            type="number"
            step="0.01"
            min="0.01"
            className="input"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            required
          />
        </div>
        <div>
          <label className="label" htmlFor={`amend-date-${row.id}`}>
            Date
          </label>
          <input
            id={`amend-date-${row.id}`}
            type="date"
            className="input"
            value={date}
            max={new Date().toISOString().slice(0, 10)}
            onChange={(event) => setDate(event.target.value)}
            required
          />
        </div>
        <div>
          <label className="label" htmlFor={`amend-account-${row.id}`}>
            Account
          </label>
          <select
            id={`amend-account-${row.id}`}
            className="input"
            value={account}
            onChange={(event) => setAccount(event.target.value as CashAccount)}
          >
            <option value="bank">Bank</option>
            <option value="cash">Cash in hand</option>
          </select>
        </div>
        <div>
          <label className="label" htmlFor={`amend-ref-${row.id}`}>
            Reference
          </label>
          <input
            id={`amend-ref-${row.id}`}
            className="input"
            value={reference}
            onChange={(event) => setReference(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor={`amend-notes-${row.id}`}>
            Notes
          </label>
          <input
            id={`amend-notes-${row.id}`}
            className="input"
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
          />
        </div>
      </div>

      <p className="text-xs text-slate-500">
        Enter the amount as a positive figure — this row is a{' '}
        <strong>{TYPE_LABELS[row.type] ?? titleCase(row.type)}</strong>, and it keeps that
        direction. A correction that would leave an account overdrawn is refused, with the shortfall
        named: that is a deliberate guard, not a glitch.
      </p>

      {error !== null && <ErrorBox message={error} />}

      <div className="flex gap-2">
        <button type="submit" className="btn-primary" disabled={mutation.isPending}>
          {mutation.isPending ? 'Amending…' : 'Save correction'}
        </button>
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}

/** Void with a reason. Soft, so the row stays as its own explanation. */
function VoidForm({
  portfolioId,
  row,
  onDone,
  onCancel,
}: {
  portfolioId: number
  row: CashTransactionRow
  onDone: (message: string) => void
  onCancel: () => void
}) {
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () =>
      (
        await api.delete<{ message: string }>(`/portfolios/${portfolioId}/cash/${row.id}`, {
          data: { reason: reason === '' ? null : reason },
        })
      ).data,
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
      <div>
        <label className="label" htmlFor={`void-reason-${row.id}`}>
          Why is this being voided?
        </label>
        <input
          id={`void-reason-${row.id}`}
          className="input"
          placeholder="e.g. entered twice by mistake"
          maxLength={255}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />
        <p className="mt-1 text-xs text-slate-500">
          The row stays visible, struck through, with this reason attached. Voiding cannot be used
          to hide a movement — it explains one.
        </p>
      </div>

      {error !== null && <ErrorBox message={error} />}

      <div className="flex gap-2">
        <button type="submit" className="btn-danger" disabled={mutation.isPending}>
          {mutation.isPending ? 'Voiding…' : 'Void this entry'}
        </button>
        <button type="button" className="btn-secondary" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}
