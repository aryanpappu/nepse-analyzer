import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, errorMessage, setToken } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Card, ErrorBox } from './ui'

export function AccountDataControls() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState<'export' | 'delete' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function download() {
    setBusy('export')
    setError(null)
    setNotice(null)
    try {
      const response = await api.post('/auth/export', { password })
      const url = URL.createObjectURL(new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' }))
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = 'nepse-account-export.json'
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
      setPassword('')
      setNotice('Your export has been downloaded. Keep it private: it contains your financial records.')
    } catch (caught) {
      setError(errorMessage(caught, 'Could not download your data.'))
    } finally {
      setBusy(null)
    }
  }

  async function remove(event: React.FormEvent) {
    event.preventDefault()
    setBusy('delete')
    setError(null)
    setNotice(null)
    try {
      await api.delete('/auth/me', { data: { password, confirmation } })
      setToken(null)
      await logout()
      navigate('/login', { replace: true })
    } catch (caught) {
      setError(errorMessage(caught, 'Could not delete your account.'))
      setBusy(null)
    }
  }

  return (
    <Card title="Your data" subtitle="Download a copy of your records or permanently close your account.">
      <p className="text-sm text-slate-600">Your JSON export includes account details, all portfolios, transactions, imports, and saved analyses. Passwords and sign-in tokens are excluded.</p>
      <label className="mt-4 block max-w-sm">
        <span className="label">Confirm your current password</span>
        <input type="password" autoComplete="current-password" className="input w-full" value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy !== null} />
      </label>
      <button type="button" className="btn-secondary mt-3" onClick={() => void download()} disabled={busy !== null || password === ''}>{busy === 'export' ? 'Preparing export…' : 'Download my data'}</button>
      <form onSubmit={remove} className="mt-6 border-t border-slate-200 pt-5">
        <h3 className="font-medium text-red-700">Delete account permanently</h3>
        <p className="mt-1 text-sm text-slate-600">This removes all your portfolios, financial records, imports, and saved analyses from the active database and signs out every session. Download your data first if you want to keep a copy. Operational backups may retain data until they expire.</p>
        <label className="mt-3 block max-w-sm">
          <span className="label">Type DELETE to confirm</span>
          <input className="input w-full" autoComplete="off" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required pattern="DELETE" disabled={busy !== null} />
        </label>
        <button type="submit" className="mt-3 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={busy !== null || password === '' || confirmation !== 'DELETE'}>{busy === 'delete' ? 'Deleting account…' : 'Delete my account'}</button>
      </form>
      {error !== null && <div className="mt-4"><ErrorBox message={error} /></div>}
      {notice !== null && <p role="status" className="mt-4 text-sm text-emerald-700">{notice}</p>}
    </Card>
  )
}
