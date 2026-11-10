import { useState } from 'react'
import { Link, Navigate, useNavigate, useLocation } from 'react-router-dom'
import { errorMessage } from '../lib/api'
import { useAuth } from '../lib/auth'
import { useSiteInformation } from '../lib/site'
import { ErrorBox, Spinner } from '../components/ui'

export function Login() {
  const { user, loading, login, register } = useAuth()
  const site = useSiteInformation()
  const registrationOpen = site.data?.registration_open === true
  const requireLogin = site.data?.require_login === true
  const navigate = useNavigate()
  const location = useLocation()
  const state = location.state as { from?: string } | null
  const destination = state?.from?.startsWith('/') && !state.from.startsWith('//')
    ? state.from
    : '/portfolio'

  const [mode, setMode] = useState<'login' | 'register'>('login')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (loading) {
    return <Spinner label="Checking your session…" />
  }

  if (user !== null) {
    return <Navigate to={destination} replace />
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setBusy(true)

    try {
      if (mode === 'login' || !registrationOpen) {
        await login(email, password)
      } else {
        await register(name, email, password)
      }

      navigate(destination, { replace: true })
    } catch (caught) {
      setError(errorMessage(caught, 'Could not sign you in.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-md">
        <div className="mb-6 text-center">
          <span className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-slate-900 text-lg font-bold text-white">
            N
          </span>
          <h1 className="text-2xl font-semibold tracking-tight">NEPSE Analyzer</h1>
          <p className="mt-1 text-sm text-slate-500">
            Stock analysis and portfolio tracking for the Nepal Stock Exchange
          </p>
        </div>

        <div className="card p-6">
          {registrationOpen && (
          <div className="mb-5 flex gap-1 rounded-lg bg-slate-100 p-1">
            {(['login', 'register'] as const).map((option) => (
              <button
                key={option}
                type="button"
                onClick={() => {
                  setMode(option)
                  setError(null)
                }}
                className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                  mode === option ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'
                }`}
              >
                {option === 'login' ? 'Sign in' : 'Create account'}
              </button>
            ))}
          </div>
          )}
          {!registrationOpen && site.isSuccess && (
            <p className="mb-4 text-sm text-slate-500">Accounts are by invitation. Ask the administrator for access.</p>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            {mode === 'register' && (
              <div>
                <label className="label" htmlFor="name">
                  Name
                </label>
                <input
                  id="name"
                  className="input"
                  value={name}
                  onChange={(event) => setName(event.target.value)}
                  required
                  autoComplete="name"
                />
              </div>
            )}

            <div>
              <label className="label" htmlFor="email">
                Email
              </label>
              <input
                id="email"
                type="email"
                className="input"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
                autoComplete="email"
              />
            </div>

            <div>
              <label className="label" htmlFor="password">
                Password
              </label>
              <input
                id="password"
                type="password"
                className="input"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                minLength={8}
                autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
              />
              {mode === 'register' && (
                <p className="mt-1 text-xs text-slate-400">At least 8 characters.</p>
              )}
            </div>

            {error !== null && <ErrorBox message={error} />}

            <button type="submit" className="btn-primary w-full" disabled={busy}>
              {busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account'}
            </button>
          </form>
          {mode === 'login' && (
            <Link to="/forgot-password" className="mt-4 inline-block text-sm font-medium underline">Forgot password?</Link>
          )}
        </div>

        {!requireLogin && (
          <p className="mt-4 text-center text-sm text-slate-500">
            <Link to="/" className="font-medium underline">
              Browse market data without signing in
            </Link>
          </p>
        )}
        <p className="mt-6 text-center text-xs text-slate-400">
          Educational and research project — not investment advice. Not affiliated with NEPSE, SEBON or CDSC.
        </p>
      </div>
    </div>
  )
}
