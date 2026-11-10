export type SignalType = 'buy' | 'sell' | 'hold'
export type RiskLevel = 'low' | 'medium' | 'high'
export type TimeHorizon = 'short_term' | 'medium_term' | 'long_term'
export type InstrumentType =
  | 'equity'
  | 'mutual_fund'
  | 'debenture'
  | 'promoter'
  | 'preference'
  | 'unknown'

export interface Paginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: {
    current_page: number
    from: number | null
    last_page: number
    per_page: number
    to: number | null
    total: number
    /** Signals list only: how many days before a signal counts as stale. */
    signal_ttl_days?: number
    /**
     * Signals list only, and only when the fresh list came back empty: how many
     * signals exist once the staleness filter is dropped. Distinguishes "never
     * analysed" from "analysed a while ago", which need different actions.
     */
    stale_available?: number | null
    newest_analysis_date?: string | null
  }
}

export interface Stock {
  symbol: string
  name: string
  sector?: string | null
  sector_slug?: string | null
  instrument_type: InstrumentType
  last_price: number | null
  previous_close: number | null
  change_amount: number | null
  change_percent: number | null
  last_traded_on: string | null
  market_capitalization: number | null
  listed_shares: number | null
  volume?: number
  turnover?: number
  signal?: {
    signal: SignalType
    confidence: number
    risk_level: RiskLevel
    analysis_date: string
    is_stale: boolean
  } | null
  indicators?: {
    rsi_14: number | null
    rsi_zone: string | null
    trend: string | null
    trend_strength: number | null
  } | null
  is_active: boolean
}

export interface StockSignal {
  id: number
  symbol?: string
  name?: string
  sector?: string | null
  analysis_date: string
  signal: SignalType
  signal_label: string
  confidence: number
  risk_level: RiskLevel
  time_horizon: TimeHorizon
  time_horizon_label: string
  reference_price: number
  entry_price: number | null
  entry_price_max: number | null
  exit_price: number | null
  stop_loss: number | null
  target_1: number | null
  target_2: number | null
  risk_reward_ratio: number | null
  expected_return_percent: number | null
  summary: string | null
  rationale: {
    bull_case?: string
    bear_case?: string
    key_drivers?: string[]
    invalidation?: string
    data_gaps?: string[]
  } | null
  indicators_snapshot: Record<string, unknown> | null
  is_stale: boolean
  model: string
  generated_at: string | null
}

export interface PricePoint {
  date: string
  open?: number
  high?: number
  low?: number
  close: number
  volume?: number
  turnover?: number
  rsi_14?: number
  macd?: number
  macd_signal?: number
  macd_histogram?: number
  sma_20?: number
  sma_50?: number
  sma_200?: number
  bollinger_upper?: number
  bollinger_lower?: number
}

export interface StockDetail {
  stock: Stock
  indicators: {
    trade_date: string
    rsi_14: number | null
    rsi_zone: string | null
    macd: number | null
    macd_signal: number | null
    macd_histogram: number | null
    sma_20: number | null
    sma_50: number | null
    sma_200: number | null
    ema_12: number | null
    ema_26: number | null
    bollinger_upper: number | null
    bollinger_middle: number | null
    bollinger_lower: number | null
    atr_14: number | null
    trend: string | null
    trend_strength: number | null
  } | null
  fundamentals: {
    fiscal_period: string
    eps: number | null
    pe_ratio: number | null
    book_value: number | null
    pb_ratio: number | null
    dividend_percent: number | null
    dividend_yield: number | null
    roe: number | null
    npl_percent: number | null
    capital_adequacy_ratio: number | null
  } | null
  fundamental_analysis: {
    analysis_date: string
    valuation: string | null
    quality_score: number | null
    financial_health_score: number | null
    dividend_sustainability_score: number | null
    fair_value_low: number | null
    fair_value_high: number | null
    summary: string | null
    strengths: string[] | null
    concerns: string[] | null
  } | null
}

export interface MarketMover {
  symbol: string
  name: string
  close: number
  change_percent: number | null
  volume: number
  turnover: number
}

export interface MarketSummary {
  trade_date: string | null
  message?: string
  advances: number
  declines: number
  unchanged: number
  total_turnover: number
  total_volume: number
  traded_scrips: number
  top_gainers: MarketMover[]
  top_losers: MarketMover[]
  most_active: MarketMover[]
}

export interface Sector {
  id: number
  name: string
  slug: string
  stocks_count: number
  average_change_percent: number | null
  total_turnover: number | null
  advances: number | null
  declines: number | null
}

export interface SectorAllocation {
  sector: string
  value: number
  invested: number
  percent: number
  symbols: string[]
}

