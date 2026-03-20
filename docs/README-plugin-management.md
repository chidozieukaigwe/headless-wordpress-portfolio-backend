# Plugin Management (recommended workflow)

This project uses Composer/WPackagist to manage third-party WordPress plugins. Third-party plugin code should not be committed to this repository; instead, use the workflow below to keep the repo small and reproducible.

Quick steps to clean up the repo after installing plugins via the admin UI:

- Run the helper script to untrack plugin files and update `.gitignore`:

```bash
chmod +x scripts/untrack-plugins.sh
./scripts/untrack-plugins.sh
```

- Add plugins to `composer.json` using the `wpackagist` package name, for example:

```json
// composer.json (excerpt)
"require": {
  "wpackagist-plugin/contact-form-7": "^5.8"
}
```

- Run `composer install` on your development and deployment environments to install the plugins.

- Optionally maintain `plugin-lock.json` as a simple manifest of plugin slugs and versions; you can generate this with WP-CLI: `wp plugin list --format=json > plugin-lock.json`.

Notes:

- Keep custom plugins (those you author) tracked in `wp-content/plugins/` by whitelisting them in `.gitignore` (see the file for an example).
- Automate plugin installs in CI/CD using `composer install` or `wp plugin install` steps.
