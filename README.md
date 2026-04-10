# CF-Summarize

A lightweight WordPress plugin that adds an AI-powered **Article Overview** button to your posts. On click, it fetches a structured summary - key points and a conclusion - from OpenAI or Anthropic and displays it in a smooth inline accordion panel.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php) ![License](https://img.shields.io/badge/License-GPL%202.0%2B-green)

---

## Features

- **Dual AI provider support** - OpenAI (GPT-4o, GPT-4o-mini, etc.) and Anthropic (Claude 3.5 Haiku, Claude 3 Opus, etc.)
- **Lazy-loaded summaries** - fetched on first button click, cached for repeat visits
- **Structured output** - returns key points as a bullet list and a conclusion paragraph
- **Transient caching** - configurable cache duration (default: 24 hours) with one-click cache clearing
- **Rate limiting** - 10 requests per 60 seconds per IP (HTTP 429 on breach)
- **Per-post control** - override global enable/disable setting per post via a meta box
- **Zero dependencies** - vanilla JS, no jQuery, no build step required
- **Accessible** - ARIA roles, `aria-expanded`, `aria-live`, keyboard (Escape) support
- **Dark mode aware** - CSS `prefers-color-scheme` support
- **Translation ready** - all strings use the `cf-summarize` text domain

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0 or later |
| PHP | 7.4 or later |
| API Key | OpenAI **or** Anthropic (at least one) |

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/your-username/CF-Summarize.git
   ```
2. Activate the plugin from **Plugins → Installed Plugins** in your WordPress admin.
3. Go to **Settings → CF-Summarize** and enter your API key.

---

## Configuration

Navigate to **Settings → CF-Summarize** to configure the plugin.

### API Configuration

| Setting | Description |
|---|---|
| AI Provider | Choose between OpenAI or Anthropic |
| OpenAI API Key | Your key from [platform.openai.com](https://platform.openai.com) |
| OpenAI Model | e.g. `gpt-4o-mini`, `gpt-4o` |
| Anthropic API Key | Your key from [console.anthropic.com](https://console.anthropic.com) |
| Anthropic Model | e.g. `claude-3-5-haiku-20241022`, `claude-3-opus-20240229` |

### Display Settings

| Setting | Description |
|---|---|
| Enable on All Posts | Global toggle for the Article Overview button |
| Button Label | Customize the button text (default: `Article Overview`) |
| Button Position | Inject button `before` or `after` post content |

### Performance

| Setting | Description |
|---|---|
| Enable Caching | Cache AI responses using WordPress transients |
| Cache Duration | Seconds to cache each summary (default: `86400` = 24 hours) |

---

## Per-Post Control

Each post editor includes a **CF-Summarize** meta box (in the sidebar) to override the global enable setting:

- **Default** - inherits the global setting
- **Enable** - force-enable on this post
- **Disable** - force-disable on this post

---

## REST API

The plugin registers a single REST endpoint used by the frontend JS:

```
POST /wp-json/cf-sum/v1/summarize
```

**Request body:**

```json
{
  "post_id": 123,
  "nonce": "<wp_nonce>"
}
```

**Success response:**

```json
{
  "key_points": ["Point one", "Point two", "..."],
  "conclusion": "A brief conclusion sentence.",
  "cached": false
}
```

**Error response:**

```json
{
  "message": "Human-readable error description."
}
```

---

## File Structure

```
CF-Summarize/
├── assets/
│   ├── admin.css              # Admin settings page styles
│   ├── cf-summarize.css       # Frontend button & panel styles
│   └── cf-summarize.js        # Frontend accordion & fetch logic
├── includes/
│   ├── class-cfs-ai-client.php          # OpenAI / Anthropic API abstraction
│   ├── class-cfs-content-extractor.php  # Post content cleaning & truncation
│   ├── class-cfs-meta-box.php           # Per-post override meta box
│   ├── class-cfs-rest-api.php           # REST endpoint, rate limiting, caching
│   └── class-cfs-settings.php           # Admin settings page & asset enqueuing
├── cf-summarize.php           # Plugin bootstrap & activation hook
└── readme.txt                 # WordPress.org readme
```

---

## Extensibility

### Filter: `cfs_providers`

Add or modify available AI providers:

```php
add_filter( 'cfs_providers', function( array $providers ): array {
    $providers['my_provider'] = 'My Custom Provider';
    return $providers;
} );
```

---

## Security

- Nonce verification on all REST requests and meta box saves
- `manage_options` capability check for settings; `edit_post` for meta box
- All input sanitized (`sanitize_text_field`, `absint`)
- All output escaped (`esc_html`, `esc_attr`, `esc_js`)
- API keys stored server-side only - never exposed to the browser
- Rate limiting per IP address

---

## Changelog

### 1.0.0
- Initial release
- OpenAI and Anthropic provider support
- Lazy-loaded inline accordion panel
- Transient caching with configurable duration
- Rate limiting (10 req / 60 s per IP)
- Per-post enable/disable meta box
- Modern admin settings UI

---

## License

[GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html)
