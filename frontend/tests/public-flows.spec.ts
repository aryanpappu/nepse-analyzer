import { expect, test, type Page } from 'playwright/test'

const profiles = [
  { value: 'long_term', label: 'Long-term investor', short_label: 'Long term' },
  { value: 'swing', label: 'Swing trader', short_label: 'Swing' },
  { value: 'momentum', label: 'Momentum profit-taker', short_label: 'Momentum' },
].map((profile) => ({ ...profile, description: 'Test profile', hold_label: 'Test horizon' }))

function account(name = 'Alice') {
  return {
    id: name === 'Alice' ? 1 : 2, name, email: `${name.toLowerCase()}@example.com`,
    role: 'user', is_admin: false, default_portfolio_id: null,
    email_verified_at: null, benchmark_fd_rate: null, benchmark_fd_rate_effective: 8,
    benchmark_fd_rate_default: 8, benchmark_index_slug: null,
    benchmark_index_slug_effective: 'nepse-index', investor_class: null,
    investor_class_effective: 'individual',
  }
}

const openSite = {
  name: 'NEPSE Analyzer', operator_name: null, contact_email: null, privacy_url: null,
  terms_url: null, require_login: false, registration_open: true,
}

async function mockApi(page: Page, site = openSite) {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname.replace('/api', '')
    const data = path === '/profiles' ? profiles
      : path === '/site-information' ? site
      : path === '/health' ? { latest_price_date: null, status: { level: 'ok' } }
      : path === '/onboarding' ? { steps: [], next: null, complete: true }
      : path === '/portfolios' || path === '/sectors' ? []
      : null
    await route.fulfill({ status: data === null ? 503 : 200, json: data === null ? { message: 'Test endpoint unavailable.' } : { data } })
  })
}

async function signIn(page: Page, name = 'Alice') {
  await page.route('**/api/auth/login', (route) => route.fulfill({ json: { data: { user: account(name), token: `test-${name}` } } }))
  await page.getByLabel('Email', { exact: true }).fill(`${name.toLowerCase()}@example.com`)
  await page.getByLabel('Password', { exact: true }).fill('test-password-123')
  await page.locator('form').getByRole('button', { name: 'Sign in', exact: true }).click()
}

test.beforeEach(async ({ page }) => {
  await mockApi(page)
})

test('private routes preserve the requested destination through sign-in', async ({ page }) => {
  await page.goto('/settings')
  await expect(page).toHaveURL(/\/login$/)
  await signIn(page)
  await expect(page).toHaveURL(/\/settings$/)
  await expect(page.getByRole('heading', { name: 'Profile & settings' })).toBeVisible()
  await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Alice')
})

test('registration submits confirmed credentials and opens the requested private page', async ({ page }) => {
  let submitted: unknown
  await page.route('**/api/auth/register', async (route) => {
    submitted = route.request().postDataJSON()
    await route.fulfill({ status: 201, json: { data: { user: account(), token: 'test-registration' } } })
  })
  await page.goto('/settings')
  await page.getByRole('button', { name: 'Create account', exact: true }).click()
  await page.getByLabel('Name', { exact: true }).fill('Alice')
  await page.getByLabel('Email', { exact: true }).fill('alice@example.com')
  await page.getByLabel('Password', { exact: true }).fill('test-password-123')
  await page.locator('form').getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/settings$/)
  expect(submitted).toEqual({ name: 'Alice', email: 'alice@example.com', password: 'test-password-123', password_confirmation: 'test-password-123' })
})

test('password recovery displays the neutral server response without sending real mail', async ({ page }) => {
  const message = 'If an account exists for that email, a password reset link will be sent.'
  let submitted: unknown
  await page.route('**/api/auth/forgot-password', async (route) => {
    submitted = route.request().postDataJSON()
    await route.fulfill({ json: { message } })
  })
  await page.goto('/login')
  await page.getByRole('link', { name: 'Forgot password?' }).click()
  await page.getByLabel('Email', { exact: true }).fill('unknown@example.com')
  await page.getByRole('button', { name: 'Send reset link' }).click()
  await expect(page.getByRole('status')).toHaveText(message)
  expect(submitted).toEqual({ email: 'unknown@example.com' })
})

test('password reset rejects mismatched confirmation before calling the API', async ({ page }) => {
  let calls = 0
  await page.route('**/api/auth/reset-password', async (route) => {
    calls++
    await route.fulfill({ json: { message: 'Your password has been reset.' } })
  })
  await page.goto('/reset-password?token=test-reset-token&email=alice%40example.com')
  await page.getByLabel('New password', { exact: true }).fill('new-password-123')
  await page.getByLabel('Confirm new password', { exact: true }).fill('different-password-123')
  await page.getByRole('button', { name: 'Reset password' }).click()
  await expect(page.getByRole('alert')).toContainText('passwords do not match')
  expect(calls).toBe(0)
  await page.getByLabel('Confirm new password', { exact: true }).fill('new-password-123')
  await page.getByRole('button', { name: 'Reset password' }).click()
  await expect(page.getByRole('status')).toHaveText('Your password has been reset.')
  expect(calls).toBe(1)
})

