import { errorMessage } from '../lib/api'
import { useSiteInformation } from '../lib/site'
import { Card, ErrorBox } from '../components/ui'

export function About() {
  const site = useSiteInformation()

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">About {site.data?.name ?? 'NEPSE Analyzer'}</h1>
        <p className="mt-2 text-slate-600">Market research and personal portfolio tracking for the Nepal Stock Exchange.</p>
      </div>
      <Card title="Understand the analysis">
        <div className="space-y-3 text-sm leading-relaxed text-slate-600">
          <p>Market prices come from public market-data sources and may be delayed, incomplete, or corrected. Check the date accompanying each quote.</p>
          <p>Rule-based scores and AI analysis are research tools. A buy label, target, or confidence score does not guarantee a return. AI confidence is not a measured probability of profit.</p>
          <p>Entry ranges, stops, and estimated fees are planning inputs. Actual execution, liquidity, fees, and market conditions may differ. This application does not place orders with a broker.</p>
        </div>
      </Card>
      <section id="disclaimer" className="scroll-mt-6">
        <Card title="Disclaimer">
          <div className="space-y-3 text-sm leading-relaxed text-slate-600">
            <p>This is an educational and research project, offered free and as is. Nothing on this site is financial, investment, tax or legal advice, or a recommendation to buy, sell or hold any security.</p>
            <p>The authors and operators are not licensed investment advisers, portfolio managers or stockbrokers with the Securities Board of Nepal (SEBON). Investing involves risk, including loss of your capital; past performance and backtests do not predict future results. Consult a licensed professional before investing.</p>
            <p>This site is independent and not affiliated with NEPSE, SEBON, CDSC/MeroShare or any broker. Trademarks and data belong to their respective owners.</p>
            <p>To the maximum extent permitted by law, the authors and operators accept no liability for any loss arising from use of this site or its information.</p>
            <p>
              The source code is open under the MIT License.{' '}
              <a className="underline" href="https://github.com/aryanpappu/nepse-analyzer/blob/main/DISCLAIMER.md" target="_blank" rel="noopener noreferrer">Read the full disclaimer</a>.
            </p>
          </div>
        </Card>
      </section>
      <section id="data-use" className="scroll-mt-6">
        <Card title="How your data is used">
          <div className="space-y-3 text-sm leading-relaxed text-slate-600">
            <p>Your account, imported financial records, transactions, and saved analyses are stored to provide portfolio tracking. Your portfolios require authentication and are separated from other accounts.</p>
            <p>Requesting AI analysis sends relevant market information and, for portfolio analysis, relevant holdings and financial context to the AI provider selected by the operator. Provider processing and retention are subject to that provider’s terms.</p>
            <p>Sign-in tokens are stored in this browser. Sign out on shared devices. Operational logs and backups may retain information separately from the active database.</p>
            <p>In Settings you can verify your email, download a password-confirmed copy of your data, or permanently delete your active account and portfolio records.</p>
          </div>
        </Card>
      </section>
      {site.isError && <ErrorBox message={errorMessage(site.error, 'Operator information is temporarily unavailable.')} />}
      {site.data && (site.data.operator_name || site.data.contact_email || site.data.privacy_url || site.data.terms_url) && (
        <Card title="Operator & policies">
          <div className="space-y-3 text-sm text-slate-600">
            {site.data.operator_name && <p>Operated by {site.data.operator_name}.</p>}
            {site.data.contact_email && <p>Contact: <a className="underline" href={`mailto:${site.data.contact_email}`}>{site.data.contact_email}</a></p>}
            {site.data.privacy_url && <p><a className="underline" href={site.data.privacy_url}>Privacy policy</a></p>}
            {site.data.terms_url && <p><a className="underline" href={site.data.terms_url}>Terms of use</a></p>}
          </div>
        </Card>
      )}
    </div>
  )
}