export interface PortfolioSummary {
  total_invested: number
  total_value: number
  unrealized_gain: number
  unrealized_gain_percent: number
  realized_gain: number
  cash_in_hand: number
  bank_balance: number
  investable_cash: number
  net_worth: number
  /**
   * The three disjoint parts net worth is built from, so nothing is counted
   * twice. Optional only so an older API build renders without crashing.
   */
  net_worth_components?: NetWorthComponents
  /** Fixed deposits, split by whether they may legitimately be added to net worth. */
  placements?: PlacementValuation
  holdings_count: number
  unpriced_symbols: string[]
  sector_allocation: SectorAllocation[]
  concentration: { symbol: string; value: number; percent: number }[]
}

export interface NetWorthComponents {
  equity_market_value: number
  investable_cash: number
  settled_placements: number
}

export interface Portfolio {
  id: number
  name: string
  description: string | null
  currency: string
  is_default: boolean
  cash_in_hand: number
  bank_balance: number
  investable_cash: number
  summary?: PortfolioSummary
  holdings_count?: number
  created_at: string | null
  updated_at: string | null
}

export interface Holding {
  id: number
  symbol: string
  name: string
  sector: string | null
  instrument_type: InstrumentType
  quantity: number
  average_cost: number
  invested: number
  current_price: number | null
  current_value: number | null
  unrealized_gain: number | null
  unrealized_gain_percent: number | null
  realized_gain: number
  weight_percent: number | null
  signal: SignalType | null
  signal_confidence: number | null
  first_purchased_on: string | null
  // Mutual-fund only
  is_fund: boolean
  nav: number | null
  nav_date: string | null
  nav_value: number | null
  nav_gain: number | null
  nav_gain_percent: number | null
  premium_discount_percent: number | null
  discount_read: string | null
}

export interface PortfolioAnalysis {
  analysis_date: string
  health_score: number
  diversification_grade: string | null
  risk_level: string | null
  total_invested: number
  total_value: number
  unrealized_gain: number
  unrealized_gain_percent: number
  summary: string | null
  strengths: string[] | null
  weaknesses: string[] | null
  concentration_risks:
    | { label: string; weight_percent?: number; severity: string; explanation: string }[]
    | null
  rebalancing_actions:
    | {
        action: string
        symbol: string
        quantity?: number
        estimated_amount?: number
        priority: string
        reason: string
      }[]
    | null
  sector_allocation: SectorAllocation[]
  model: string
}

export interface BuyRecommendation {
  rank: number
  symbol: string
  name: string
  sector: string | null
  action: string
  suggested_quantity: number
  suggested_price: number
  estimated_cost: number
  allocation_percent: number | null
  stop_loss: number | null
  target_price: number | null
  conviction: number | null
  risk_level: string | null
  time_horizon: string | null
  reason: string | null
}

export interface RecommendationRun {
  investor_profile: InvestorProfileValue
  analysis_date: string
  investable_cash: number
  allocated_amount: number
  reserve_amount: number
  strategy_summary: string | null
  avoid: { symbol: string; reason: string }[] | null
  model: string
  recommendations: BuyRecommendation[]
}

export type ImportRowStatus =
  | 'ready'
  | 'missing_cost'
  | 'sold'
  | 'quantity_mismatch'
  | 'unknown_stock'

export interface ImportRow {
  symbol: string
  name: string
  sector: string | null
  instrument_type: InstrumentType | null
  status: ImportRowStatus
  include: boolean
  edited: boolean
  quantity: number
  wacc_quantity: number | null
  average_cost: number | null
  total_cost: number | null
  last_price: number | null
  market_value: number | null
  purchased_on: string | null
  source_average_cost: number | null
  source_quantity: number
  gain: number | null
  gain_percent: number | null
}

export interface PortfolioImport {
  id: number
  status: 'awaiting_holdings' | 'awaiting_wacc' | 'ready' | 'committed' | 'cancelled'
  holdings_filename: string | null
  wacc_filename: string | null
  demat: string | null
  holdings_count: number
  wacc_count: number
  committed_count: number
  summary: {
    total_rows: number
    ready: number
    quantity_mismatch: number
    missing_cost: number
    unknown_stock: number
    sold: number
    included: number
    total_cost: number | null
    total_value: number | null
    funds_held: number
  }
  warnings: string[]
  rows: ImportRow[]
  created_at: string | null
  committed_at: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  role: string
  is_admin: boolean
  default_portfolio_id: number | null

  /*
   * Comparison settings. The bare field is the user's own choice and is null
   * when unset; the `_effective` twin is what is actually in force, falling
   * back to config. Both are sent so the UI can show the real number without
   * presenting an unset preference as a deliberate 0%.
   */
  benchmark_fd_rate: number | null
  benchmark_fd_rate_effective: number
  benchmark_fd_rate_default: number
  benchmark_index_slug: string | null
  benchmark_index_slug_effective: string
  investor_class: string | null
  investor_class_effective: string
}

