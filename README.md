# Ext PHP Copilot WordPress Example

Minimal WordPress admin plugin that calls GitHub Copilot through
[`soderlind/ext-php-copilot`](https://github.com/soderlind/ext-php-copilot).

It adds:

- `Tools -> Copilot Example`
- an admin-only prompt form
- a direct `Copilot\Client` / `Copilot\Session` call
- `deny_all` session permissions
- no token storage in WordPress options

## Requirements

- WordPress with PHP 8.3+
- the `ext_php_copilot` PHP extension loaded
- a GitHub token with Copilot entitlement in `GITHUB_COPILOT_TOKEN`

## Install

Copy the `ext-php-copilot-wordpress-example` folder into:

```text
wp-content/plugins/
```

Then activate **Ext PHP Copilot WordPress Example** in `Plugins`.

## Token Configuration

Preferred: set the token in the PHP/web-server environment:

```sh
GITHUB_COPILOT_TOKEN=github_pat_or_token_here
```

For local development only, you can provide it from `wp-config.php` without
storing it in the database:

```php
add_filter('ext_php_copilot_wp_example_token', static function (?string $token): ?string {
    return $token ?: getenv('GITHUB_COPILOT_TOKEN') ?: null;
});
```

Do not hard-code real tokens in plugin files or commit them to source control.

## How It Works

The plugin creates a native client:

```php
$client = new Copilot\Client(wp_json_encode([
    'cwd' => ABSPATH,
    'githubToken' => $token,
    'useLoggedInUser' => false,
    'copilotHome' => $writable_home,
]));
```

Then it creates a session and sends the admin prompt:

```php
$session = $client->createSession(wp_json_encode([
    'permissionPolicy' => 'deny_all',
    'streaming' => false,
]));

$event_json = $session->sendAndWaitJson($prompt, wp_json_encode([
    'timeoutSeconds' => 90,
]));
```

## Notes

This is intentionally an example, not production product code. Before using this
pattern in a public plugin, add rate limiting, audit logging, clearer privacy
copy, and deployment-specific secret management.