test('unknown pages provide a useful recovery path and a focusable main landmark', async ({ page }) => {
  await page.goto('/missing-page')
  await expect(page.getByRole('heading', { name: 'Page not found' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Market overview' })).toHaveAttribute('href', '/')
  await expect(page.locator('#main-content')).toBeFocused()
  await expect(page.getByRole('link', { name: 'Skip to content' })).toHaveAttribute('href', '#main-content')
  await expect(page.getByRole('button', { name: /Fetch/ })).toHaveCount(0)
})

test('signing out clears the previous account before another account signs in', async ({ page }) => {
  await page.route('**/api/auth/logout', (route) => route.fulfill({ json: { message: 'Signed out.' } }))
  await page.goto('/settings')
  await signIn(page)
  await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Alice')
  await page.getByRole('button', { name: 'Sign out', exact: true }).click()
  await expect(page).toHaveURL(/\/login$/)
  await signIn(page, 'Bob')
  // Wait for the sign-in to finish before reloading, or the reload races it.
  await expect(page).not.toHaveURL(/\/login$/)
  await page.route('**/api/auth/me', (route) => route.fulfill({ json: { data: account('Bob') } }))
  await page.goto('/settings')
  await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Bob')
  await expect(page.getByText('Alice', { exact: true })).toHaveCount(0)
})

test('recommendations follow the selected profile and do not reuse another profile result', async ({ page }) => {
  const member = { ...account(), default_portfolio_id: 1 }
  const summary = {
    total_invested: 0, total_value: 0, unrealized_gain: 0, unrealized_gain_percent: 0,
    realized_gain: 0, cash_in_hand: 10000, bank_balance: 0, investable_cash: 10000,
    net_worth: 10000, holdings_count: 0, unpriced_symbols: [], sector_allocation: [], concentration: [],
  }
  const portfolio = { id: 1, name: 'Test portfolio', currency: 'NPR', is_default: true, summary }
  await page.addInitScript(() => localStorage.setItem('nepse.token', 'test-profile-token'))
  await page.route('**/api/auth/me', (route) => route.fulfill({ json: { data: member } }))
  await page.route('**/api/portfolios', (route) => route.fulfill({ json: { data: [portfolio] } }))
  await page.route('**/api/portfolios/1', (route) => route.fulfill({ json: { data: { portfolio, holdings: [], latest_analysis: null } } }))
  const requestedProfiles: string[] = []
  let generated = false
  const run = {
    id: 1, investor_profile: 'swing', analysis_date: '2026-11-08', model: 'test-model',
    investable_cash: 10000, allocated_amount: 0, reserve_amount: 10000,
    strategy_summary: 'Swing-only test plan', avoid: [], recommendations: [],
  }
  await page.route('**/api/portfolios/1/recommendations?*', async (route) => {
    const profile = new URL(route.request().url()).searchParams.get('profile') ?? ''
    requestedProfiles.push(profile)
    await route.fulfill({ json: { data: profile === 'swing' && generated ? run : null } })
  })
  let submitted: unknown
  await page.route('**/api/portfolios/1/recommend', async (route) => {
    submitted = route.request().postDataJSON()
    generated = true
    await route.fulfill({ json: { data: run } })
  })
  await page.goto('/portfolio')
  await expect(page.getByRole('heading', { name: 'What to buy with idle cash' })).toBeVisible()
  await expect.poll(() => requestedProfiles).toContain('long_term')
  await page.getByRole('group', { name: 'Investor profile' }).getByRole('button', { name: 'Swing', exact: true }).click()
  await expect.poll(() => requestedProfiles).toContain('swing')
  await page.getByRole('button', { name: 'Get recommendations' }).click()
  await expect(page.getByText('Swing-only test plan')).toBeVisible()
  expect(submitted).toEqual({ profile: 'swing' })
  await page.getByRole('group', { name: 'Investor profile' }).getByRole('button', { name: 'Long term', exact: true }).click()
  await expect(page.getByText('Swing-only test plan')).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Get recommendations' })).toBeVisible()
})

test('a private, invite-only site sends visitors to sign-in and offers no sign-up', async ({ page }) => {
  await page.unrouteAll()
  await mockApi(page, { ...openSite, require_login: true, registration_open: false })
  await page.goto('/stocks')
  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByRole('button', { name: 'Create account', exact: true })).toHaveCount(0)
  await expect(page.getByText('Accounts are by invitation.', { exact: false })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Browse market data without signing in' })).toHaveCount(0)
})