export interface ProfileUpdate {
  name?: string
  email?: string
  current_password?: string
  password?: string
  password_confirmation?: string
  benchmark_fd_rate?: number | null
  benchmark_index_slug?: string | null
  investor_class?: string | null
}

/**
 * A plain-language verdict on whether the data is usable, so a silently
 * frozen pipeline is visible rather than mistaken for a quiet market.
 */
export interface HealthVerdict {
  level: 'ok' | 'stale' | 'error' | 'empty'
  headline: string
  detail: string
  action: string | null
  days_behind?: number
}

export interface HealthStatus {
  status?: HealthVerdict
  latest_price_date: string | null
  last_scrape: {
    status: string
    source: string | null
    trade_date: string | null
    rows_found: number
    rows_rejected: number
    error: string | null
    finished_at: string | null
  } | null
  ai: {
    configured: boolean
    model: string
    deep_model: string
    calls_used_today: number
    daily_call_limit: number
  } | null
  sources: string[]
}

/*
 * Head-to-head comparison.
 *
 * The metric vocabulary (labels, units, which direction is better) is served by
 * the API rather than hardcoded here, so the table cannot drift out of step
 * with the backend that decides the winners.
 */

export type MetricUnit = 'rupees' | 'percent' | 'times' | 'shares'

export type MetricBetter = 'higher' | 'lower' | 'neutral'

export interface ComparisonCell {
  raw: number | null
  display: string
}

export interface ComparisonMetric {
  key: string
  label: string
  unit: MetricUnit
  better: MetricBetter
  rationale: string
  /** False for context-only rows such as share price, which are never scored. */
  scored: boolean
  /** True only when every scrip reports the metric, so a winner is meaningful. */
  contested: boolean
  winner: string | null
  tie: boolean
  values: Record<string, ComparisonCell>
}

export interface ComparisonScrip {
  symbol: string
  name: string
  sector: string | null
  fiscal_period: string | null
  data_coverage_percent: number
}

export interface Comparison {
  symbols: string[]
  scrips: ComparisonScrip[]
  metrics: ComparisonMetric[]
  scoreboard: Record<string, number>
  contested_metrics: number
  verdict: {
    winner: string | null
    margin: number
    note: string
  }
}

export interface ComparisonVerdict {
  winner: string | null
  confidence: number
  agrees_with_tally: boolean
  counted_winner: string | null
  disagreement_reason: string | null
  summary: string
  decisive_metrics: Array<{
    metric: string
    favours: string | null
    why_it_matters: string
  }>
  per_scrip: Array<{
    symbol: string
    case_for: string
    case_against: string
    suits_investor?: string
  }>
  data_gaps: string[]
}

export interface ComparisonAnalysis {
  comparison: Comparison
  verdict: ComparisonVerdict
  model: string
}

export interface PeerStanding {
  symbol: string
  sector: string | null
  fiscal_period: string | null
  data_coverage_percent: number
  metrics: Array<{
    key: string
    label: string
    value: number | null
    display: string
    percentile: number
    sample: number
    better: MetricBetter
  }>
}

// ---------------------------------------------------------------------------
// Investor profiles and the rule-based playbook
//
// The same scrip reads differently to a long-term investor, a swing trader and
// a momentum profit-taker, so the API returns one verdict per profile rather
// than one blended answer.
// ---------------------------------------------------------------------------

export type InvestorProfileValue = 'long_term' | 'swing' | 'momentum'

export type VerdictGrade = 'strong_buy' | 'buy' | 'watch' | 'neutral' | 'avoid'

export interface InvestorProfileMeta {
  value: InvestorProfileValue
  label: string
  short_label: string
  description: string
  hold_label: string
  time_horizon: string
  target_bands: [number, number]
  max_stop_loss_percent: number
  max_position_weight_percent: number
  weights: Record<string, number>
}

export interface VerdictReason {
  bucket: string
  text: string
  effect: 'positive' | 'negative' | 'neutral'
}

export interface PlaybookVerdict {
  profile: InvestorProfileValue
  profile_label: string
  grade: VerdictGrade | null
  grade_label?: string
  score?: number
  analysis_date?: string
  reference_price?: number
  entry_low?: number | null
  entry_high?: number | null
  stop_loss?: number | null
  target_1?: number | null
  target_2?: number | null
  risk_reward?: number | null
  price_in_zone?: boolean
  expected_return_percent?: number | null
  hold_label?: string
  scores?: Record<string, number>
  reasons?: VerdictReason[]
  summary: string
  /** 'live' recomputed at today's close, 'stored' from the last run, 'unavailable' when history is too short. */
  source?: 'live' | 'stored' | 'unavailable'
  stored_on?: string | null
  stock?: {
    symbol: string
    name: string
    sector: string | null
    last_price: number | null
    change_percent: number | null
    instrument_type: string
  } | null
}

