# Vivistays Leads

Lead tracker for Vivistays: Facebook owner and guest leads, project contractors, LinkedIn contacts and event organisers.

## How deploys work
Push to `main` → GitHub Actions uploads the changed files to cPanel over FTPS. Nothing else to do.

Never deployed (stays on the server only):
- `config.local.php` – passwords and the Anthropic API key
- `data/leads.sqlite` – all saved leads and contacts

## Adding records from outside the app
Put them in `data/seed.json` (same shape as now) and push. On the next page load the server adds any records it doesn't already have and never overwrites existing ones.

## Files
- `app.html` – the app (same page as the claude.ai version)
- `bridge.js` – connects the page to this server's storage and AI
- `api.php` – data and AI endpoint (signed-in users only)
- `index.php` – sign-in screen
- `lib.php` – shared helpers and database
