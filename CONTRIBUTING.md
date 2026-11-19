# Contributing

Open an issue before a large change. Keep school-specific names, domains, branding and policy out of source. New integrations must implement `NetworkProvider`, expose capabilities honestly, start read-only and include failure/reversal tests.

Before a pull request:

```bash
vendor/bin/pint --test
php artisan test
```

Never commit `.env`, database dumps, real school screenshots, identifying API responses or secrets. Use focused commits and update `CHANGELOG.md` for user-visible changes. Contributions are licensed under MIT.