export type PositionActionValue =
  | 'accumulate'
  | 'add_on_dip'
  | 'hold'
  | 'trim'
  | 'exit'
  | 'stop_loss'

export interface PositionAdvice {
  symbol: string
  name: string
  profile: InvestorProfileValue
  action: PositionActionValue
  action_label: string
  urgency: number
  is_sell: boolean
  quantity: number
  average_cost: number
  price: number | null
  unrealized_gain: number | null
  unrealized_gain_percent: number | null
  days_held: number | null
  weight_percent: number | null
  suggested_quantity: number | null
  suggested_amount: number | null
  entry_low: number | null
  entry_high: number | null
  stop_loss: number | null
  target_1: number | null
  target_2: number | null
  risk_at_stop: number | null
  reasons: string[]
  headline: string
}

export interface ActionsResponse {
  data: PositionAdvice[]
  meta: {
    profile: InvestorProfileValue
    profile_label: string
    hold_label: string
    max_position_weight_percent: number
    counts: Partial<Record<PositionActionValue, number>>
    needs_attention: number
  }
}

// ---------------------------------------------------------------------------
// Year-by-year performance
// ---------------------------------------------------------------------------

export type PerformanceBasis = 'fiscal' | 'calendar'

export interface PerformancePeriod {
  key: string
  /** Market value at the close before the period began; null when price history cannot reach it. */
  opening_value: number | null
  closing_value: number | null
  values_complete: boolean
  unpriced_symbols: string[]
  /** Rupees the period earned: closing − opening − money put in. Realised and unrealised together. */
  profit: number | null
  /** Not annualised — the honest headline for a period still running. */
  simple_return_percent: number | null
  /** Annualised money-weighted (XIRR) return for the period. Extrapolated, so misleading while partial. */
  return_percent: number | null
  net_contribution: number
  days_in_period: number
  is_partial: boolean
  fixed_deposit_rate: number
  fixed_deposit_value: number | null
  value_vs_fixed_deposit: number | null
  beat_fixed_deposit: boolean | null
  label: string
  starts_on: string
  ends_on: string
  is_current: boolean
  invested: number
  proceeds: number
  dividends: number
  fees: number
  bonus_units: number
  realized_gain: number
  net_realized: number
  /** Only the live period can carry a mark-to-market figure. */
  unrealized_gain: number | null
  total_gain: number
  buy_count: number
  sell_count: number
  trades_closed: number
  wins: number
  losses: number
  win_rate: number | null
  average_days_held: number | null
  best_trade: { symbol: string; gain: number; gain_percent: number | null } | null
  worst_trade: { symbol: string; gain: number; gain_percent: number | null } | null
  approximate_boundary: boolean
}

export interface ClosedTradeRow {
  symbol: string
  opened_on: string
  closed_on: string
  quantity: number
  cost_basis: number
  proceeds: number
  fees: number
  gain: number
  gain_percent: number | null
  days_held: number
  is_win: boolean
}

export interface PerformanceTotals extends CapitalBasisTotals {
  realized_gain: number
  dividends: number
  unrealized_gain: number
  net_profit: number
  total_fees: number
  invested_now: number
  market_value: number
  trades_closed: number
  wins: number
  losses: number
  win_rate: number | null
  average_win: number | null
  average_loss: number | null
  profit_factor: number | null
  average_days_held: number | null
  first_transaction_on: string | null
  years_active: number | null
  /** Money-weighted (XIRR), so deposits at different times are comparable. */
  annualized_return_percent: number | null
  /** The benchmark: what the same cash flows would be worth in a fixed deposit. */
  fixed_deposit_rate: number
  fixed_deposit_value: number | null
  value_vs_fixed_deposit: number | null
  beat_fixed_deposit: boolean | null
  /*
   * The deposit comparison is deliberately equity-only. Money already sitting
   * in a real fixed deposit earns the deposit rate by definition, so putting
   * it on both sides of its own benchmark guarantees a tie on that slice and
   * dilutes the equity verdict. Real deposit income is reported separately
   * below, as income rather than as part of the comparison.
   */
  fixed_deposit_comparison_basis?: string
  fixed_deposit_comparison_note?: string
  placement_count?: number
  /** Null rather than 0 when no placements exist at all. */
  placement_principal_placed?: number | null
  placement_interest_earned?: number | null
  placement_value_in_net_worth?: number | null
  placement_note?: string | null
}

