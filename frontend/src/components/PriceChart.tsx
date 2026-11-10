import { useMemo } from 'react'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  ReferenceArea,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { compactMoney, money, shortDate } from '../lib/format'
import type { PricePoint } from '../lib/types'

/**
 * Shared axis/grid styling. Defined once so the three stacked charts line up
 * visually and read as one figure rather than three unrelated ones.
 */
const AXIS = {
  tick: { fontSize: 11, fill: '#94a3b8' },
  stroke: '#e2e8f0',
}

function ChartTooltip({
  active,
  payload,
  label,
}: {
  active?: boolean
  payload?: { name?: string; value?: number | string; color?: string }[]
  label?: string | number
}) {
  if (active !== true || payload === undefined || payload.length === 0) {
    return null
  }

  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg">
      <p className="mb-1 font-semibold text-slate-700">{shortDate(String(label))}</p>
      {payload.map((entry) => (
        <p key={entry.name} className="num flex justify-between gap-4">
          <span style={{ color: entry.color }}>{entry.name}</span>
          <span className="font-medium text-slate-800">
            {typeof entry.value === 'number' ? money(entry.value) : String(entry.value ?? '—')}
          </span>
        </p>
      ))}
    </div>
  )
}

/**
 * The trade plan drawn on the chart: a band you are willing to buy in, the
 * line where the thesis is wrong, and the two where you take money off.
 *
 * Passing it in rather than fetching it here keeps the chart a dumb renderer,
 * and lets the same component draw a different profile's plan without knowing
 * that profiles exist.
 */
/**
 * How far beyond the plotted price range a zone level may push the axis, as a
 * multiple of that range. Anything further is clipped rather than allowed to
 * flatten the price line — see zoneLevelsOutOfView() for reporting what was.
 */
const ZONE_HEADROOM = 0.5

export interface ChartZone {
  entryLow: number | null
  entryHigh: number | null
  stopLoss: number | null
  target1: number | null
  target2: number | null
  label?: string
}

