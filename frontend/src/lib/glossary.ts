/**
 * Plain-English definitions for the jargon this app cannot avoid.
 *
 * Two audiences have to be served at once. Someone who trades NEPSE daily
 * knows what a book close is and would be insulted by a tooltip explaining it
 * in the page body; someone opening the app for the first time has no idea and
 * will quietly misread the screen. A definition that appears only when asked
 * for serves both.
 *
 * Rules for writing these:
 *
 *  - Say what it *means to the reader*, not what it is in the abstract. "The
 *    date that decides whether the dividend is yours" beats "the record date
 *    for corporate action entitlement".
 *  - Nepali market terms get their local framing (kitta, par at Rs 100, the
 *    Bikram Sambat fiscal year), because that is how the reader will meet them.
 *  - Where a term is routinely misunderstood, say the wrong reading out loud.
 *    Beta is the clearest case: most readers assume it means risk of loss.
 *  - No cross-references to other jargon. A definition that needs a second
 *    tooltip has failed.
 */
export interface GlossaryEntry {
  /** Shown as the tooltip heading — the term spelled out in full. */
  title: string
  /** One or two sentences. Plain language, no other jargon. */
  body: string
}

export const GLOSSARY: Record<string, GlossaryEntry> = {
  // ---------------------------------------------------------------- Nepal
  'book-close': {
    title: 'Book close',
    body: 'The cut-off date for a dividend, bonus or rights issue. Own the share on that date and it is yours; buy a day later and you get nothing, even if the payout arrives months afterwards.',
  },
  kitta: {
    title: 'Kitta',
    body: 'The Nepali word for a single unit of a share. "100 kitta" simply means 100 shares.',
  },
  scrip: {
    title: 'Scrip',
    body: 'One listed company or fund, identified by its short symbol — NABIL, NTC, and so on. Used the way "stock" or "ticker" is elsewhere.',
  },
  'par-value': {
    title: 'Par value',
    body: 'The fixed face value a new issue is sold at: Rs 100 for an ordinary share, Rs 10 for a mutual fund unit. An allotment priced at exactly par is almost always an IPO rather than a market purchase.',
  },
  ipo: {
    title: 'IPO',
    body: 'A company selling shares to the public for the first time, at par value and allotted by lottery when oversubscribed. You apply through MeroShare and the money leaves your bank directly, so it never appears in a broker statement.',
  },
  fpo: {
    title: 'FPO',
    body: 'A further public offering — an already-listed company selling more shares, usually above par.',
  },
  bonus: {
    title: 'Bonus shares',
    body: 'Extra shares handed out free against what you already hold, instead of cash. Your unit count rises and your average cost per unit falls; the total you paid does not change.',
  },
  rights: {
    title: 'Rights issue',
    body: 'An offer to existing holders to buy more shares, usually at par, in proportion to their holding. Like an IPO it is paid from your bank rather than through the broker.',
  },
  agm: {
    title: 'AGM',
    body: 'The annual general meeting, where dividends are formally approved. Its book-close date decides who may attend and who receives the payout.',
  },
  'dp-charge': {
    title: 'DP charge',
    body: 'A flat fee your depository participant takes per scrip each day you trade it — currently Rs 25. Small, but it makes tiny trades disproportionately expensive.',
  },
  'sebon-fee': {
    title: 'SEBON fee',
    body: 'A regulator levy of 0.015% of turnover on every trade, charged on both the buy and the sell.',
  },
  commission: {
    title: 'Broker commission',
    body: 'The broker\'s cut, tiered by trade size — from 0.36% on small trades down to 0.24% on very large ones. With the SEBON fee and DP charge, a full round trip costs roughly 0.6–1.2%.',
  },
  'fiscal-year': {
    title: 'Nepali fiscal year',
    body: 'Runs Shrawan 1 to Ashad end — mid-July to mid-July — not January to December. Capital gains and broker statements are reported on this calendar.',
  },
  promoter: {
    title: 'Promoter share',
    body: 'Shares held by a company\'s founders, traded in a separate and much less liquid market, usually well below the ordinary share price.',
  },
  debenture: {
    title: 'Debenture',
    body: 'A corporate bond paying a fixed rate of interest. It has no growth potential, so momentum and trend signals say nothing useful about it.',
  },
  nav: {
    title: 'NAV',
    body: 'Net asset value — what one mutual fund unit is actually worth, based on the fund\'s holdings. A closed-end fund often trades below its NAV.',
  },
  'circuit-breaker': {
    title: 'Circuit breaker',
    body: 'NEPSE\'s daily limit on how far one scrip may move — about 10%. A price beyond that in the data is a sign of a bad reading, not a real move.',
  },
  ltp: {
    title: 'LTP',
    body: 'Last traded price — what the scrip changed hands at most recently. On a thinly traded scrip this can be days old.',
  },
  turnover: {
    title: 'Turnover',
    body: 'The total rupee value traded, not the number of shares. High turnover means the price you see is one people are genuinely dealing at.',
  },
  breadth: {
    title: 'Market breadth',
    body: 'How many scrips rose versus fell. A market can be up on the index while most scrips fall, which breadth reveals and the index hides.',
  },

  // ------------------------------------------------------------- Portfolio
  'cost-basis': {
    title: 'Cost basis',
    body: 'What you actually paid for the shares you still hold, including fees. Gains are measured against this.',
  },
  wacc: {
    title: 'WACC / average cost',
    body: 'Weighted average cost — your single blended purchase price across several buys at different prices. MeroShare reports it per scrip.',
  },
  realised: {
    title: 'Realised profit',
    body: 'Profit you have actually banked by selling. Until you sell, a gain is only on paper.',
  },
  unrealised: {
    title: 'Unrealised profit',
    body: 'Profit on paper — what you would make if you sold everything at today\'s price. It moves every day and is not yours until you sell.',
  },
  xirr: {
    title: 'XIRR',
    body: 'Your annualised return, accounting for when each rupee went in. Rs 1 lakh invested last month and Rs 1 lakh invested three years ago are not comparable, and a simple percentage treats them as if they were.',
  },
  'money-weighted': {
    title: 'Money-weighted',
    body: 'A comparison that respects timing: money added later had less time to work, and money withdrawn stopped working when you took it out. It measures what your actual decisions earned.',
  },
  'fixed-deposit': {
    title: 'Fixed deposit',
    body: 'Money locked in a bank for a set term at a set interest rate. Used here as the honest baseline: did taking market risk beat simply leaving the money in the bank?',
  },
  'net-worth': {
    title: 'Net worth',
    body: 'Everything together — shares at today\'s price, plus idle cash, plus any fixed deposits. The single number for what the portfolio is worth right now.',
  },

  // ------------------------------------------------------------- Analysis
  beta: {
    title: 'Beta',
    body: 'How much your portfolio moves when the market moves. Above 1 means it swings harder than the market in both directions. It measures volatility, not the chance of losing money — those are different things.',
  },
  alpha: {
    title: 'Alpha',
    body: 'Return beyond what the risk you took would explain. Positive alpha means the picking added something; negative means you would have done better simply owning the market.',
  },
  correlation: {
    title: 'Correlation',
    body: 'How closely your portfolio tracks the market, from -1 to 1. Near 1 means you essentially own the market; near 0 means your holdings move on their own.',
  },
  'sub-index': {
    title: 'Sub-index',
    body: 'An index for one sector only — banks, hydropower, finance. Comparing a bank holding against the banking sub-index is fairer than against the whole market.',
  },
  attribution: {
    title: 'Attribution',
    body: 'Breaking a return apart to see where it came from: which holdings moved the number, and whether your picks beat simply owning their sector.',
  },
  contribution: {
    title: 'Contribution',
    body: 'How many percentage points one holding added to, or took off, the whole portfolio\'s return. A big loss in a small position matters less than a small loss in a large one.',
  },
  coverage: {
    title: 'Coverage',
    body: 'How much of the period the data actually spans. A comparison computed over part of a window can point the opposite way to the full one, so it is labelled rather than quietly reported.',
  },
  'capital-gains': {
    title: 'Capital gains tax',
    body: 'Tax on profit from selling shares: 7.5% if held under 365 days, 5% if held longer. It is withheld per sale, so a loss on one trade does not reduce the tax on another.',
  },
  'acquisition-channel': {
    title: 'How you acquired it',
    body: 'Whether shares came from an IPO lottery, a purchase on the market, a bonus issue or a rights offer. Only what you bought at a chosen price says anything about your judgement.',
  },
  'entry-band': {
    title: 'Entry band',
    body: 'The price range at which a scrip is worth buying on this strategy. Pay above it and the trade\'s risk and reward no longer line up.',
  },
  stop: {
    title: 'Stop',
    body: 'The price at which you accept the idea was wrong and sell. Set before buying, so the decision is not made while losing money.',
  },
  target: {
    title: 'Target',
    body: 'The price at which you plan to take the profit.',
  },
  'investor-profile': {
    title: 'Investor profile',
    body: 'Whose question is being answered. The same scrip is a buy for a long-term holder and a pass for a swing trader, so every verdict is scored separately for each.',
  },
  rsi: {
    title: 'RSI',
    body: 'A 0–100 gauge of how hard a scrip has been bought or sold lately. Above 70 it has run hot, below 30 it has been dumped — but a strong trend can hold either extreme for weeks, so it is a hint, not a signal.',
  },
  macd: {
    title: 'MACD',
    body: 'Compares a short-term average price against a longer-term one to show whether momentum is building or fading. Rising bars mean the recent move is gathering pace.',
  },
  sma: {
    title: 'Moving average',
    body: 'The average closing price over a number of days, which smooths out daily noise. Price above its average is usually read as an uptrend.',
  },
  'annualised-return': {
    title: 'Annualised return',
    body: 'A part-year result stretched to a yearly rate, so periods of different lengths can be compared. Treat a short period with care: six weeks at -6% annualises to -48%, which is an extrapolation rather than a result.',
  },
  'win-rate': {
    title: 'Win rate',
    body: 'The share of closed trades that made money. On its own it says little — many small wins and one large loss is a losing strategy with a high win rate.',
  },
  'net-profit': {
    title: 'Net profit',
    body: 'Everything together for the period: gains you banked by selling, plus the change on paper in what you still hold.',
  },
  't-plus-2': {
    title: 'T+2 settlement',
    body: 'Money and shares change hands two working days after the trade. Your broker may carry the balance in between, which is why a statement can briefly show a negative figure.',
  },
}

/** Whether a term has a definition, so a caller can fail loudly in development. */
export function hasGlossaryEntry(term: string): boolean {
  // Not Object.hasOwn: this project targets ES2020, and bumping the whole
  // compilation target for one convenience method is not worth it.
  return Object.prototype.hasOwnProperty.call(GLOSSARY, term)
}
