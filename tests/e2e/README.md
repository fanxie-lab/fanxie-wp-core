# Playwright end-to-end smoke tests

Before running `npm run test:e2e`, boot the local WordPress environment and build the admin SPA so the Vue app is served statically (rather than via Vite's dev server):

```bash
npm run env:start                  # start wp-env (dev env on :8888)
cd assets/admin && npm run build   # produce assets/admin/dist/ so SettingsPage enqueues real files
cd ../..
npm run test:e2e:install           # one-time: install chromium + OS deps
npm run test:e2e                   # run the smoke suite
```

The Playwright runner connects to the wp-env *development* environment at `http://localhost:8888` with the default admin credentials (`admin` / `password`). The `tests` env on 8889 is reserved for PHPUnit integration runs.
