/**
 * Display helpers.
 *
 * Every number the user sees goes through here, so "no data" renders as a dash
 * instead of "NPR 0.00" — a zero would read as a real price and silently
 * misinform, which matters when the number is someone's holding.
 */

const DASH = '—'

export function money(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return DASH
  }

  return value.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  })
}

/** Compact rupee amounts for dashboard tiles: 1.2Cr, 45.3L, 12.5K. */
export function compactMoney(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return DASH
  }

  const abs = Math.abs(value)
  const sign = value < 0 ? '-' : ''

  // Nepali/South Asian convention: crore (10^7) and lakh (10^5) read far more
  // naturally to this audience than millions.
  if (abs >= 1e7) return `${sign}${(abs / 1e7).toFixed(2)}Cr`
  if (abs >= 1e5) return `${sign}${(abs / 1e5).toFixed(2)}L`
  if (abs >= 1e3) return `${sign}${(abs / 1e3).toFixed(1)}K`

  return `${sign}${abs.toFixed(0)}`
}

export function percent(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return DASH
  }

  return `${value.toFixed(decimals)}%`
}

export function signedPercent(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return DASH
  }

  return `${value >= 0 ? '+' : ''}${value.toFixed(decimals)}%`
}

export function quantity(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return DASH
  }

  // Share counts are whole numbers in practice; only show decimals if present.
  return Number.isInteger(value)
    ? value.toLocaleString('en-US')
    : value.toLocaleString('en-US', { maximumFractionDigits: 2 })
}

/** Tailwind text colour for a gain/loss figure. */
export function changeColor(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value) || value === 0) {
    return 'text-slate-500'
  }

  return value > 0 ? 'text-gain' : 'text-loss'
}

export function changeBg(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value) || value === 0) {
    return 'bg-slate-100 text-slate-600'
  }

  return value > 0 ? 'bg-gain-light text-gain-dark' : 'bg-loss-light text-loss-dark'
}

export function signalColor(signal: string | null | undefined): string {
  switch (signal) {
    case 'buy':
      return 'bg-gain-light text-gain-dark'
    case 'sell':
      return 'bg-loss-light text-loss-dark'
    case 'hold':
      return 'bg-amber-100 text-amber-800'
    default:
      return 'bg-slate-100 text-slate-600'
  }
}

export function riskColor(risk: string | null | undefined): string {
  switch (risk) {
    case 'low':
      return 'bg-emerald-100 text-emerald-800'
    case 'medium':
      return 'bg-amber-100 text-amber-800'
    case 'high':
      return 'bg-red-100 text-red-800'
    default:
      return 'bg-slate-100 text-slate-600'
  }
}

/** 0-100 score to a colour band. */
export function scoreColor(score: number | null | undefined): string {
  if (score === null || score === undefined) return 'text-slate-400'
  if (score >= 75) return 'text-gain'
  if (score >= 50) return 'text-amber-600'
  return 'text-loss'
}

export function shortDate(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return DASH

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return DASH

  return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' })
}

export function titleCase(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return DASH

  return value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase())
}

export const DASH_PLACEHOLDER = DASH
