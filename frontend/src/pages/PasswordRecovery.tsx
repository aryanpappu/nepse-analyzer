import { useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { ErrorBox } from '../components/ui'
import { api, errorMessage, setToken } from '../lib/api'
import { useAuth } from '../lib/auth'

export function PasswordRecovery() {
  const { logout } = useAuth()
  const location = useLocation()
  const [params] = useSearchParams()
  const resetting = location.pathname === '/reset-password'
  const token = params.get('token') ?? ''
  const [email, setEmail] = useState(params.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    setMessage(null)
    if (resetting && password !== confirmation) {
      setError('The passwords do not match.')
      return
    }
    setBusy(true)
    try {
      const response = await api.post<{ message: string }>(
        resetting ? '/auth/reset-password' : '/auth/forgot-password',
        resetting ? { email, token, password, password_confirmation: confirmation } : { email },
      )
      if (resetting) {
        setToken(null)
        await logout()
        setPassword('')
        setConfirmation('')
      }
      setMessage(response.data.message)
    } catch (caught) {
      setError(errorMessage(caught, 'Could not complete your request. Please try again.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="card w-full max-w-md p-6">
        <h1 className="text-xl font-semibold">{resetting ? 'Choose a new password' : 'Forgot your password?'}</h1>
        <p className="mt-2 text-sm text-slate-500">
          {resetting ? 'Resetting your password signs out all existing sessions.' : 'Enter your account email to request a reset link.'}
        </p>
        {message !== null ? (
          <p role="status" className="mt-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{message}</p>
        ) : resetting && token === '' ? (
          <p role="alert" className="mt-5 text-sm text-red-700">This link is incomplete. <Link to="/forgot-password" className="underline">Request a new reset link.</Link></p>
        ) : (
          <form onSubmit={submit} className="mt-5 space-y-4">
            <div>
              <label className="label" htmlFor="recovery-email">Email</label>
              <input id="recovery-email" type="email" autoComplete="email" className="input" value={email} onChange={(event) => setEmail(event.target.value)} required maxLength={255} />
            </div>
            {resetting && <>
              <div>
                <label className="label" htmlFor="new-password">New password</label>
                <input id="new-password" type="password" autoComplete="new-password" className="input" value={password} onChange={(event) => setPassword(event.target.value)} minLength={8} required />
              </div>
              <div>
                <label className="label" htmlFor="confirm-password">Confirm new password</label>
                <input id="confirm-password" type="password" autoComplete="new-password" className="input" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} minLength={8} required />
              </div>
            </>}
            {error !== null && <ErrorBox message={error} />}
            <button type="submit" className="btn-primary w-full" disabled={busy}>{busy ? 'Please wait…' : resetting ? 'Reset password' : 'Send reset link'}</button>
          </form>
        )}
        <Link to="/login" className="mt-5 inline-block text-sm font-medium underline">Back to sign in</Link>
      </div>
    </main>
  )
}
