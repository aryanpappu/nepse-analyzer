import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Bar,
  BarChart,
  Cell,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { api, errorMessage } from '../lib/api'
import { compactMoney, quantity, signedPercent, changeColor } from '../lib/format'
import { Card, ChangePill, EmptyState, ErrorBox, Spinner } from '../components/ui'
import { Explain } from '../components/Explain'
import type { Sector } from '../lib/types'

export function Sectors() {
  const query = useQuery({
    queryKey: ['sectors'],
    queryFn: async () => (await api.get<{ data: Sector[] }>('/sectors')).data.data,
  })

  if (query.isLoading) return <Spinner label="Loading sectors…" />

  if (query.isError) {
    return <ErrorBox message={errorMessage(query.error)} onRetry={() => query.refetch()} />
  }

  const sectors = (query.data ?? []).filter((sector) => sector.stocks_count > 0)

  if (sectors.length === 0) {
    return <EmptyState title="No sector data yet" hint="Sector information has not been loaded yet. Check back after the next market update." />
  }

  // Only sectors that actually traded can be charted.
  const chartable = sectors
    .filter((sector) => sector.average_change_percent !== null)
    .sort((a, b) => (b.average_change_percent ?? 0) - (a.average_change_percent ?? 0))

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Sector performance</h1>
        <p className="text-sm text-slate-500">
          Average daily change and turnover across {sectors.length} sectors.
        </p>
      </div>

      {chartable.length > 0 && (
        <Card title="Average change by sector" subtitle="Latest trading session">
          <ResponsiveContainer width="100%" height={Math.max(280, chartable.length * 28)}>
            <BarChart
              data={chartable}
              layout="vertical"
              margin={{ top: 4, right: 24, left: 8, bottom: 4 }}
            >
              <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" horizontal={false} />
              <XAxis
                type="number"
                tick={{ fontSize: 11, fill: '#94a3b8' }}
                stroke="#e2e8f0"
                tickFormatter={(value: number) => `${value.toFixed(1)}%`}
              />
              <YAxis
                type="category"
                dataKey="name"
                tick={{ fontSize: 11, fill: '#475569' }}
                stroke="#e2e8f0"
                width={150}
              />
              <Tooltip
                formatter={(value: number) => [signedPercent(value), 'Avg change']}
                contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e2e8f0' }}
              />
              <Bar dataKey="average_change_percent" radius={[0, 3, 3, 0]}>
                {chartable.map((sector) => (
                  <Cell
                    key={sector.slug}
                    fill={(sector.average_change_percent ?? 0) >= 0 ? '#16a34a' : '#dc2626'}
                  />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </Card>
      )}

      <div className="card overflow-x-auto">
        <table className="w-full min-w-[44rem]">
          <thead className="border-b border-slate-200 bg-slate-50">
            <tr>
              <th className="th">Sector</th>
              <th className="th text-right">Scrips</th>
              <th className="th text-right">Avg change</th>
              <th className="th text-right"><Explain term="turnover">Turnover</Explain></th>
              <th className="th text-right">Breadth</th>
              <th className="th" />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {sectors.map((sector) => (
              <tr key={sector.slug} className="hover:bg-slate-50">
                <td className="td font-medium text-slate-800">{sector.name}</td>
                <td className="td num text-right text-slate-600">
                  {quantity(sector.stocks_count)}
                </td>
                <td className="td text-right">
                  <ChangePill value={sector.average_change_percent} />
                </td>
                <td className="td num text-right text-slate-600">
                  {sector.total_turnover === null ? '—' : compactMoney(sector.total_turnover)}
                </td>
                <td className="td num text-right text-xs">
                  {sector.advances === null ? (
                    '—'
                  ) : (
                    <>
                      <span className="text-gain">{sector.advances}↑</span>{' '}
                      <span className="text-loss">{sector.declines}↓</span>
                    </>
                  )}
                </td>
                <td className="td text-right">
                  <Link
                    to={`/stocks?sector=${sector.slug}`}
                    className={`text-xs font-medium underline ${changeColor(sector.average_change_percent)}`}
                  >
                    View scrips
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
