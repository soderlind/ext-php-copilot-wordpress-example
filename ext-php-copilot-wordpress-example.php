<?php
/**
 * Plugin Name: Ext PHP Copilot WordPress Example
 * Description: Minimal admin-page example that calls GitHub Copilot through ext-php-copilot.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.3
 * Author: Example
 * License: MIT
 * Text Domain: ext-php-copilot-wordpress-example
 */

declare(strict_types=1);

namespace ExtPhpCopilotWpExample;

use Copilot\Client;

if (! defined('ABSPATH')) {
    exit;
}

const MENU_SLUG = 'ext-php-copilot-wordpress-example';
const NONCE_ACTION = 'ext_php_copilot_wp_example_prompt';
const NONCE_NAME = 'ext_php_copilot_wp_example_nonce';

add_action('admin_menu', __NAMESPACE__ . '\\register_admin_page');

/**
 * Register the example page under Tools.
 */
function register_admin_page(): void
{
    add_management_page(
        __('Copilot Example', 'ext-php-copilot-wordpress-example'),
        __('Copilot Example', 'ext-php-copilot-wordpress-example'),
        'manage_options',
        MENU_SLUG,
        __NAMESPACE__ . '\\render_admin_page'
    );
}

/**
 * Render the prompt form and response.
 */
