import { Component } from 'react'

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { err: null }
  }

  static getDerivedStateFromError(err) { return { err } }

  componentDidCatch(err, info) { console.error('Render error:', err, info) }

  render() {
    if (this.state.err) {
      return (
        <div className="min-h-screen grid place-items-center p-6 bg-slate-50">
          <div className="bg-white rounded-2xl border border-slate-200 p-6 max-w-lg w-full space-y-3">
            <div className="font-bold text-navy-800">Something went wrong on this screen.</div>
            <div className="text-xs text-red-500 font-mono break-words">{String(this.state.err.message || this.state.err)}</div>
            <div className="flex gap-2">
              <button onClick={() => window.location.reload()}
                className="px-4 py-2 rounded-xl bg-navy-800 text-white text-sm font-semibold">Reload</button>
              <button onClick={() => { this.setState({ err: null }); window.location.assign('/dashboard') }}
                className="px-4 py-2 rounded-xl bg-white border border-slate-300 text-sm">Back to dashboard</button>
            </div>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}