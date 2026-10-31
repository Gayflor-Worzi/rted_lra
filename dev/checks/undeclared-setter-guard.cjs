#!/usr/bin/env node
/**
 * Undeclared-setter guard (frontend).
 *
 * Catches the bug where a component calls a React state setter that it does not
 * own — typically a child component reaching for its parent's `setNotice` or
 * similar. The failure mode is nasty because the throw happens *after* the
 * network write has already succeeded, so the save silently appears to fail
 * while the record was actually written.
 *
 * Real example this was written for: `ValuationForm` in frontend/src/pages/
 * Valuations.jsx called the parent `Valuations` component's `setNotice`, which
 * threw "setNotice is not defined" only on the success path of saving.
 *
 * Method: for each top-level component function, collect the `set*` names it
 * declares (via useState destructuring or plain assignment) and the `set*` names
 * it calls, then report any call with no local declaration. Browser globals that
 * merely match the `set*` shape are allow-listed.
 *
 * Usage:  node dev/checks/undeclared-setter-guard.cjs frontend/src
 * Exit:   0 = clean, 1 = at least one finding.
 *
 * Heuristic, not a type checker: it inspects one component function at a time
 * and cannot see through helpers, so a finding is a strong hint to inspect by
 * hand, and a clean run is not a proof of absence.
 */
const fs = require('fs')
const path = require('path')

// Names that look like a setter but are not component state.
const GLOBALS = new Set(['setTimeout', 'setInterval', 'setItem'])

function walk(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, entry.name)
        if (entry.isDirectory()) walk(p, out)
        else if (/\.jsx?$/.test(entry.name)) out.push(p)
    }
    return out
}

function findComponentBounds(lines) {
    const bounds = []
    lines.forEach((line, i) => {
        const m = line.match(/^(?:export\s+)?function\s+([A-Z][A-Za-z0-9_]*)\s*\(/)
        if (m) bounds.push({ name: m[1], start: i })
    })
    bounds.forEach((b, idx) => {
        b.end = idx + 1 < bounds.length ? bounds[idx + 1].start : lines.length
    })
    return bounds
}

function scanComponent(body, offset, report) {
    const declared = new Set()
    const called = new Map()

    body.forEach((line, i) => {
        // Array destructuring: const [form, setForm] = useState(...)
        for (const m of line.matchAll(/(?:const|let|var)\s*\[([^\]]*)\]\s*=/g)) {
            for (const part of m[1].split(',')) {
                const id = part.trim().match(/^(set[A-Z][A-Za-z0-9_]*)/)
                if (id) declared.add(id[1])
            }
        }
        // Plain declaration: const setRows = ...
        for (const m of line.matchAll(/(?:const|let|var)\s+(set[A-Z][A-Za-z0-9_]*)\s*=/g)) {
            declared.add(m[1])
        }
        for (const m of line.matchAll(/\b(set[A-Z][A-Za-z0-9_]*)\s*\(/g)) {
            if (!called.has(m[1])) called.set(m[1], offset + i + 1)
        }
    })

    for (const [name, line] of called) {
        if (declared.has(name) || GLOBALS.has(name)) continue
        report.push({ name, line })
    }
}

const root = process.argv[2]
if (!root) {
    console.error('usage: node dev/checks/undeclared-setter-guard.cjs <src-dir>')
    process.exit(2)
}

let findings = 0
let files = 0
let components = 0

for (const file of walk(root)) {
    files++
    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/)
    const report = []

    for (const b of findComponentBounds(lines)) {
        components++
        scanComponent(lines.slice(b.start, b.end), b.start, report)
    }

    for (const f of report) {
        findings++
        console.log(`  ${path.relative(root, file)}:${f.line}  component calls ${f.name} but never declares it`)
    }
}

console.log(`scanned ${components} component(s) in ${files} file(s), ${findings} problem(s)`)
process.exit(findings === 0 ? 0 : 1)
