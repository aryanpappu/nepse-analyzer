import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { Layout } from './components/Layout'
import { Spinner } from './components/ui'
import { useAuth } from './lib/auth'
import { Dashboard } from './pages/Dashboard'
import { StockList } from './pages/StockList'
import { StockDetail } from './pages/StockDetail'
import { Signals } from './pages/Signals'
import { Sectors } from './pages/Sectors'
import { Attribution } from './pages/Attribution'
import { Calendar } from './pages/Calendar'
import { CapitalGains } from './pages/CapitalGains'
import { Channels } from './pages/Channels'
import { Compare } from './pages/Compare'
import { PortfolioPage } from './pages/Portfolio'
import { Performance } from './pages/Performance'
import { ImportWizard } from './pages/ImportWizard'
import { CashImportPage } from './pages/CashImport'
import { Login } from './pages/Login'
import { Settings } from './pages/Settings'
import { VerifyEmail } from './pages/VerifyEmail'
import { About } from './pages/About'
import { NotFound } from './pages/NotFound'
import { PasswordRecovery } from './pages/PasswordRecovery'
import type { ReactNode } from 'react'

/** Gates routes that need a signed-in user. */
function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return <Spinner label="Checking your session…" />
  }

  if (user === null) {
    return <Navigate to="/login" state={{ from: location.pathname + location.search + location.hash }} replace />
  }

  return <>{children}</>
}

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/forgot-password" element={<PasswordRecovery />} />
      <Route path="/reset-password" element={<PasswordRecovery />} />

      <Route element={<Layout />}>
        {/* Public market data */}
        <Route index element={<Dashboard />} />
        <Route path="about" element={<About />} />
        <Route path="stocks" element={<StockList />} />
        <Route path="stocks/:symbol" element={<StockDetail />} />
        <Route path="signals" element={<Signals />} />
        <Route path="sectors" element={<Sectors />} />
        <Route path="compare" element={<Compare />} />

        {/* Requires an account */}
        <Route path="verify-email" element={<RequireAuth><VerifyEmail /></RequireAuth>} />
        <Route
          path="portfolio"
          element={
            <RequireAuth>
              <PortfolioPage />
            </RequireAuth>
          }
        />
        <Route
          path="performance"
          element={
            <RequireAuth>
              <Performance />
            </RequireAuth>
          }
        />
        <Route
          path="import"
          element={
            <RequireAuth>
              <ImportWizard />
            </RequireAuth>
          }
        />
        <Route
          path="cash-import"
          element={
            <RequireAuth>
              <CashImportPage />
            </RequireAuth>
          }
        />

        <Route path="channels" element={<RequireAuth><Channels /></RequireAuth>} />
        <Route path="capital-gains" element={<RequireAuth><CapitalGains /></RequireAuth>} />
        <Route path="attribution" element={<RequireAuth><Attribution /></RequireAuth>} />
        <Route path="calendar" element={<Calendar />} />
        <Route path="settings" element={<RequireAuth><Settings /></RequireAuth>} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  )
}
