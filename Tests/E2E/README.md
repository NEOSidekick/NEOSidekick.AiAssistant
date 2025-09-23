# NEOSidekick.AiAssistant E2E (Playwright)

Runs against DDEV Neos (`/neos`) using the real NEOSidekick API.

## Install

From this folder:
```bash
npm install
npm run test:install
```

## Setup

- Ensure Neos is reachable at `https://neosidekicktestwebsite.ddev.site/`.
- Export env vars:
```bash
export PLAYWRIGHT_BASE_URL=https://neosidekicktestwebsite.ddev.site
export NEOS_BACKEND_USERNAME=admin
export NEOS_BACKEND_PASSWORD=admin
```
- Ensure a valid NEOSidekick API key in Neos settings.

## Run tests

```bash
npm test tests/auth.setup.spec.ts
npm test tests/focusKeyword.editor.spec.ts
npx playwright test tests/imageEditors.spec.ts
```