function render_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to use this page.', 'ext-php-copilot-wordpress-example'));
    }

    $prompt = '';
    $result = null;
    $error = null;

    if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        check_admin_referer(NONCE_ACTION, NONCE_NAME);

        $prompt = isset($_POST['ext_php_copilot_prompt'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['ext_php_copilot_prompt']))
            : '';

        if ('' === trim($prompt)) {
            $error = __('Enter a prompt before asking Copilot.', 'ext-php-copilot-wordpress-example');
        } elseif (strlen($prompt) > 4000) {
            $error = __('Prompt is too long. Keep this example under 4,000 characters.', 'ext-php-copilot-wordpress-example');
        } else {
            $result = ask_copilot($prompt);
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
                $result = null;
            }
        }
    }

    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Copilot Example', 'ext-php-copilot-wordpress-example'); ?></h1>

        <?php if (! extension_loaded('ext_php_copilot')) : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html__('The ext_php_copilot PHP extension is not loaded.', 'ext-php-copilot-wordpress-example'); ?></p>
            </div>
        <?php endif; ?>

        <?php if (null !== $error) : ?>
            <div class="notice notice-error">
                <p><?php echo esc_html($error); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(menu_page_url(MENU_SLUG, false)); ?>">
            <?php wp_nonce_field(NONCE_ACTION, NONCE_NAME); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ext-php-copilot-prompt">
                            <?php echo esc_html__('Prompt', 'ext-php-copilot-wordpress-example'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea
                            id="ext-php-copilot-prompt"
                            name="ext_php_copilot_prompt"
                            rows="8"
                            class="large-text"
                            required
                        ><?php echo esc_textarea($prompt); ?></textarea>
                        <p class="description">
                            <?php echo esc_html__('Example: Suggest three improvements for this WordPress site admin workflow.', 'ext-php-copilot-wordpress-example'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Ask Copilot', 'ext-php-copilot-wordpress-example')); ?>
        </form>

        <?php if (is_string($result) && '' !== $result) : ?>
            <h2><?php echo esc_html__('Response', 'ext-php-copilot-wordpress-example'); ?></h2>
            <div class="card" style="max-width: 960px;">
                <pre style="white-space: pre-wrap;"><?php echo esc_html($result); ?></pre>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Ask Copilot through the native ext-php-copilot classes.
 *
 * @return string|\WP_Error
 */
function ask_copilot(string $prompt): string|\WP_Error
{
    if (! extension_loaded('ext_php_copilot') || ! class_exists(Client::class)) {
        return new \WP_Error(
            'missing_extension',
            __('The ext_php_copilot PHP extension is not loaded.', 'ext-php-copilot-wordpress-example')
        );
    }

    $token = get_copilot_token();
    if (null === $token) {
        return new \WP_Error(
            'missing_token',
            __('Set GITHUB_COPILOT_TOKEN in the server environment, or provide one with the ext_php_copilot_wp_example_token filter.', 'ext-php-copilot-wordpress-example')
        );
    }

    try {
        $client = new Client(wp_json_encode([
            'cwd' => ABSPATH,
            'githubToken' => $token,
            'useLoggedInUser' => false,
            'copilotHome' => get_copilot_home(),
            'logLevel' => 'info',
        ], JSON_THROW_ON_ERROR));

        $session = $client->createSession(wp_json_encode([
            'clientName' => 'ext-php-copilot-wordpress-example',
            'model' => 'claude-opus-4.5',
            'permissionPolicy' => 'deny_all',
            'streaming' => false,
            'systemMessage' => [
                'content' => 'You are helping a WordPress administrator. Keep responses concise and practical. Answer questions directly based on your knowledge - do not attempt to use tools or explore files. Provide actionable advice without needing to inspect the user\'s specific setup.',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $event_json = $session->sendAndWaitJson($prompt, wp_json_encode([
                'timeoutSeconds' => 90,
            ], JSON_THROW_ON_ERROR));

            // Handle empty response.
            if ('' === $event_json || null === $event_json) {
                return __('Copilot returned an empty response.', 'ext-php-copilot-wordpress-example');
            }

            $event = json_decode($event_json, true, 512);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Return as-is if it looks like readable text.
                if (preg_match('/^[\x20-\x7E\n\r\t]+$/', $event_json) && strlen($event_json) < 10000) {
                    return $event_json;
                }
                return __('Copilot returned an unreadable response.', 'ext-php-copilot-wordpress-example');
            }

            $text = extract_response_text($event);

            // If extraction returned empty, provide user-friendly error.
            if ('' === $text) {
                return __('Copilot returned a response but no text content was found.', 'ext-php-copilot-wordpress-example');
            }

            return $text;
        } finally {
            $session->disconnect();
            $client->stop();
        }
    } catch (\Throwable $throwable) {
        return new \WP_Error('copilot_request_failed', $throwable->getMessage());
    }
}

/**
 * Read the Copilot token from the environment, then allow deployment-specific overrides.
 */
function get_copilot_token(): ?string
{
    $token = getenv('GITHUB_COPILOT_TOKEN');
    $token = is_string($token) && '' !== trim($token) ? trim($token) : null;

    /**
     * Filters the GitHub Copilot token used by the example plugin.
     *
     * Keep this value out of source control and WordPress options.
     *
     * @param string|null $token Token from GITHUB_COPILOT_TOKEN, or null.
     */
    $filtered = apply_filters('ext_php_copilot_wp_example_token', $token);

    return is_string($filtered) && '' !== trim($filtered) ? trim($filtered) : null;
}

/**
 * Return a writable Copilot CLI home directory with basic web-server hardening files.
 */
function get_copilot_home(): string
{
    $upload_dir = wp_upload_dir(null, false);
    $base_dir = trailingslashit((string) $upload_dir['basedir']) . 'ext-php-copilot-example';

    if (! is_dir($base_dir)) {
        wp_mkdir_p($base_dir);
    }

    $index = trailingslashit($base_dir) . 'index.php';
    if (! file_exists($index)) {
        file_put_contents($index, "<?php\n// Silence is golden.\n");
    }

    $htaccess = trailingslashit($base_dir) . '.htaccess';
    if (! file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }

    return $base_dir;
}

/**
 * Pull useful text from the event shape returned by sendAndWaitJson().
 *
 * The SDK returns events shaped like: { "type": "...", "data": { ... } }
 * For assistant.message: data.content contains the text, or data.reasoningText as fallback.
 *
 * @param mixed $event
 */
function extract_response_text(mixed $event): string
{
    if (!is_array($event)) {
        return is_string($event) ? $event : '';
    }

    // SDK event structure: { "type": "...", "data": { ... } }
    $type = $event['type'] ?? null;
    $data = $event['data'] ?? null;

    // Handle assistant.message type (non-streaming complete response).
    if ($type === 'assistant.message' && is_array($data)) {
        // Primary: content field.
        $content = $data['content'] ?? '';
        if (is_string($content) && '' !== trim($content)) {
            return $content;
        }

        // Fallback: reasoningText (when content is empty, e.g., tools blocked).
        $reasoning = $data['reasoningText'] ?? '';
        if (is_string($reasoning) && '' !== trim($reasoning)) {
            // If there are blocked tool requests, note that in the response.
            $tool_requests = $data['toolRequests'] ?? [];
            if (is_array($tool_requests) && count($tool_requests) > 0) {
                return $reasoning . "\n\n" . sprintf(
                    /* translators: %d: number of tool requests */
                    _n(
                        '(Note: Copilot requested %d tool which was blocked by permission policy.)',
                        '(Note: Copilot requested %d tools which were blocked by permission policy.)',
                        count($tool_requests),
                        'ext-php-copilot-wordpress-example'
                    ),
                    count($tool_requests)
                );
            }
            return $reasoning;
        }
    }

    // Handle known streaming event types.
    if (is_string($type)) {
        // Streaming delta events.
        if ($type === 'assistant.message_delta' && is_array($data)) {
            $delta = $data['delta'] ?? null;
            if (is_string($delta)) {
                return $delta;
            }
        }

        // Turn complete, message complete, or response events.
        if (in_array($type, ['assistant.turn_complete', 'turn_complete', 'message_complete', 'response'], true)) {
            if (is_array($data)) {
                foreach (['text', 'content', 'message', 'response', 'delta', 'reasoningText'] as $key) {
                    if (isset($data[$key]) && is_string($data[$key]) && '' !== trim($data[$key])) {
                        return $data[$key];
                    }
                }
            }
        }
    }

    // Generic data extraction - check data object first.
    if (is_array($data)) {
        foreach (['content', 'text', 'message', 'response', 'delta', 'reasoningText'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && '' !== trim($data[$key])) {
                return $data[$key];
            }
        }
    }

    // Check nested structures common in LLM API responses.
    $paths_to_check = [
        ['choices', 0, 'message', 'content'],
        ['choices', 0, 'text'],
        ['choices', 0, 'delta', 'content'],
        ['data', 'content'],
        ['data', 'text'],
        ['data', 'reasoningText'],
        ['result', 'text'],
        ['result', 'content'],
    ];

    foreach ($paths_to_check as $path) {
        $value = $event;
        foreach ($path as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                $value = null;
                break;
            }
        }
        if (is_string($value) && '' !== trim($value)) {
            return $value;
        }
    }

    // Try direct keys on the event itself.
    $direct_keys = ['text', 'message', 'content', 'response'];
    foreach ($direct_keys as $key) {
        if (isset($event[$key]) && is_string($event[$key]) && '' !== trim($event[$key])) {
            return $event[$key];
        }
    }

    // Find longest readable string, skipping base64/encrypted blobs.
    $nested = find_longest_string($event, skip_encoded: true);
    if (null !== $nested) {
        return $nested;
    }

    return '';
}

/**
 * Find the longest string in a nested event payload.
 *
 * @param mixed $value
 * @param bool  $skip_encoded Skip strings that look like base64/encrypted blobs.
 */
function find_longest_string(mixed $value, bool $skip_encoded = false): ?string
{
    if (is_string($value)) {
        // Skip base64/encrypted-looking strings (no spaces, mostly alphanumeric + /+=).
        if ($skip_encoded && strlen($value) > 100 && ! preg_match('/\s/', $value)) {
            return null;
        }
        return $value;
    }

    if (! is_array($value)) {
        return null;
    }

    $best = null;
    foreach ($value as $child) {
        $candidate = find_longest_string($child, $skip_encoded);
        if (is_string($candidate) && (null === $best || strlen($candidate) > strlen($best))) {
            $best = $candidate;
        }
    }

    return $best;
}