export interface StockPerformanceRow {
  symbol: string
  quantity: number
  average_cost: number | null
  market_value: number | null
  unrealized_gain: number | null
  realized_gain: number
  dividends: number
  fees: number
  trades_closed: number
  wins: number
  losses: number
  total_gain: number
}

export interface PerformanceReport {
  basis: PerformanceBasis
  fixed_deposit_rate: number
  /**
   * `equity_only`: every "vs FD" figure in the payload compares the equity
   * book against a hypothetical deposit. Real deposits are on neither side.
   */
  fixed_deposit_basis?: string
  periods: PerformancePeriod[]
  totals: PerformanceTotals
  closed_trades: ClosedTradeRow[]
  by_stock: StockPerformanceRow[]
  fiscal_year_note: string | null
}

/*
 * Market benchmark — GET /portfolios/{id}/benchmark
 *
 * Coverage is not a cosmetic detail. With the index missing the early part of
 * a window the verdict can invert outright, so `index_simulation_complete` and
 * `index_coverage_note` must be surfaced rather than assumed.
 */
export interface BenchmarkMarket {
  index_slug: string | null
  index_name: string | null
  as_of: string
  since: string | null
  index_available: boolean
  index_coverage_percent: number | null
  index_coverage_note: string | null
  portfolio_value: number | null
  portfolio_value_known: boolean
  portfolio_unpriced_symbols: string[]
  index_value: number | null
  index_simulation_complete: boolean
  index_uncovered_dates: string[]
  value_vs_index: number | null
  value_vs_index_percent: number | null
  beat_index: boolean | null
}

export interface BenchmarkRisk {
  sample_size: number
  interval: 'daily' | 'weekly' | 'monthly'
  periods_per_year: number
  beta: number | null
  correlation: number | null
  r_squared: number | null
  alpha_percent_per_period: number | null
  alpha_percent_annualized: number | null
  index_slug: string | null
  index_name: string | null
  index_coverage_note: string | null
}

export interface BenchmarkReport {
  market?: BenchmarkMarket
  risk?: BenchmarkRisk
}


// ---------------------------------------------------------------------------
// Cash ledger corrections
//
// A mis-entered movement is amended in place or voided, never offset with a
// second entry. Both leave a trail the UI is expected to show: `corrected`
// with the `original_values` it used to hold, and `voided` with its reason —
// the point of voiding rather than deleting is that the balance stays
// explainable afterwards.
// ---------------------------------------------------------------------------

export type CashAccount = 'cash' | 'bank'

export interface CashTransactionOriginalValues {
  amount: number
  account: CashAccount
  reference: string | null
  notes: string | null
  transacted_on: string
}

export interface CashTransactionRow {
  id: number
  /** `deposit`, `withdrawal`, `trade_debit`, `placement_debit`, … */
  type: string
  account: CashAccount
  /** Signed: negative for withdrawals and debits. Amendments send a magnitude. */
  amount: number
  /** Null when the running balance could not be established for that row. */
  balance_after: number | null
  reference: string | null
  notes: string | null
  transacted_on: string
  voided: boolean
  void_reason: string | null
  corrected: boolean
  original_values: CashTransactionOriginalValues | null
}

export interface CashLedgerReport {
  cash_in_hand: number
  bank_balance: number
  investable_cash: number
  transactions: CashTransactionRow[]
}

/** Only the fields actually being changed are sent — the API amends in place. */
export interface CashAmendPayload {
  amount?: number
  account?: CashAccount
  reference?: string | null
  notes?: string | null
  transacted_on?: string
}

// ---------------------------------------------------------------------------
// Cash placements (fixed deposits)
// ---------------------------------------------------------------------------

export type CashPlacementKind = 'fixed_deposit' | 'pledged_shares'

export type CashPlacementStatus = 'active' | 'matured' | 'withdrawn' | 'broken_early'

export type CompoundingBasis =
  | 'simple'
  | 'monthly'
  | 'quarterly'
  | 'semi_annually'
  | 'annually'

export type PayoutHandling = 'paid_out' | 'reinvested'

/** Actual/365, the same basis as the "vs FD" benchmark in /performance. */
export interface PlacementAccrual {
  principal: number
  accrued_interest: number
  /** Principal plus accrued interest when reinvested; equals principal when paid out. */
  principal_at_end: number
  effective_end_date: string
  days_accrued: number
  is_capped_at_maturity: boolean
}

export interface CashPlacement {
  id: number
  portfolio_id: number
  kind: CashPlacementKind
  institution: string | null
  reference: string | null
  principal: number
  /** Annual percentage. */
  interest_rate: number
  start_date: string
  maturity_date: string | null
  closed_on: string | null
  compounding_basis: CompoundingBasis
  payout: PayoutHandling
  status: CashPlacementStatus
  notes: string | null
  /** Present on list and show, absent on write responses. */
  accrual?: PlacementAccrual
}

