import { useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { money, quantity, shortDate, signedPercent, changeColor } from '../lib/format'
import {
  Banner,
  Card,
  EmptyState,
  ErrorBox,
  FundTag,
  Spinner,
} from '../components/ui'
import { CreateFirstPortfolio } from '../components/CreateFirstPortfolio'
import type { ImportRow, ImportRowStatus, Portfolio, PortfolioImport } from '../lib/types'

const STATUS_LABEL: Record<ImportRowStatus, { label: string; tone: string; hint: string }> = {
  ready: {
    label: 'Ready',
    tone: 'bg-gain-light text-gain-dark',
    hint: 'Both files agree — will be imported.',
  },
  quantity_mismatch: {
    label: 'Qty differs',
    tone: 'bg-amber-100 text-amber-800',
    hint: 'Your holdings file and WACC file disagree on quantity. The holdings file wins.',
  },
  missing_cost: {
    label: 'Needs price',
    tone: 'bg-sky-100 text-sky-800',
    hint: 'Held, but the WACC file has no purchase price. Enter one to include it.',
  },
  unknown_stock: {
    label: 'Unknown scrip',
    tone: 'bg-slate-200 text-slate-700',
    hint: 'Not in the stock list.',
  },
  sold: {
    label: 'Sold',
    tone: 'bg-slate-100 text-slate-500',
    hint: 'In the WACC file but no longer held — a closed position, excluded by default.',
  },
}

export function ImportWizard() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const [importId, setImportId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [showSold, setShowSold] = useState(false)
  const [replaceExisting, setReplaceExisting] = useState(true)

  const holdingsInput = useRef<HTMLInputElement>(null)
  const waccInput = useRef<HTMLInputElement>(null)

  const portfolios = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
  })

  const portfolioId = portfolios.data?.[0]?.id ?? null

  const current = useQuery({
    queryKey: ['import', portfolioId, importId],
    queryFn: async () =>
      (await api.get<{ data: PortfolioImport }>(`/portfolios/${portfolioId}/imports/${importId}`))
        .data.data,
    enabled: portfolioId !== null && importId !== null,
  })

  const uploadHoldings = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData()
      body.append('file', file)

      return (
        await api.post<{ data: PortfolioImport }>(`/portfolios/${portfolioId}/imports`, body)
      ).data.data
    },
    onSuccess: (data) => {
      setImportId(data.id)
      setError(null)
      queryClient.setQueryData(['import', portfolioId, data.id], data)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const uploadWacc = useMutation({
    mutationFn: async (file: File) => {
      const body = new FormData()
      body.append('file', file)

      return (
        await api.post<{ data: PortfolioImport }>(
          `/portfolios/${portfolioId}/imports/${importId}/wacc`,
          body,
        )
      ).data.data
    },
    onSuccess: (data) => {
      setError(null)
      queryClient.setQueryData(['import', portfolioId, data.id], data)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  const updateRow = useMutation({
    mutationFn: async (payload: Record<string, unknown>) =>
      (
        await api.patch<{ data: PortfolioImport }>(
          `/portfolios/${portfolioId}/imports/${importId}/rows`,
          payload,
        )
      ).data.data,
    onSuccess: (data) => queryClient.setQueryData(['import', portfolioId, data.id], data),
    onError: (caught) => setError(errorMessage(caught)),
  })

  const commit = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ message: string; data: PortfolioImport }>(
          `/portfolios/${portfolioId}/imports/${importId}/commit`,
          { replace_existing: replaceExisting },
        )
      ).data,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['portfolio'] })
      void queryClient.invalidateQueries({ queryKey: ['portfolios'] })
      // Committing an import is what completes the "bring in what you hold"
      // step, and leaves parcels to classify — both read from this key.
      void queryClient.invalidateQueries({ queryKey: ['onboarding'] })
      navigate('/portfolio')
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  if (portfolios.isLoading) return <Spinner />

  if (portfolioId === null) {
    return (
      <EmptyState
        title="No portfolio to import into"
        hint="An import needs somewhere to land. Create one here and the wizard opens straight away."
        action={
          <div className="mt-4 w-full">
            <CreateFirstPortfolio />
          </div>
        }
      />
    )
  }

  const data = current.data ?? null
  const step = data === null ? 1 : data.status === 'awaiting_wacc' ? 2 : 3
  const rows = data?.rows ?? []
  const visibleRows = showSold ? rows : rows.filter((row) => row.status !== 'sold')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Import from MeroShare</h1>
        <p className="text-sm text-slate-500">
          Upload your two MeroShare exports. The portfolio file says what you hold; the WACC file
          says what you paid.
        </p>
      </div>

      <Stepper step={step} />

      {error !== null && <ErrorBox message={error} />}

      {/* Step 1 */}
      <Card
        title="Step 1 — My Portfolio export"
        subtitle="MeroShare → My Portfolio → download as CSV"
      >
        {data !== null ? (
          <Banner tone="success">
            Loaded <strong>{data.holdings_filename}</strong> — {data.holdings_count} scrips held.
          </Banner>
        ) : (
          <div className="flex flex-wrap items-center gap-3">
            <input
              ref={holdingsInput}
              type="file"
              accept=".csv,text/csv,text/plain"
              className="hidden"
              onChange={(event) => {
                const file = event.target.files?.[0]
                if (file !== undefined) uploadHoldings.mutate(file)
              }}
            />
            <button
              type="button"
              className="btn-primary"
              onClick={() => holdingsInput.current?.click()}
              disabled={uploadHoldings.isPending}
            >
              {uploadHoldings.isPending ? 'Reading…' : 'Choose portfolio CSV'}
            </button>
            <span className="text-xs text-slate-500">
              Looks like <code className="font-mono">my_portfolio_…csv</code>
            </span>
          </div>
        )}
      </Card>

      {/* Step 2 */}
      {data !== null && (
        <Card
          title="Step 2 — WACC export"
          subtitle="MeroShare → My WACC → download as CSV. This carries your purchase prices."
        >
          {data.wacc_filename !== null ? (
            <Banner tone="success">
              Loaded <strong>{data.wacc_filename}</strong> — {data.wacc_count} WACC entries
              {data.demat !== null && ` · demat ${data.demat}`}.
            </Banner>
          ) : (
            <div className="flex flex-wrap items-center gap-3">
              <input
                ref={waccInput}
                type="file"
                accept=".csv,text/csv,text/plain"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  if (file !== undefined) uploadWacc.mutate(file)
                }}
              />
              <button
                type="button"
                className="btn-primary"
                onClick={() => waccInput.current?.click()}
                disabled={uploadWacc.isPending}
              >
                {uploadWacc.isPending ? 'Merging…' : 'Choose WACC CSV'}
              </button>
              <span className="text-xs text-slate-500">
                Looks like <code className="font-mono">wacc_…csv</code>
              </span>
            </div>
          )}
        </Card>
      )}

      {/* Step 3 — review */}
      {data !== null && data.wacc_filename !== null && (
        <>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-5">
            <SummaryTile label="Ready" value={data.summary.ready} tone="text-gain" />
            <SummaryTile label="Needs price" value={data.summary.missing_cost} tone="text-sky-600" />
            <SummaryTile
              label="Qty differs"
              value={data.summary.quantity_mismatch}
              tone="text-amber-600"
            />
            <SummaryTile label="Sold (skipped)" value={data.summary.sold} tone="text-slate-400" />
            <SummaryTile label="Funds" value={data.summary.funds_held} tone="text-violet-600" />
          </div>

          {data.warnings.length > 0 && (
            <Banner tone="warning">
              <p className="mb-1 font-semibold">Worth checking before you import:</p>
              <ul className="list-inside list-disc space-y-0.5 text-xs">
                {data.warnings.slice(0, 8).map((warning) => (
                  <li key={warning}>{warning}</li>
                ))}
              </ul>
            </Banner>
          )}

          <Card
            title="Step 3 — review and adjust"
            subtitle="Quantities come from your portfolio file, prices from the WACC file. Edit anything before importing."
            action={
              <label className="flex items-center gap-2 text-xs text-slate-600">
                <input
                  type="checkbox"
                  checked={showSold}
                  onChange={(event) => setShowSold(event.target.checked)}
                />
                Show {data.summary.sold} sold positions
              </label>
            }
          >
            <div className="overflow-x-auto">
              <table className="w-full min-w-[56rem]">
                <thead className="border-b border-slate-200 bg-slate-50">
                  <tr>
                    <th className="th w-10">In</th>
                    <th className="th">Scrip</th>
                    <th className="th">Status</th>
                    <th className="th text-right">Quantity</th>
                    <th className="th text-right">Buy price (WACC)</th>
                    <th className="th text-right">Total cost</th>
                    <th className="th text-right">LTP</th>
                    <th className="th text-right">P/L</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {visibleRows.map((row) => (
                    <RowEditor
                      key={row.symbol}
                      row={row}
                      busy={updateRow.isPending}
                      onChange={(changes) =>
                        updateRow.mutate({ symbol: row.symbol, ...changes })
                      }
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <Card title="Import">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="space-y-2">
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={replaceExisting}
                    onChange={(event) => setReplaceExisting(event.target.checked)}
                  />
                  Replace the portfolio's existing positions
                </label>
                <p className="max-w-xl text-xs text-slate-500">
                  {replaceExisting
                    ? 'Existing holdings and their transaction history will be cleared and rebuilt from these files. MeroShare is treated as the source of truth.'
                    : 'Positions will be added on top of what is already there — quantities of the same scrip will be combined into a new weighted average.'}
                </p>
                <p className="num text-sm text-slate-700">
                  Importing <strong>{data.summary.included}</strong> positions, total cost{' '}
                  <strong>NPR {money(data.summary.total_cost)}</strong>
                </p>
              </div>

              <button
                type="button"
                className="btn-primary"
                onClick={() => commit.mutate()}
                disabled={commit.isPending || data.summary.included === 0}
              >
                {commit.isPending ? 'Importing…' : `Import ${data.summary.included} positions`}
              </button>
            </div>
          </Card>
        </>
      )}

      <Link to="/portfolio" className="inline-block text-sm text-slate-500 underline">
        ← Back to portfolio
      </Link>
    </div>
  )
}

function RowEditor({
  row,
  busy,
  onChange,
}: {
  row: ImportRow
  busy: boolean
  onChange: (changes: Record<string, unknown>) => void
}) {
  const [cost, setCost] = useState(row.average_cost === null ? '' : String(row.average_cost))
  const [qty, setQty] = useState(String(row.quantity))

  const status = STATUS_LABEL[row.status]
  const isSold = row.status === 'sold'

  return (
    <tr className={clsx('hover:bg-slate-50', isSold && 'opacity-60')}>
      <td className="td">
        <input
          type="checkbox"
          checked={row.include}
          disabled={busy || row.status === 'unknown_stock'}
          onChange={(event) => onChange({ include: event.target.checked })}
          title={row.include ? 'Included in the import' : 'Excluded'}
        />
      </td>

      <td className="td">
        <div className="flex items-center gap-1.5">
          <span>
            <span className="font-semibold text-slate-800">{row.symbol}</span>
            <span className="block max-w-[15rem] truncate text-xs text-slate-500">
              {row.name === row.symbol ? (row.sector ?? '—') : row.name}
            </span>
          </span>
          {row.instrument_type === 'mutual_fund' && <FundTag />}
        </div>
      </td>

      <td className="td">
        <span className={clsx('badge', status.tone)} title={status.hint}>
          {status.label}
        </span>
        {row.edited && (
          <span className="ml-1 text-[10px] font-medium uppercase text-sky-600">edited</span>
        )}
      </td>

      <td className="td text-right">
        {isSold ? (
          <span className="num text-slate-400">—</span>
        ) : (
          <input
            type="number"
            step="0.01"
            min="0"
            className="input num w-24 py-1 text-right"
            value={qty}
            disabled={busy}
            onChange={(event) => setQty(event.target.value)}
            onBlur={() => {
              if (Number(qty) !== row.quantity) onChange({ quantity: Number(qty) })
            }}
          />
        )}
        {row.wacc_quantity !== null && row.wacc_quantity !== row.quantity && (
          <span className="num block text-[10px] text-amber-600">
            WACC: {quantity(row.wacc_quantity)}
          </span>
        )}
      </td>

      <td className="td text-right">
        <input
          type="number"
          step="0.0001"
          min="0"
          placeholder="Enter price"
          className={clsx(
            'input num w-28 py-1 text-right',
            row.status === 'missing_cost' && 'border-sky-400 bg-sky-50',
          )}
          value={cost}
          disabled={busy}
          onChange={(event) => setCost(event.target.value)}
          onBlur={() => {
            const parsed = cost === '' ? null : Number(cost)
            if (parsed !== row.average_cost) onChange({ average_cost: parsed })
          }}
        />
        {row.source_average_cost !== null && row.source_average_cost !== row.average_cost && (
          <span className="num block text-[10px] text-slate-400">
            was {money(row.source_average_cost, 4)}
          </span>
        )}
      </td>

      <td className="td num text-right font-medium">{money(row.total_cost)}</td>
      <td className="td num text-right">{money(row.last_price)}</td>

      <td className="td text-right">
        <span className={clsx('num block text-sm', changeColor(row.gain))}>
          {row.gain === null ? '—' : `${row.gain >= 0 ? '+' : ''}${money(row.gain)}`}
        </span>
        <span className={clsx('num text-xs', changeColor(row.gain_percent))}>
          {signedPercent(row.gain_percent)}
        </span>
        {row.purchased_on !== null && (
          <span className="block text-[10px] text-slate-400">{shortDate(row.purchased_on)}</span>
        )}
      </td>
    </tr>
  )
}

function Stepper({ step }: { step: number }) {
  const steps = ['Upload holdings', 'Upload WACC', 'Review and import']

  return (
    <ol className="flex flex-wrap items-center gap-2 text-sm">
      {steps.map((label, index) => {
        const number = index + 1
        const state = step > number ? 'done' : step === number ? 'active' : 'todo'

        return (
          <li key={label} className="flex items-center gap-2">
            <span
              className={clsx(
                'grid h-7 w-7 place-items-center rounded-full text-xs font-semibold',
                state === 'done'
                  ? 'bg-gain text-white'
                  : state === 'active'
                    ? 'bg-slate-900 text-white'
                    : 'bg-slate-200 text-slate-500',
              )}
            >
              {state === 'done' ? '✓' : number}
            </span>
            <span
              className={clsx(
                state === 'todo' ? 'text-slate-400' : 'font-medium text-slate-800',
              )}
            >
              {label}
            </span>
            {number < steps.length && <span className="mx-1 text-slate-300">→</span>}
          </li>
        )
      })}
    </ol>
  )
}

function SummaryTile({ label, value, tone }: { label: string; value: number; tone: string }) {
  return (
    <div className="card p-3 text-center">
      <p className={clsx('num text-2xl font-semibold', tone)}>{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </div>
  )
}
