import { AiDataNotice } from '../components/AiDataNotice'
import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { Card, EmptyState, ErrorBox, Spinner } from '../components/ui'
import { useAuth } from '../lib/auth'
import type { Comparison, ComparisonAnalysis, ComparisonMetric } from '../lib/types'

/**
 * Head-to-head fundamentals.
 *
 * The table is the deterministic part and loads for free. The AI verdict is a
 * separate, explicit action because it costs an API call — and because the
 * numbers should be readable without one.
 */
export function Compare() {
  const [params, setParams] = useSearchParams()
  const { user } = useAuth()

  const initial = (params.get('symbols') ?? '').split(',').filter(Boolean)
  const [draft, setDraft] = useState(initial.join(', ') || 'NABIL, EBL')

  const symbols = initial.length >= 2 ? initial : []

  const query = useQuery({
    queryKey: ['compare', symbols],
    queryFn: async () =>
      (await api.get<{ data: Comparison }>('/compare', { params: { symbols: symbols.join(',') } }))
        .data.data,
    enabled: symbols.length >= 2,
  })

  const analysis = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: ComparisonAnalysis }>('/compare/analyze', { symbols })).data.data,
  })

  function submit(event: React.FormEvent) {
    event.preventDefault()

    const next = draft
      .split(',')
      .map((s) => s.trim().toUpperCase())
      .filter(Boolean)

    if (next.length >= 2) {
      analysis.reset()
      setParams({ symbols: next.join(',') })
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Compare scrips</h1>
        <p className="text-sm text-slate-500">
          Side-by-side on the figures companies actually file — capital, distributable earnings,
          returns and asset quality.
        </p>
      </div>

      <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
        <input
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          placeholder="NABIL, EBL"
          aria-label="Symbols to compare, comma separated"
          className="input w-72 uppercase"
        />
        <button type="submit" className="btn-primary">
          Compare
        </button>
        <span className="text-xs text-slate-500">Two to four symbols, comma separated.</span>
      </form>

      {symbols.length < 2 && (
        <EmptyState
          title="Pick two scrips"
          hint="Enter two to four symbols above — for example NABIL, EBL — to see them side by side."
        />
      )}

      {query.isLoading && <Spinner label="Loading fundamentals…" />}

      {query.isError && (
        <ErrorBox message={errorMessage(query.error)} onRetry={() => query.refetch()} />
      )}

      {query.data !== undefined && (
        <>
          <Scoreboard comparison={query.data} />

          <MetricTable comparison={query.data} />

          <Card
            title="AI verdict"
            subtitle="Weighs the metrics rather than counting them. Costs one API call."
          >
            <AiDataNotice />
            {user === null ? (
              <p className="text-sm text-slate-500">
                <Link to="/login" className="font-medium underline">
                  Sign in
                </Link>{' '}
                to run the weighted analysis. The table above needs no account.
              </p>
            ) : (
              <>
                <button
                  type="button"
                  className="btn-primary"
                  onClick={() => analysis.mutate()}
                  disabled={analysis.isPending}
                >
                  {analysis.isPending ? 'Analysing…' : 'Analyse this comparison'}
                </button>

                {analysis.isError && (
                  <div className="mt-3">
                    <ErrorBox message={errorMessage(analysis.error)} />
                  </div>
                )}

                {analysis.data !== undefined && <Verdict analysis={analysis.data} />}
              </>
            )}
          </Card>
        </>
      )}
    </div>
  )
}

function Scoreboard({ comparison }: { comparison: Comparison }) {
  return (
    <div className="grid gap-4 md:grid-cols-3">
      {comparison.scrips.map((scrip) => {
        const wins = comparison.scoreboard[scrip.symbol] ?? 0
        const leading = comparison.verdict.winner === scrip.symbol

        return (
          <Card key={scrip.symbol} className={clsx(leading && 'ring-2 ring-emerald-500')}>
            <div className="flex items-baseline justify-between">
              <Link to={`/stocks/${scrip.symbol}`} className="text-lg font-semibold underline">
                {scrip.symbol}
              </Link>
              <span className="num text-sm text-slate-500">
                {wins}/{comparison.contested_metrics}
              </span>
            </div>
            <p className="truncate text-sm text-slate-600">{scrip.name}</p>
            <p className="mt-1 text-xs text-slate-500">
              {scrip.sector ?? 'Unclassified'} · filed {scrip.fiscal_period ?? 'n/a'}
            </p>
            {scrip.data_coverage_percent < 100 && (
              <p className="mt-1 text-xs text-amber-700">
                Only {scrip.data_coverage_percent}% of metrics reported.
              </p>
            )}
          </Card>
        )
      })}

      <Card className="md:col-span-3">
        <p className="text-sm text-slate-700">{comparison.verdict.note}</p>
      </Card>
    </div>
  )
}

