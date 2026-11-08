# Contributing

Thank you for helping. A few rules keep the plugin safe and small.

1. Open an issue before large changes.
2. Follow the [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/); `composer cs` checks them.
3. Escape on output, sanitise on input, check a capability and a nonce on every entry point.
4. Use `$wpdb->prepare()` for every query with a variable.
5. Add or update unit tests for logic you change (`tests/Unit`).
6. Do not add outside requests, tracking, fonts or scripts loaded from a CDN.
7. Keep user-facing text in the `estasoft-handover-passport` text domain.

Commit messages: a short summary line in the imperative, then a body that says why.
