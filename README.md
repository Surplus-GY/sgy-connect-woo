# Surplus GY Connect (WooCommerce)

The WooCommerce plugin half of Surplus GY Store Connect: import your WooCommerce catalogue into Surplus GY
and keep price, stock and visibility in sync, with the outstanding-Surplus-fields checklist shown right in
your product editor.

- Plugin source: [`sgy-connect/`](sgy-connect/)
- Vendor install guide: [`sgy-connect/readme.txt`](sgy-connect/readme.txt)
- Architecture + decisions: `STORE_CONNECT_BLUEPRINT.md` in the main project
- One build serves both environments: the API key prefix (`sgy_test_` / `sgy_live_`) selects staging vs prod.

Releases are built by tagging `vX.Y.Z` (see `.github/workflows/build-release.yml`); the plugin's built-in
updater offers the new version in every connected store's WordPress admin.
