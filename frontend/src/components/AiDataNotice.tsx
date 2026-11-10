import { Link } from 'react-router-dom'

export function AiDataNotice() {
  return (
    <p className="mt-3 text-xs leading-relaxed text-slate-500">
      AI requests share relevant market information and portfolio context with the AI provider.{' '}
      <Link to="/about#data-use" className="underline">Learn about data use</Link>.
    </p>
  )
}
