import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { App } from './App'
import { AuthProvider } from './lib/auth'
import { ProfileProvider } from './lib/profile'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Market data changes once a day, so aggressive refetching would only add
      // load without adding information.
      staleTime: 60 * 1000,
      refetchOnWindowFocus: false,
      retry: (failureCount, error) => {
        // Never retry a client error — a 422 or 403 will fail identically.
        const status = (error as { response?: { status?: number } }).response?.status
        if (status !== undefined && status >= 400 && status < 500) return false
        return failureCount < 2
      },
    },
  },
})

const root = document.getElementById('root')

if (root === null) {
  throw new Error('Root element #root is missing from index.html')
}

ReactDOM.createRoot(root).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <ProfileProvider>
            <App />
          </ProfileProvider>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
)
