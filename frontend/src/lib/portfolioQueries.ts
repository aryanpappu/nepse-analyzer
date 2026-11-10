import type { QueryClient } from '@tanstack/react-query'

/**
 * Every cached view a money movement changes.
 *
 * A cash correction does not only change the ledger it was made in: the
 * portfolio summary re-derives net worth from it, the performance report
 * re-runs XIRR and the deposit comparison over the amended flows, and the
 * market benchmark re-buys index units at the amended dates. Leaving any of
 * those on screen after a correction reads as the bug the correction was
 * fixing, so they are all dropped together.
 *
 * Keys are prefixes on purpose — React Query matches partially, so
 * `['performance', id]` also invalidates `['performance', id, basis, rate]`
 * without this file having to know which basis or rate a page happens to be
 * showing.
 */
export function invalidatePortfolioMoney(client: QueryClient, portfolioId: number): void {
  const keys: unknown[][] = [
    ['portfolios'],
    ['portfolio', portfolioId],
    ['cash-ledger', portfolioId],
    ['cash-placements', portfolioId],
    ['performance', portfolioId],
    ['benchmark', portfolioId],
    // Position advice sizes "add" suggestions from investable cash.
    ['portfolio-actions', portfolioId],
    /*
     * The getting-started checklist is derived from the same ledger. Recording
     * the first cash movement is what completes one of its steps, and a stale
     * card would go on telling the user to do what they just did.
     */
    ['onboarding'],
  ]

  for (const queryKey of keys) {
    void client.invalidateQueries({ queryKey })
  }
}
