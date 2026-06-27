#!/usr/bin/env node
/**
 * Single source of truth for this repo's version.
 *
 * package.json is the canonical version. Everything else is DERIVED from it:
 *   - the WordPress header (style.css, or the main plugin file)
 *   - selected in-repo docs that cite the current version
 *   - package-lock.json root version + the git tag (handled natively by `npm version`)
 *
 * Entry points (see package.json "scripts"):
 *   npm version <patch|minor|major|x.y.z>
 *       The one command. npm bumps package.json + package-lock.json, then the
 *       "version" lifecycle hook runs this script (in --stage mode) to write the
 *       header + docs and `git add` them, and npm makes the release commit + tag.
 *   npm run sync-version    Write derived files from package.json (no git staging).
 *   npm run check-version   Guard (CI + pre-merge): exit non-zero if anything drifted; no writes.
 *
 * Targets are declared in package.json under "versionSync":
 *   [{ "file": "<repo-relative path>",
 *      "pattern": "<JS regex with exactly ONE () group around the version digits>",
 *      "flags": "m",            // optional regex flags ('g' is stripped — first match only)
 *      "required": true|false } // required:true → missing file/pattern is a hard error
 *   ]
 * Only the captured group is rewritten, so surrounding text (changelog prose,
 * @since tags, table cells) is preserved untouched.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { execFileSync } from 'node:child_process';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8');

const pkg = JSON.parse(read('package.json'));
const version = pkg.version;
const targets = Array.isArray(pkg.versionSync) ? pkg.versionSync : [];

const mode =
	process.argv.includes('--check') ? 'check'
	: (process.argv.includes('--stage') || process.env.npm_lifecycle_event === 'version') ? 'stage'
	: 'write';

const SEMVER = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
if (!SEMVER.test(version)) {
	console.error(`✖ sync-version: package.json version "${version}" is not valid semver`);
	process.exit(1);
}

const errors = [];
const warnings = [];
const changed = [];

for (const t of targets) {
	let text;
	try {
		text = read(t.file);
	} catch {
		(t.required ? errors : warnings).push(`${t.required ? 'required ' : ''}file not found: ${t.file}`);
		continue;
	}
	const re = new RegExp(t.pattern, (t.flags || '').replace(/g/g, ''));
	const m = text.match(re);
	if (!m || m[1] === undefined) {
		(t.required ? errors : warnings).push(`pattern not found in ${t.file}: /${t.pattern}/`);
		continue;
	}
	const found = m[1];
	if (found === version) continue;
	if (mode === 'check') {
		errors.push(`${t.file}: says ${found}, package.json says ${version}`);
		continue;
	}
	writeFileSync(join(ROOT, t.file), text.replace(re, (whole, g1) => whole.replace(g1, version)));
	changed.push(t.file);
}

// package-lock.json root version is owned by `npm version`; we only VERIFY it here.
if (mode === 'check') {
	try {
		const lock = JSON.parse(read('package-lock.json'));
		if (lock.version !== version) errors.push(`package-lock.json root version ${lock.version} != ${version}`);
		const rootEntry = lock.packages && lock.packages[''];
		if (rootEntry && rootEntry.version !== version) {
			errors.push(`package-lock.json packages[""].version ${rootEntry.version} != ${version}`);
		}
	} catch {
		warnings.push('package-lock.json not found or unreadable');
	}
}

for (const w of warnings) console.warn(`⚠ sync-version: ${w}`);

if (errors.length) {
	for (const e of errors) console.error(`✖ sync-version: ${e}`);
	if (mode === 'check') {
		console.error('\nVersion drift detected. Bump with `npm version <patch|minor|major|x.y.z>` — never hand-edit versions.');
	}
	process.exit(1);
}

if (mode === 'check') {
	console.log(`✓ sync-version: all version references agree (${version})`);
} else if (changed.length === 0) {
	console.log(`✓ sync-version: already in sync (${version})`);
} else {
	console.log(`✓ sync-version: set ${version} in ${changed.join(', ')}`);
	if (mode === 'stage') {
		execFileSync('git', ['add', '--', ...changed], { cwd: ROOT, stdio: 'inherit' });
		console.log(`  staged ${changed.length} file(s) for the version commit`);
	}
}
