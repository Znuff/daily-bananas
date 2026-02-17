# Daily Bananas

WordPress plugin that auto-generates featured images for blog posts using Google's Gemini API (image generation with Google Search grounding).

## Architecture

```
daily-bananas.php                      Bootstrap: requires classes, calls init()
includes/
  class-logger.php                     Debug logging to wp-content/uploads/daily-bananas-debug.log
  class-settings.php                   WP Settings API page at Settings > Daily Bananas
  class-link-extractor.php             DOMDocument-based URL extraction from post HTML
  class-image-generator.php            Gemini REST API call + WP media library upload
  class-cron-handler.php               transition_post_status hook + WP-Cron async callback
```

Load order matters: logger must be loaded before settings (settings references logger in render). All classes use static methods, no instantiation.

## How It Works

1. `transition_post_status` hook fires on publish
2. `Cron_Handler::on_post_published()` checks guards: new_status=publish, old_status!=publish, correct category, API key present
3. Schedules `daily_bananas_generate` WP-Cron event + calls `spawn_cron()`
4. Cron runs `Cron_Handler::run()` in a separate PHP process (non-blocking)
5. `Image_Generator::generate_image()` extracts links, builds prompt, calls Gemini API (120s timeout), uploads result to media library, sets as featured image

## Key Conventions

- **Class naming**: `Daily_Bananas_*` prefix, one class per file
- **Options**: All stored with `daily_bananas_` prefix in `wp_options`. Access via `Daily_Bananas_Settings::get('key')`
- **Post meta**: `_daily_bananas_status` (processing/generated/failed), `_daily_bananas_attachment_id`, `_daily_bananas_generated_at`
- **Cron hook name**: `daily_bananas_generate`
- **Coding style**: WordPress PHP coding standards (tabs for indentation, Yoda conditions optional, `wp_` prefixed functions preferred)

## Gemini API

- Endpoint: `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`
- Auth: `x-goog-api-key` header
- `googleSearch` tool value must be `new \stdClass()` not `[]` (PHP would encode `[]` as JSON array, API expects object `{}`)
- Response images are base64-encoded in `candidates[0].content.parts[].inlineData.data`
- `responseModalities` must include both `TEXT` and `IMAGE`

## Settings (wp_options keys)

| Key | Default | Notes |
|-----|---------|-------|
| `daily_bananas_api_key` | `''` | Gemini API key |
| `daily_bananas_model` | `gemini-3-pro-image-preview` | Model ID |
| `daily_bananas_category` | `stirile-zilei` | WP category slug (dropdown in UI) |
| `daily_bananas_aspect_ratio` | `16:9` | Image aspect ratio |
| `daily_bananas_prompt` | *(long string)* | `{urls}` placeholder for links |
| `daily_bananas_ignored_domains` | `''` | Newline-separated, subdomain-aware |
| `daily_bananas_max_urls` | `3` | Max URLs injected into prompt |
| `daily_bananas_randomize_urls` | `0` | Shuffle URLs before selecting first N |
| `daily_bananas_filename` | `stirile_zilei_{date}` | `{date}`, `{post_id}`, `{timestamp}` placeholders |
| `daily_bananas_debug` | `1` | Enables debug log file |

## Adding a New Setting

1. Add default to `DEFAULTS` array in `class-settings.php`
2. Register field in `register_settings()` with `self::add_field()`
3. Add `render_{key}_field()` static method
4. Add `sanitize_{key}()` static method
5. The `add_field()` helper auto-wires `register_setting()` + `add_settings_field()` using the key

## Debugging

- Enable debug checkbox in Settings > Daily Bananas
- Log file: `wp-content/uploads/daily-bananas-debug.log`
- Log viewer + clear button at bottom of settings page
- Every guard condition in `on_post_published()` logs why it skipped
- API calls log request URL, body, response status, timing, and part structure
- To re-trigger generation for a post: delete its `_daily_bananas_status` post meta

## Common Issues

- **Nothing happens on publish**: Check debug log. Most likely: wrong category selected, post was already published (edit = publish->publish transition, skipped), or no API key
- **Cron never fires**: WP-Cron requires working loopback HTTP. Check if `DISABLE_WP_CRON` is set. Some hosts block loopback requests
- **API timeout**: Gemini image generation can take 10-60s. Plugin uses 120s timeout. Host `max_execution_time` must be >= 120
- **Duplicate execution guard**: Cron checks for both `processing` and `generated` status to prevent double runs. If status is stuck on `processing`, delete the `_daily_bananas_status` meta to allow retry