export function PriceChart({
  series,
  showMovingAverages,
  showBollinger,
  zone,
}: {
  series: PricePoint[]
  showMovingAverages: boolean
  showBollinger: boolean
  zone?: ChartZone | null
}) {
  // A price axis starting at zero wastes most of the height on empty space, so
  // pad the real range by 3% instead. The zone levels join the calculation
  // because a stop below every close would otherwise be clipped out of sight.
  const domain = useMemo<[number, number]>(() => {
    const values = series.flatMap((point) =>
      [point.close, point.high, point.low, showBollinger ? point.bollinger_upper : undefined,
        showBollinger ? point.bollinger_lower : undefined].filter(
        (value): value is number => typeof value === 'number',
      ),
    )

    const zoneValues = [zone?.entryLow, zone?.entryHigh, zone?.stopLoss, zone?.target1, zone?.target2]
      .filter((value): value is number => typeof value === 'number')

    if (values.length === 0) return [0, 1]

    // Targets can sit far above the visible history (a 60% long-term target on a
    // one-month view), which would squash the price line into a flat ribbon. So
    // the zone may only extend the range by ZONE_HEADROOM, not without limit.
    const priceMin = Math.min(...values)
    const priceMax = Math.max(...values)
    const priceSpan = priceMax - priceMin || priceMax * 0.06 || 1
    const allowedMin = priceMin - priceSpan * ZONE_HEADROOM
    const allowedMax = priceMax + priceSpan * ZONE_HEADROOM

    const min = Math.min(priceMin, ...zoneValues.map((v) => Math.max(v, allowedMin)))
    const max = Math.max(priceMax, ...zoneValues.map((v) => Math.min(v, allowedMax)))
    const pad = (max - min) * 0.03 || max * 0.03 || 1

    return [Math.max(0, min - pad), max + pad]
  }, [series, showBollinger, zone])

  return (
    <ResponsiveContainer width="100%" height={320}>
      <AreaChart data={series} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <defs>
          <linearGradient id="closeFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor="#0f172a" stopOpacity={0.18} />
            <stop offset="100%" stopColor="#0f172a" stopOpacity={0} />
          </linearGradient>
        </defs>

        <CartesianGrid strokeDasharray="3 3" stroke={AXIS.stroke} vertical={false} />
        <XAxis
          dataKey="date"
          tick={AXIS.tick}
          stroke={AXIS.stroke}
          tickFormatter={(value: string) => shortDate(value)}
          minTickGap={40}
        />
        <YAxis
          tick={AXIS.tick}
          stroke={AXIS.stroke}
          domain={domain}
          tickFormatter={(value: number) => money(value, 0)}
          width={58}
        />
        <Tooltip content={<ChartTooltip />} />

        {zone !== null && zone !== undefined && (
          <>
            {/* Buy zone: a band, not a single price, because a fill is never
                exactly where you planned it. */}
            {typeof zone.entryLow === 'number' && typeof zone.entryHigh === 'number' && (
              <ReferenceArea
                y1={zone.entryLow}
                y2={zone.entryHigh}
                fill="#10b981"
                fillOpacity={0.1}
                stroke="#10b981"
                strokeOpacity={0.35}
                strokeDasharray="3 3"
                label={{ value: 'Buy zone', position: 'insideLeft', fontSize: 10, fill: '#047857' }}
              />
            )}

            {typeof zone.stopLoss === 'number' && (
              <ReferenceLine
                y={zone.stopLoss}
                stroke="#e11d48"
                strokeWidth={1.5}
                strokeDasharray="6 3"
                label={{ value: `Stop ${money(zone.stopLoss, 0)}`, position: 'insideBottomRight', fontSize: 10, fill: '#be123c' }}
              />
            )}

            {typeof zone.target1 === 'number' && (
              <ReferenceLine
                y={zone.target1}
                stroke="#0284c7"
                strokeWidth={1.5}
                strokeDasharray="6 3"
                label={{ value: `T1 ${money(zone.target1, 0)}`, position: 'insideTopRight', fontSize: 10, fill: '#0369a1' }}
              />
            )}

            {typeof zone.target2 === 'number' && (
              <ReferenceLine
                y={zone.target2}
                stroke="#0284c7"
                strokeWidth={1}
                strokeDasharray="3 4"
                strokeOpacity={0.7}
                label={{ value: `T2 ${money(zone.target2, 0)}`, position: 'insideTopRight', fontSize: 10, fill: '#0369a1' }}
              />
            )}
          </>
        )}

        {showBollinger && (
          <>
            <Line
              type="monotone"
              dataKey="bollinger_upper"
              name="Boll. upper"
              stroke="#cbd5e1"
              strokeWidth={1}
              dot={false}
              strokeDasharray="4 3"
            />
            <Line
              type="monotone"
              dataKey="bollinger_lower"
              name="Boll. lower"
              stroke="#cbd5e1"
              strokeWidth={1}
              dot={false}
              strokeDasharray="4 3"
            />
          </>
        )}

        <Area
          type="monotone"
          dataKey="close"
          name="Close"
          stroke="#0f172a"
          strokeWidth={2}
          fill="url(#closeFill)"
          dot={false}
          activeDot={{ r: 3 }}
        />

        {showMovingAverages && (
          <>
            <Line
              type="monotone"
              dataKey="sma_20"
              name="SMA 20"
              stroke="#2563eb"
              strokeWidth={1.5}
              dot={false}
            />
            <Line
              type="monotone"
              dataKey="sma_50"
              name="SMA 50"
              stroke="#d97706"
              strokeWidth={1.5}
              dot={false}
            />
            <Line
              type="monotone"
              dataKey="sma_200"
              name="SMA 200"
              stroke="#7c3aed"
              strokeWidth={1.5}
              dot={false}
            />
          </>
        )}
      </AreaChart>
    </ResponsiveContainer>
  )
}

export function VolumeChart({ series }: { series: PricePoint[] }) {
  return (
    <ResponsiveContainer width="100%" height={110}>
      <BarChart data={series} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={AXIS.stroke} vertical={false} />
        <XAxis dataKey="date" hide />
        <YAxis
          tick={AXIS.tick}
          stroke={AXIS.stroke}
          tickFormatter={(value: number) => compactMoney(value)}
          width={58}
        />
        <Tooltip content={<ChartTooltip />} />
        <Bar dataKey="volume" name="Volume" fill="#94a3b8" radius={[2, 2, 0, 0]} />
      </BarChart>
    </ResponsiveContainer>
  )
}

