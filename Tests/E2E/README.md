# NEOSidekick.AiAssistant E2E (Playwright)

Runs against DDEV Neos (`/neos`) using the real NEOSidekick API with the NEOSidekickTestWebsite.

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

## Neos 9 specs

`neos9.editorsAndChat.spec.ts` and `neos9.bulkAltTextModule.spec.ts` are independent of the
NEOSidekickTestWebsite: they run against any Neos 9 site with this plugin installed (the
Neos.Demo distribution qualifies without extra content). Requirements and warnings are
documented in each spec's docblock — notably an English backend interface language, a valid
API key, `--workers=1` (auth.setup must complete first), and for the bulk module spec a
publicly reachable base URL because the NEOSidekick API fetches asset images by URL.

They are WRITE tests: the chat spec authorizes the NEOSidekick agent on the instance, the
bulk module spec persists generated descriptions into asset titles. Do not point them at
an instance whose content you care about.
