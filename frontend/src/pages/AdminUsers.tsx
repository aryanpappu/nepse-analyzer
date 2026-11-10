import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '../lib/api'
import { shortDate } from '../lib/format'
import { Card, ErrorBox, Spinner } from '../components/ui'

interface ManagedUser {
  id: number
  name: string
  email: string
  role: 'user' | 'admin'
  email_verified_at: string | null
  created_at: string
}

interface NewUser {
  name: string
  email: string
  password: string
  role: 'user' | 'admin'
}

const EMPTY: NewUser = { name: '', email: '', password: '', role: 'user' }

/**
 * Account administration. With registration closed this is the only way a new
 * person gets an account: the admin sets an initial password and shares it
 * privately; the user then changes it in Settings.
 */
export function AdminUsers() {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<NewUser>(EMPTY)
  const [created, setCreated] = useState<string | null>(null)

  const users = useQuery({
    queryKey: ['admin-users'],
    queryFn: async () => (await api.get<{ data: ManagedUser[] }>('/admin/users')).data.data,
  })

  const create = useMutation({
    mutationFn: async (payload: NewUser) => (await api.post<{ data: ManagedUser }>('/admin/users', payload)).data.data,
    onSuccess: async (user) => {
      setCreated(user.email)
      setForm(EMPTY)
      await queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    },
  })

  function update<K extends keyof NewUser>(key: K, value: NewUser[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">Users</h1>
        <p className="mt-1 text-sm text-slate-600">
          Create accounts for people you invite. Share the initial password privately and ask them to change it in Settings.
        </p>
      </div>

      <Card title="Create an account">
        <form
          className="grid gap-4 sm:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault()
            setCreated(null)
            create.mutate(form)
          }}
        >
          <div>
            <label className="label" htmlFor="new-user-name">Name</label>
            <input id="new-user-name" className="input" value={form.name} onChange={(e) => update('name', e.target.value)} required minLength={2} autoComplete="off" />
          </div>
          <div>
            <label className="label" htmlFor="new-user-email">Email</label>
            <input id="new-user-email" type="email" className="input" value={form.email} onChange={(e) => update('email', e.target.value)} required autoComplete="off" />
          </div>
          <div>
            <label className="label" htmlFor="new-user-password">Initial password</label>
            <input id="new-user-password" type="password" className="input" value={form.password} onChange={(e) => update('password', e.target.value)} required minLength={12} autoComplete="new-password" />
            <p className="mt-1 text-xs text-slate-400">At least 12 characters.</p>
          </div>
          <div>
            <label className="label" htmlFor="new-user-role">Role</label>
            <select id="new-user-role" className="input" value={form.role} onChange={(e) => update('role', e.target.value as NewUser['role'])}>
              <option value="user">User</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <div className="sm:col-span-2 space-y-3">
            {create.isError && <ErrorBox message={errorMessage(create.error, 'Could not create the account.')} />}
            {created !== null && <p className="text-sm text-emerald-700">Account created for {created}.</p>}
            <button type="submit" className="btn-primary" disabled={create.isPending}>
              {create.isPending ? 'Creating…' : 'Create account'}
            </button>
          </div>
        </form>
      </Card>

      <Card title="Accounts">
        {users.isLoading && <Spinner label="Loading accounts…" />}
        {users.isError && <ErrorBox message={errorMessage(users.error, 'Could not load accounts.')} onRetry={() => users.refetch()} />}
        {users.data && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="text-xs uppercase text-slate-500">
                <tr>
                  <th className="py-2 pr-4">Name</th>
                  <th className="py-2 pr-4">Email</th>
                  <th className="py-2 pr-4">Role</th>
                  <th className="py-2 pr-4">Verified</th>
                  <th className="py-2">Created</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.data.map((user) => (
                  <tr key={user.id}>
                    <td className="py-2 pr-4">{user.name}</td>
                    <td className="py-2 pr-4">{user.email}</td>
                    <td className="py-2 pr-4">{user.role === 'admin' ? 'Administrator' : 'User'}</td>
                    <td className="py-2 pr-4">{user.email_verified_at ? 'Yes' : 'No'}</td>
                    <td className="py-2">{shortDate(user.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
