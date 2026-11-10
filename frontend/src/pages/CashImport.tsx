import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { money, shortDate } from '../lib/format'
import { invalidatePortfolioMoney } from '../lib/portfolioQueries'
import { Banner, Card, EmptyState, ErrorBox, Npr, Spinner } from '../components/ui'
import { CreateFirstPortfolio } from '../components/CreateFirstPortfolio'
import { Explain } from '../components/Explain'
import type { CashImport, CashImportRow, Portfolio } from '../lib/types'

/**
 * Upload a broker statement.
 *
 * What this screen is *for* is worth being explicit about, because the obvious
 * reading is wrong. A broker statement is not a record of what you own. It is
 * a record of money crossing the boundary of your broker account: what you put
 * in, and what you took back out. Holdings come from the MeroShare exports and
 * are not touched here.
 *
 * In a fictional example, gross credits of 150,000.00 and debits of
 * 145,000.00 leave a 5,000.00 balance. Contributed capital is different:
 * 80,000.00 in contributions minus 10,000.00 in withdrawals leaves
 * 70,000.00 net. Every money-weighted figure in the app — annualised
 * return, the deposit benchmark, the index benchmark — is measured against the
 * second one. Showing only the first would understate the money at work by
 * a factor of fourteen in this fictional example.
 *
 * Staged, never immediate: nothing reaches the ledger until commit, duplicates
 * against movements already recorded are excluded with their reason shown, and
 * the replayed balance is checked against the statement's own printed closing
 * figure first.
 */
export function CashImportPage() {
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)

  const [importId, setImportId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [account, setAccount] = useState<'bank' | 'cash'>('bank')

  const portfolios = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
  })

  const portfolioId = portfolios.data?.[0]?.id ?? null

  const current = useQuery({
    queryKey: ['cash-import', portfolioId, importId],
    queryFn: async () =>
      (await api.get<{ data: CashImport }>(`/portfolios/${portfolioId}/cash-imports/${importId}`))
        .data.data,
    enabled: portfolioId !== null && importId !== null,
  })

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData()
      body.append('file', file)
      body.append('account', account)

      return (
        await api.post<{ data: CashImport }>(`/portfolios/${portfolioId}/cash-imports`, body)
      ).data.data
    },
    onSuccess: (data) => {
      setError(null)
      setImportId(data.id)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const setRow = useMutation({
    mutationFn: async ({ key, include }: { key: string; include: boolean }) =>
      api.patch(`/portfolios/${portfolioId}/cash-imports/${importId}/rows`, { key, include }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['cash-import', portfolioId, importId] })
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const commit = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ message: string; data: CashImport }>(
          `/portfolios/${portfolioId}/cash-imports/${importId}/commit`,
          {},
        )
      ).data,
    onSuccess: (data) => {
      setError(null)
      setNotice(data.message)
      setImportId(null)
      if (portfolioId !== null) invalidatePortfolioMoney(queryClient, portfolioId)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const discard = useMutation({
    mutationFn: async () =>
      api.delete(`/portfolios/${portfolioId}/cash-imports/${importId}`),
    onSuccess: () => {
      setImportId(null)
      setError(null)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  if (portfolios.isLoading) return <Spinner label="Loading portfolios…" />

  if (portfolioId === null) {
    return (
      <EmptyState
        title="No portfolio to import into"
        hint="A statement needs somewhere to land. Create one here and the upload opens straight away."
        action={
          <div className="mt-4 w-full">
            <CreateFirstPortfolio />
          </div>
        }
      />
    )
  }

  const staged = current.data ?? null

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Broker statement</h1>
        <p className="mt-1 max-w-3xl text-sm text-slate-500">
          This records <strong>money in and money out</strong> of your broker account — nothing
          else. It does not set what you hold or what it is worth; those come from your MeroShare
          exports on the Import screen. What it does give you is the one thing those cannot: how
          much you actually put in, and when.
        </p>
      </div>

      {notice !== null && <Banner tone="info">{notice}</Banner>}
      {error !== null && <ErrorBox message={error} />}

      {staged === null ? (
        <Card
          title="Upload the statement"
          subtitle="Your broker's .xlsx account statement, or a CSV/text export of it."
        >
          <div className="flex flex-wrap items-end gap-4">
            <label className="text-sm">
              <span className="block text-xs font-medium text-slate-600">Goes into</span>
              <select
                className="input mt-1 w-40"
                value={account}
                onChange={(event) => setAccount(event.target.value as 'bank' | 'cash')}
              >
                <option value="bank">Bank / broker</option>
                <option value="cash">Cash in hand</option>
              </select>
            </label>

            <div>
              <input
                ref={fileInput}
                type="file"
                accept=".xlsx,.csv,.txt"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file !== undefined) upload.mutate(file)
                  event.target.value = ''
                }}
              />
              <button
                type="button"
                className="btn-primary"
                disabled={upload.isPending}
                onClick={() => fileInput.current?.click()}
              >
                {upload.isPending ? 'Reading…' : 'Choose statement file'}
              </button>
            </div>
          </div>

          <p className="mt-4 text-xs text-slate-500">
            Nothing is written until you review the rows and press commit. Movements already in
            your ledger are detected and excluded, so re-uploading an overlapping statement will
            not double-count.
          </p>
        </Card>
      ) : (
        <StagedReview
          staged={staged}
          isLoading={current.isLoading}
          committing={commit.isPending}
          onToggleRow={(key, include) => setRow.mutate({ key, include })}
          onCommit={() => commit.mutate()}
          onDiscard={() => discard.mutate()}
        />
      )}
    </div>
  )
}