export interface CashPlacementMeta {
  as_of: string
  count: number
  total_principal: number
  total_accrued_interest: number
  matured_count: number
  maturing_soon_count: number
  maturing_soon_within_days: number
  /** Null when no active interest-bearing capital is placed — never 0. */
  weighted_average_rate: number | null
}

export interface CashPlacementListResponse {
  data: CashPlacement[]
  meta: CashPlacementMeta
}

/** Body accepted by POST/PATCH /cash-placements. Undefined keys are omitted. */
export interface CashPlacementPayload {
  institution?: string | null
  reference?: string | null
  principal?: number
  interest_rate?: number
  start_date?: string
  maturity_date?: string | null
  compounding_basis?: CompoundingBasis
  payout?: PayoutHandling
  status?: CashPlacementStatus
  notes?: string | null
  /** Create only: debit the principal from a cash account on the start date. */
  settle_cash?: boolean
  cash_account?: CashAccount
}

export interface CashPlacementWithdrawPayload {
  closed_on?: string
  settle_cash?: boolean
  cash_account?: CashAccount
  include_interest?: boolean
  notes?: string | null
}

/**
 * Why a placement is or is not inside net worth.
 *
 * `active`/`matured` still hold capital; `closed` was withdrawn; `not_started`
 * predates the valuation date; `undated_exit` is marked withdrawn with no
 * closure date and is excluded rather than guessed at; `not_cash` is a pledged
 * -share record, already marked to market with the holdings.
 */
export type PlacementState =
  | 'not_started'
  | 'active'
  | 'matured'
  | 'closed'
  | 'undated_exit'
  | 'not_cash'

export interface PlacementValuationRow {
  id: number
  label: string
  kind: CashPlacementKind
  status: CashPlacementStatus
  principal: number
  interest_rate: number
  start_date: string
  maturity_date: string | null
  closed_on: string | null
  /** True when the principal demonstrably left a cash account. */
  cash_settled: boolean
  state: PlacementState
  /** Null when the placement cannot be valued honestly — never 0. */
  value: number | null
  accrued_interest: number | null
  days_accrued: number
  is_capped_at_maturity: boolean
  counts_toward_net_worth: boolean
  reason: string | null
}

export interface PlacementValuation {
  as_of: string
  count: number
  /** Settled, still-open placements only: the part safe to add to net worth. */
  net_worth_contribution: number
  settled_count: number
  /** Open but never debited from cash — that money is already idle cash. */
  unsettled_value: number
  unsettled_count: number
  accrued_interest_open: number
  accrued_interest_closed: number
  total_interest: number
  principal_placed: number
  placements: PlacementValuationRow[]
  /** Named, never silently dropped. */
  excluded: { label: string; reason: string }[]
  note: string | null
}

/*
 * Acquisition channel — GET /portfolios/{id}/channels
 *
 * `channel` is what is stored; `suggested_channel` is what detection would
 * propose. Both are sent so the UI can offer a suggestion without having
 * applied it, and `is_confirmed` marks a choice a person made by hand, which
 * detection must never overwrite.
 */
export interface ChannelOption {
  value: string
  label: string
  short_label: string
  description: string
  funded_outside_broker: boolean
  is_market_priced: boolean
}

export interface ChannelTransaction {
  id: number
  symbol: string
  type: string
  quantity: number
  price: number
  transacted_on: string
  notes: string | null

  channel: string
  channel_label: string
  channel_short_label: string
  channel_basis: string | null
  is_confirmed: boolean
  confirmed_at: string | null

  suggested_channel: string
  suggested_basis: string | null
  suggested_confidence: string
  differs_from_suggestion: boolean

  funded_outside_broker: boolean
  /** Quantity x price. Null when no price was ever recorded. */
  capital: number | null
  capital_recorded: boolean
  capital_unknowable: boolean
  /** A market purchase with no settlement in the ledger — bought via another broker. */
  cash_unaccounted: boolean
}

export interface ChannelOverview {
  transactions: ChannelTransaction[]
  broker_traded_symbols: string[]
  channels: ChannelOption[]
  capital_summary: { rows: number; capital: number }
  unconfirmed_count: number
  note: string | null
}

export interface ChannelBucket {
  channel: string
  label: string
  short_label: string
  is_market_priced: boolean
  funded_outside_broker: boolean
  parcels: number
  quantity: number
  cost_basis: number
  market_value: number
  gain: number
  return_percent: number | null
  share_of_cost_percent: number | null
  excluded_symbols: string[]
}