function MetricTable({ comparison }: { comparison: Comparison }) {
  const scored = comparison.metrics.filter((metric) => metric.scored)
  const context = comparison.metrics.filter((metric) => !metric.scored)

  return (
    <div className="card overflow-x-auto">
      <table className="w-full min-w-[44rem]">
        <thead className="border-b border-slate-200 bg-slate-50">
          <tr>
            <th className="th">Metric</th>
            {comparison.symbols.map((symbol) => (
              <th key={symbol} className="th text-right">
                {symbol}
              </th>
            ))}
            <th className="th text-right">Better</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {scored.map((metric) => (
            <MetricRow key={metric.key} metric={metric} symbols={comparison.symbols} />
          ))}

          <tr className="bg-slate-50">
            <td className="td text-xs font-medium uppercase tracking-wide text-slate-500" colSpan={comparison.symbols.length + 2}>
              Context only — not scored
            </td>
          </tr>

          {context.map((metric) => (
            <MetricRow key={metric.key} metric={metric} symbols={comparison.symbols} />
          ))}
        </tbody>
      </table>
    </div>
  )
}

function MetricRow({ metric, symbols }: { metric: ComparisonMetric; symbols: string[] }) {
  return (
    <tr className="hover:bg-slate-50">
      <td className="td">
        <span className="font-medium text-slate-800">{metric.label}</span>
        <span className="block text-xs text-slate-500">{metric.rationale}</span>
      </td>

      {symbols.map((symbol) => {
        const cell = metric.values[symbol]
        const won = metric.winner === symbol

        return (
          <td
            key={symbol}
            className={clsx(
              'td num text-right',
              won ? 'bg-emerald-50 font-semibold text-emerald-800' : 'text-slate-700',
            )}
          >
            {cell?.display ?? '—'}
          </td>
        )
      })}

      <td className="td text-right text-xs text-slate-500">
        {!metric.scored
          ? '—'
          : !metric.contested
            ? 'not reported by all'
            : metric.tie
              ? 'tie'
              : `${metric.better} wins`}
      </td>
    </tr>
  )
}

function Verdict({ analysis }: { analysis: ComparisonAnalysis }) {
  const { verdict } = analysis

  return (
    <div className="mt-4 space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <span className="rounded-full bg-slate-900 px-3 py-1 text-sm font-semibold text-white">
          {verdict.winner ?? 'No clear winner'}
        </span>
        <span className="text-xs text-slate-500">
          confidence {verdict.confidence}/100 · {analysis.model}
        </span>
      </div>

      {!verdict.agrees_with_tally && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          <p className="font-medium">
            This differs from the metric count, which favoured {verdict.counted_winner ?? 'neither'}.
          </p>
          {verdict.disagreement_reason !== null && <p className="mt-1">{verdict.disagreement_reason}</p>}
        </div>
      )}

      <p className="text-sm text-slate-700">{verdict.summary}</p>

      {verdict.decisive_metrics.length > 0 && (
        <div>
          <h3 className="text-sm font-semibold text-slate-800">What decided it</h3>
          <ul className="mt-2 space-y-2">
            {verdict.decisive_metrics.map((item, index) => (
              <li key={index} className="text-sm text-slate-600">
                <span className="font-medium text-slate-800">{item.metric}</span>
                {item.favours !== null && (
                  <span className="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800">
                    {item.favours}
                  </span>
                )}
                <span className="block">{item.why_it_matters}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="grid gap-3 md:grid-cols-2">
        {verdict.per_scrip.map((scrip) => (
          <div key={scrip.symbol} className="rounded-lg border border-slate-200 p-3">
            <p className="font-semibold text-slate-800">{scrip.symbol}</p>
            <p className="mt-1 text-sm text-slate-600">
              <span className="font-medium text-emerald-700">For: </span>
              {scrip.case_for}
            </p>
            <p className="mt-1 text-sm text-slate-600">
              <span className="font-medium text-red-700">Against: </span>
              {scrip.case_against}
            </p>
            {scrip.suits_investor !== undefined && (
              <p className="mt-1 text-xs text-slate-500">Suits: {scrip.suits_investor}</p>
            )}
          </div>
        ))}
      </div>

      {verdict.data_gaps.length > 0 && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
            Limits of this comparison
          </h3>
          <ul className="mt-1 list-inside list-disc text-sm text-slate-600">
            {verdict.data_gaps.map((gap, index) => (
              <li key={index}>{gap}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