function StagedReview({
  staged,
  isLoading,
  committing,
  onToggleRow,
  onCommit,
  onDiscard,
}: {
  staged: CashImport
  isLoading: boolean
  committing: boolean
  onToggleRow: (key: string, include: boolean) => void
  onCommit: () => void
  onDiscard: () => void
}) {
  const [showSettlements, setShowSettlements] = useState(false)

  const rows = staged.rows ?? []
  const capitalRows = rows.filter((r) => r.kind === 'deposit' || r.kind === 'withdrawal')
  // Settlements are hidden by default. They belong in the ledger — the balance
  // does not reconcile without them — but they are not what this screen is
  // about, and 61 of them bury the 24 rows that are.
  const visible = showSettlements || capitalRows.length === 0 ? rows : capitalRows

  const reconciles =
    staged.reconciliation?.statement_closing_balance == null ||
    staged.reconciliation.replayed_closing_balance == null
      ? null
      : Math.abs(
          staged.reconciliation.statement_closing_balance -
            staged.reconciliation.replayed_closing_balance,
        ) < 0.01

  return (
    <div className="space-y-6">
      <Card
        title="Review before committing"
        // The date range covers what will actually be written, so it is empty
        // when every row is held back — in which case saying "· to" reads as a
        // rendering fault rather than as "nothing selected".
        subtitle={
          staged.first_movement_on === null || staged.last_movement_on === null
            ? `${staged.filename ?? 'Statement'} · ${staged.total_rows} rows · nothing selected to write`
            : `${staged.filename ?? 'Statement'} · ${staged.total_rows} rows · ${shortDate(
                staged.first_movement_on,
              )} to ${shortDate(staged.last_movement_on)}`
        }
        action={
          <div className="flex gap-2">
            <button type="button" className="btn-secondary" onClick={onDiscard}>
              Discard
            </button>
            <button
              type="button"
              className="btn-primary"
              disabled={committing || staged.included === 0}
              onClick={onCommit}
            >
              {committing ? 'Committing…' : `Commit ${staged.included} rows`}
            </button>
          </div>
        }
      >
        {reconciles === false && (
          <Banner tone="warning">
            The rows replay to {money(staged.reconciliation?.replayed_closing_balance ?? 0)} but the
            statement prints {money(staged.reconciliation?.statement_closing_balance ?? 0)}. Some
            row was misread — check before committing.
          </Banner>
        )}

        {reconciles === true && (
          <p className="mb-4 rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            <strong>Checks out.</strong> Replaying these rows reproduces the statement&rsquo;s own
            printed closing balance of {money(staged.reconciliation?.statement_closing_balance ?? 0)}
            , so nothing was misread.
          </p>
        )}

        {staged.warnings.map((warning) => (
          <p key={warning} className="mb-2 text-xs text-amber-800">
            {warning}
          </p>
        ))}

        {/* The headline. Contributed capital is the number that matters; the
            gross balance is shown beside it only so the two are never confused
            for each other. */}
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-lg border border-sky-200 bg-sky-50 p-4">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-sky-700">
              Money you actually put in
            </p>
            {staged.net_capital === null ? (
              <p className="mt-1 text-sm text-slate-500">
                This format does not label its rows, so real deposits cannot be told apart from
                settlements. Reported as n/a rather than guessed.
              </p>
            ) : (
              <>
                <p className="num mt-1 text-2xl font-semibold text-slate-900">
                  <Npr value={staged.net_capital} />
                </p>
                <p className="mt-1 text-xs text-slate-600">
                  {money(staged.capital_in ?? 0)} deposited, {money(staged.capital_out ?? 0)}{' '}
                  withdrawn. This is what your{' '}
                  <Explain term="xirr">annualised return</Explain> and both benchmarks measure
                  against.
                </p>
              </>
            )}
          </div>

          <div className="rounded-lg border border-slate-200 bg-white p-4">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
              Balance left in the account
            </p>
            <p className="num mt-1 text-2xl font-semibold text-slate-700">
              <Npr value={staged.net_injected} />
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {money(staged.money_in)} in and {money(staged.money_out)} out across every row,
              settlements included. Settlements are cash turning into shares and back — not new
              money, which is why they are not in the figure on the left.
            </p>
          </div>
        </div>
      </Card>

      <Card
        title="Rows"
        subtitle={`${staged.included} of ${staged.total_rows} will be written`}
        action={
          capitalRows.length > 0 && (
            <label className="flex items-center gap-2 text-xs text-slate-500">
              <input
                type="checkbox"
                checked={showSettlements}
                onChange={(event) => setShowSettlements(event.target.checked)}
              />
              Show trade settlements
            </label>
          )
        }
      >
        {isLoading ? (
          <Spinner />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-2 py-2">Include</th>
                  <th className="px-2 py-2">Date</th>
                  <th className="px-2 py-2">What</th>
                  <th className="px-2 py-2 text-right">Amount</th>
                  <th className="px-2 py-2">Reference</th>
                  <th className="px-2 py-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {visible.map((row) => (
                  <RowLine key={row.fingerprint} row={row} onToggle={onToggleRow} />
                ))}
              </tbody>
            </table>
          </div>
        )}

        {!showSettlements && capitalRows.length > 0 && capitalRows.length < rows.length && (
          <p className="mt-3 text-xs text-slate-400">
            {rows.length - capitalRows.length} trade settlements hidden. They will still be
            committed — the running balance does not reconcile without them.
          </p>
        )}
      </Card>
    </div>
  )
}