export interface ChannelProfit {
  as_of: string
  channels: ChannelBucket[]
  total: {
    parcels: number
    cost_basis: number | null
    market_value: number | null
    gain: number | null
    return_percent: number | null
  }
  market_priced: {
    cost_basis: number | null
    market_value: number | null
    gain: number | null
    return_percent: number | null
    note: string
  }
  excluded: { symbol: string; channel: string; reason: string }[]
  coverage: {
    parcels_measured: number
    parcels_excluded: number
    complete: boolean
    note: string | null
  }
  reconciles: boolean
  reconciliation_error: number
}

/*
 * Capital gains — GET /portfolios/{id}/capital-gains
 *
 * `taxable_gain` can exceed `gross_gain`: tax is withheld per disposal at
 * settlement, so a losing trade does not reduce the tax charged on a winning
 * one. Both are sent because reporting either alone misleads.
 */
export interface CapitalGainsBand {
  band: string
  rate_percent: number
  disposals: number
  gross_gain: number
  taxable_gain: number
  estimated_tax: number
}

export interface CapitalGainsAggregate {
  disposals: number
  assessable_disposals: number
  excluded_disposals: number
  excluded_symbols: string[]
  excluded_gross_gain: number | null
  coverage_percent: number | null
  coverage_complete: boolean
  coverage_note: string | null
  gross_gain: number | null
  taxable_gain: number | null
  realised_loss: number | null
  estimated_tax: number | null
  net_gain: number | null
  effective_tax_rate_percent: number | null
  bands: CapitalGainsBand[]
  loss_offset_note?: string
}

export interface CapitalGainsPeriod extends CapitalGainsAggregate {
  key: string
  label: string
  starts_on: string
  ends_on: string
  approximate_boundary: boolean
}

export interface CapitalGainsDisposal {
  symbol: string
  opened_on: string | null
  closed_on: string
  days_held: number | null
  quantity: number
  cost_basis: number | null
  proceeds: number
  gross_gain: number | null
  rate_band: string | null
  rate_percent: number | null
  rate_reason: string | null
  estimated_tax: number | null
  net_gain: number | null
  is_assessable: boolean
  exclusion_reason: string | null
}

export interface CapitalGainsReport {
  /** Scrips whose zero cost is explained by a confirmed bonus issue, not a gap. */
  bonus_zero_cost_symbols?: string[]

  basis: string
  investor_class: string
  rates: {
    investor_class: string
    label: string
    long_term_threshold_days: number
    short_term_rate_percent: number
    long_term_rate_percent: number
    has_holding_period_split: boolean
    source: string
  }
  periods: CapitalGainsPeriod[]
  totals: CapitalGainsAggregate
  disposals: CapitalGainsDisposal[]
  disposals_truncated: boolean
  excluded_disposals: CapitalGainsDisposal[]
  fiscal_year_note: string | null
  estimate_note: string | null
}

/*
 * Return attribution — GET /portfolios/{id}/attribution
 *
 * `contribution_percent` is weight x return and the rows are constrained to
 * sum to `total.return_percent`; `reconciles` reports whether they actually
 * did, so a row that looks wrong is wrong rather than a rounding artefact.
 *
 * `weight_percent` and `return_percent` are null where the capital base is
 * zero (a position opened and closed inside the window), while the
 * contribution is still real — the profit happened, it just has no base to be
 * a percentage of.
 */
export interface AttributionHolding {
  symbol: string
  sector: string | null
  sector_slug: string | null
  opening_value: number
  closing_value: number
  net_contribution: number
  capital_base: number
  profit: number
  weight_percent: number | null
  return_percent: number | null
  contribution_percent: number
}

export interface AttributionSector {
  sector: string
  sector_slug: string | null
  holdings: string[]
  excluded_symbols: string[]
  opening_value: number
  closing_value: number
  net_contribution: number
  capital_base: number
  profit: number
  portfolio_return_percent: number | null
  index_slug: string | null
  index_name: string | null
  index_return_percent: number | null
  index_coverage_percent: number | null
  selection_gap_percent: number | null
  beat_sub_index: boolean | null
  index_note: string | null
}

export interface AttributionReport {
  window: { from: string; to: string; days: number; first_transaction_on: string | null }
  basis: { return: string; note: string }
  total: {
    capital_base: number | null
    profit: number | null
    return_percent: number | null
    holdings: number
  }
  reconciles: boolean
  reconciliation_error_percent: number
  holdings: AttributionHolding[]
  excluded: { symbol: string; reason: string }[]
  coverage: {
    positions_measured: number
    positions_excluded: number
    excluded_symbols: string[]
    excluded_capital_at_cost: number | null
    capital_base_covered_percent: number | null
    complete: boolean
    note: string | null
  }
  sectors: AttributionSector[]
  allocation_effect: {
    available: boolean
    allocation_effect_percent: number | null
    selection_effect_percent: number | null
    outstanding_shares_coverage_percent: number | null
    equities_total: number | null
    equities_with_outstanding_shares: number | null
    dated_outstanding_shares_rows: number | null
    reason: string
  }
}