export function RsiChart({ series }: { series: PricePoint[] }) {
  return (
    <ResponsiveContainer width="100%" height={130}>
      <AreaChart data={series} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={AXIS.stroke} vertical={false} />
        <XAxis dataKey="date" hide />
        <YAxis tick={AXIS.tick} stroke={AXIS.stroke} domain={[0, 100]} ticks={[0, 30, 50, 70, 100]} width={58} />
        <Tooltip content={<ChartTooltip />} />
        {/* The 70/30 lines are what make an RSI value interpretable at a glance. */}
        <ReferenceLine y={70} stroke="#dc2626" strokeDasharray="4 3" strokeWidth={1} />
        <ReferenceLine y={30} stroke="#16a34a" strokeDasharray="4 3" strokeWidth={1} />
        <Area
          type="monotone"
          dataKey="rsi_14"
          name="RSI(14)"
          stroke="#7c3aed"
          strokeWidth={1.8}
          fill="#7c3aed"
          fillOpacity={0.1}
          dot={false}
        />
      </AreaChart>
    </ResponsiveContainer>
  )
}

export function MacdChart({ series }: { series: PricePoint[] }) {
  return (
    <ResponsiveContainer width="100%" height={130}>
      <BarChart data={series} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={AXIS.stroke} vertical={false} />
        <XAxis dataKey="date" hide />
        <YAxis tick={AXIS.tick} stroke={AXIS.stroke} width={58} tickFormatter={(v: number) => v.toFixed(1)} />
        <Tooltip content={<ChartTooltip />} />
        <ReferenceLine y={0} stroke="#94a3b8" strokeWidth={1} />
        <Bar dataKey="macd_histogram" name="Histogram" fill="#94a3b8" radius={[1, 1, 0, 0]} />
        <Line type="monotone" dataKey="macd" name="MACD" stroke="#2563eb" strokeWidth={1.5} dot={false} />
        <Line
          type="monotone"
          dataKey="macd_signal"
          name="Signal"
          stroke="#dc2626"
          strokeWidth={1.5}
          dot={false}
        />
      </BarChart>
    </ResponsiveContainer>
  )
}

/** NAV vs market price — the chart that actually matters for a closed-end fund. */
export function NavChart({ series }: { series: { date: string; nav: number; market_price: number | null }[] }) {
  return (
    <ResponsiveContainer width="100%" height={280}>
      <AreaChart data={series} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke={AXIS.stroke} vertical={false} />
        <XAxis
          dataKey="date"
          tick={AXIS.tick}
          stroke={AXIS.stroke}
          tickFormatter={(value: string) => shortDate(value)}
          minTickGap={40}
        />
        <YAxis tick={AXIS.tick} stroke={AXIS.stroke} width={58} domain={['auto', 'auto']} />
        <Tooltip content={<ChartTooltip />} />
        <Area
          type="monotone"
          dataKey="nav"
          name="NAV"
          stroke="#7c3aed"
          strokeWidth={2}
          fill="#7c3aed"
          fillOpacity={0.1}
          dot={false}
        />
        <Line
          type="monotone"
          dataKey="market_price"
          name="Market price"
          stroke="#0f172a"
          strokeWidth={2}
          dot={false}
          connectNulls
        />
      </AreaChart>
    </ResponsiveContainer>
  )
}

/**
 * Which zone levels fall outside what the chart can draw at this range.
 *
 * A clipped target line is invisible, which reads as "there is no target" —
 * worse than saying so. The caller uses this to caption the chart instead.
 */
export function zoneLevelsOutOfView(
  series: PricePoint[],
  zone: ChartZone | null | undefined,
): { label: string; value: number }[] {
  if (zone === null || zone === undefined || series.length === 0) return []

  const values = series.flatMap((point) =>
    [point.close, point.high, point.low].filter((value): value is number => typeof value === 'number'),
  )

  if (values.length === 0) return []

  const priceMin = Math.min(...values)
  const priceMax = Math.max(...values)
  const priceSpan = priceMax - priceMin || priceMax * 0.06 || 1
  const ceiling = priceMax + priceSpan * ZONE_HEADROOM
  const floor = priceMin - priceSpan * ZONE_HEADROOM

  return (
    [
      { label: 'Stop', value: zone.stopLoss },
      { label: 'Target 1', value: zone.target1 },
      { label: 'Target 2', value: zone.target2 },
    ] as { label: string; value: number | null }[]
  )
    .filter((level): level is { label: string; value: number } => typeof level.value === 'number')
    .filter((level) => level.value > ceiling || level.value < floor)
}