function RowLine({
  row,
  onToggle,
}: {
  row: CashImportRow
  onToggle: (key: string, include: boolean) => void
}) {
  const isCapital = row.kind === 'deposit' || row.kind === 'withdrawal'

  return (
    <tr className={clsx('border-b border-slate-100', !row.include && 'opacity-50')}>
      <td className="px-2 py-2">
        <input
          type="checkbox"
          checked={row.include}
          onChange={(event) => onToggle(row.fingerprint, event.target.checked)}
        />
      </td>
      <td className="px-2 py-2 text-slate-500">
        {row.occurred_on === null ? '—' : shortDate(row.occurred_on)}
      </td>
      <td className="px-2 py-2">
        <span className={clsx(isCapital ? 'font-medium text-slate-900' : 'text-slate-500')}>
          {row.narration ?? '—'}
        </span>
        {row.reason !== null && (
          <span className="block text-xs text-amber-700">{row.reason}</span>
        )}
      </td>
      <td
        className={clsx(
          'num px-2 py-2 text-right font-medium',
          row.direction === 'out' ? 'text-loss' : 'text-gain',
        )}
      >
        {row.direction === 'out' ? '−' : '+'}
        {money(row.amount ?? 0)}
      </td>
      <td className="px-2 py-2 font-mono text-xs text-slate-400">{row.reference ?? '—'}</td>
      <td className="px-2 py-2 text-xs text-slate-500">{row.status.replace(/_/g, ' ')}</td>
    </tr>
  )
}