/*
 * Corporate calendar — GET /corporate-actions
 *
 * `days_until_book_close` can be negative: a book close that has already
 * passed is exactly what a holder needs to see, so it is reported rather than
 * filtered out.
 */
export interface CorporateAction {
  id: number
  symbol: string
  name: string | null
  type: string
  title: string | null
  book_close_on: string | null
  days_until_book_close: number | null
  event_on: string | null
  closes_on: string | null
  listing_on: string | null
  cash_dividend_percent: number | null
  bonus_percent: number | null
  total_dividend_percent: number | null
  ratio: string | null
  units: number | null
  issue_price: number | null
  issue_manager: string | null
  fiscal_period: string | null
  venue: string | null
  agenda: string | null
  published_on: string | null
  affects_me: boolean
}

export interface CorporateCalendar {
  book_closes: CorporateAction[]
  open_issues: CorporateAction[]
  meetings: CorporateAction[]
  from: string
  days: number
  my_symbols: string[]
  total_stored: number
  latest_published_on: string | null
}


/**
 * One thing a user still has to do before the app can answer a question.
 *
 * `done` is derived server-side from the database on every read, never from a
 * stored flag — data arrives through the CLI importers and backup restores as
 * well as through this UI, and a flag would be wrong in the one direction that
 * matters: telling someone to redo work already finished.
 */
export interface OnboardingStep {
  key: string
  title: string
  done: boolean
  /** What this step gets you — why it is worth doing at all. */
  why: string
  /** How to do it, as a sentence or a command. */
  how: string
  /** Where in the app to do it, when it can be done in the app at all. */
  link: string | null
  /** What is already recorded, once the step is under way. */
  detail: string | null
}

export interface OnboardingState {
  steps: OnboardingStep[]
  completed: number
  total: number
  complete: boolean
  /** The first outstanding step's key, so the UI can ask one question. */
  next: string | null
}

/** One staged cash movement from a broker statement, before it reaches the ledger. */
export interface CashImportRow {
  fingerprint: string
  line: number
  direction: 'in' | 'out' | null
  amount: number | null
  occurred_on: string | null
  /**
   * What the broker called it. `deposit` / `withdrawal` are money crossing the
   * account boundary; the settlements are cash moving against shares already
   * owned, and are not new money. Null for the text formats, which cannot tell.
   */
  kind: 'deposit' | 'withdrawal' | 'buy_settlement' | 'sell_settlement' | null
  reference: string | null
  narration: string | null
  balance_after: number | null
  status: 'ready' | 'excluded_failed' | 'duplicate' | 'needs_review' | 'overlap_warning'
  include: boolean
  reason: string | null
  edited: boolean
}

export interface CashImport {
  id: number
  status: string
  format: string
  account: string
  filename: string | null
  total_rows: number
  ready: number
  excluded_failed: number
  duplicate: number
  needs_review: number
  overlap_warning: number
  included: number
  /** Gross movement across the account, settlements included. */
  money_in: number
  money_out: number
  net_injected: number
  /**
   * Money you actually put in and took out — settlements excluded. Null when
   * the format cannot label its rows, never 0.
   */
  capital_in: number | null
  capital_out: number | null
  net_capital: number | null
  deposits: number
  withdrawals: number
  first_movement_on: string | null
  last_movement_on: string | null
  reconciliation: {
    statement_closing_balance: number | null
    replayed_closing_balance: number | null
  } | null
  warnings: string[]
  rows?: CashImportRow[]
}

/**
 * The second "vs a deposit" answer: money you actually put in, against
 * everything the portfolio is now worth.
 *
 * Distinct from the equity-only fields on the same object, and they can
 * legitimately disagree: the deposit can win on one basis and lose on the
 * other because the bases answer different questions.
 */
export interface CapitalBasisTotals {
  capital_basis_available: boolean
  capital_contributed: number | null
  capital_withdrawn: number | null
  net_capital: number | null
  capital_fixed_deposit_value: number | null
  net_worth_vs_capital_fixed_deposit: number | null
  beat_fixed_deposit_on_capital: boolean | null
  capital_basis_note: string
  /**
   * False when cost exists that no cash movement explains — typically a second
   * broker whose statement has not been imported. Contributed capital is the
   * denominator, so an incomplete one flatters the return.
   */
  capital_basis_complete: boolean
  capital_unexplained_cost: number | null
  capital_unexplained_note: string | null
}
