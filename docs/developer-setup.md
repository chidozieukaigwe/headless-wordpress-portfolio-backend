**Developer Setup**

This document describes how to prepare a local development environment for this repository. It assumes WordPress core is managed by Composer (johnpbloch/wordpress) and that `wp-config.php` in the repository is the canonical, environment-driven bootstrap file.

- **Keep `wp-config.php` in VCS**: The repository contains a `wp-config.php` that reads environment variables from a `.env` file and provides the project bootstrap logic required by all developers. Do NOT delete this file.

- **Do NOT commit secrets**: Use `.env` (gitignored) for secrets. A sample file is provided as `.env.example`.

Steps for a new developer

1. Clone the repository:

   git clone <repo-url>
   cd <repo-folder>

2. Copy the example env and fill in values:

   cp .env.example .env

   # Edit .env with your DB credentials, salts, and URLs

3. Install dependencies (Composer will install WordPress core and plugins):

   composer install

4. If Composer's installer created or replaced `wp-config.php` (rare), restore the repository's canonical `wp-config.php`:

   # Restore the committed wp-config.php from git

   git checkout -- wp-config.php

   # Or copy from a committed sample if you keep one

   # cp wp-config.php.sample wp-config.php

   Note: `git checkout -- wp-config.php` restores the version tracked in the repo. This ensures the environment-driven bootstrap is used instead of any installer-provided file.

5. Create the local database and run migrations/imports as documented by the project (if any).

6. Optional: If you prefer a PHP config file instead of `.env`, create `wp-config-local.php` (gitignored) and update values there. `wp-config.php` will include/allow local overrides if present.

Handling the potential wp-config overwrite when Composer installs WordPress core

The johnpbloch installer may place WordPress core files into the project root. In normal cases this does not remove or overwrite `wp-config.php` if it already exists. However, to be safe, follow this pattern:

- Before running `composer install`, ensure `wp-config.php` exists in the working tree (it should if cloned from this repo).
- Run `composer install`.
- If you notice `wp-config.php` was changed/overwritten, run `git checkout -- wp-config.php` to restore the repository version.

Automating safety (recommended)

- Add `wp-config.php` to `.gitattributes` to mark merge behavior if desired. Example: `wp-config.php merge=ours` to prefer the checked-in file during merges (use with caution).
- Add a small `scripts/post-install-cmd` Composer hook to restore `wp-config.php` automatically after `composer install`:

  {
  "scripts": {
  "post-install-cmd": [
  "git checkout -- wp-config.php || true"
  ]
  }
  }

  The above will attempt to restore the tracked `wp-config.php` after install (harmless if already correct).

Documentation and troubleshooting

- If WordPress fails to bootstrap, ensure `ABSPATH` and `WP_CONTENT_DIR` values in `wp-config.php` are correct for the project root.
- Ensure `.env` values are set (database, salts, URLs). Use `php -r 'var_dump(getenv("DB_NAME"));'` to verify environment loading.

Questions or changes

If you want me to add an automated Composer `post-install-cmd`, update `.gitattributes`, or create a `wp-config.php.sample` alternative, tell me which and I will implement it.
