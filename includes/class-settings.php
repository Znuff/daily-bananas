<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Daily_Bananas_Settings {

	const OPTION_PREFIX = 'daily_bananas_';

	const DEFAULTS = [
		'api_key'         => '',
		'model'           => 'gemini-3-pro-image-preview',
		'category'        => 'stirile-zilei',
		'aspect_ratio'    => '16:9',
		'prompt'          => "Make a stylish image that is to be used for a blog post. The blog post is part of a category called 'ȘTIRILE ZILEI' (today's news). A good starting point would be a newspaper on a desk, with today's date and a headline of 'ȘTIRILE ZILEI'. Grab search result images for these urls: {urls} --",
		'ignored_domains' => '',
		'max_urls'        => '3',
		'randomize_urls'  => '0',
		'filename'        => 'stirile_zilei_{date}',
		'debug'           => '1',
	];

	const ASPECT_RATIOS = [
		'1:1', '2:3', '3:2', '3:4', '4:3',
		'4:5', '5:4', '9:16', '16:9', '21:9',
	];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	public static function get( string $key ): string {
		$option = get_option( self::OPTION_PREFIX . $key, self::DEFAULTS[ $key ] ?? '' );
		return $option !== false ? $option : ( self::DEFAULTS[ $key ] ?? '' );
	}

	public static function add_menu_page() {
		add_options_page(
			'Daily Bananas',
			'Daily Bananas',
			'manage_options',
			'daily-bananas',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		// API Configuration section
		add_settings_section(
			'daily_bananas_api',
			'API Configuration',
			null,
			'daily-bananas'
		);

		self::add_field( 'api_key', 'API Key', 'render_api_key_field', 'daily_bananas_api' );
		self::add_field( 'model', 'Model', 'render_model_field', 'daily_bananas_api' );

		// Content Settings section
		add_settings_section(
			'daily_bananas_content',
			'Content Settings',
			null,
			'daily-bananas'
		);

		self::add_field( 'category', 'Category Slug', 'render_category_field', 'daily_bananas_content' );
		self::add_field( 'aspect_ratio', 'Aspect Ratio', 'render_aspect_ratio_field', 'daily_bananas_content' );

		// Prompt Configuration section
		add_settings_section(
			'daily_bananas_prompt',
			'Prompt Configuration',
			null,
			'daily-bananas'
		);

		self::add_field( 'prompt', 'Prompt Template', 'render_prompt_field', 'daily_bananas_prompt' );
		self::add_field( 'ignored_domains', 'Ignored Domains', 'render_ignored_domains_field', 'daily_bananas_prompt' );
		self::add_field( 'max_urls', 'Max URLs in Prompt', 'render_max_urls_field', 'daily_bananas_prompt' );
		self::add_field( 'randomize_urls', 'Randomize URLs', 'render_randomize_urls_field', 'daily_bananas_prompt' );
		self::add_field( 'filename', 'Image Filename', 'render_filename_field', 'daily_bananas_content' );

		// Debug section
		add_settings_section(
			'daily_bananas_debug',
			'Debug',
			null,
			'daily-bananas'
		);

		self::add_field( 'debug', 'Enable Debug Logging', 'render_debug_field', 'daily_bananas_debug' );
	}

	private static function add_field( string $key, string $title, string $callback, string $section ) {
		$option_name = self::OPTION_PREFIX . $key;

		register_setting( 'daily_bananas_options', $option_name, [
			'type'              => 'string',
			'default'           => self::DEFAULTS[ $key ],
			'sanitize_callback' => [ __CLASS__, 'sanitize_' . $key ],
		] );

		add_settings_field(
			$option_name,
			$title,
			[ __CLASS__, $callback ],
			'daily-bananas',
			$section
		);
	}

	// --- Render callbacks ---

	public static function render_api_key_field() {
		$value = self::get( 'api_key' );
		printf(
			'<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off" />
			<p class="description">Get your API key from <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>.</p>',
			esc_attr( self::OPTION_PREFIX . 'api_key' ),
			esc_attr( $value )
		);
	}

	public static function render_model_field() {
		$value = self::get( 'model' );
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" />
			<p class="description">Gemini model ID. Check Google AI Studio for available image generation models.</p>',
			esc_attr( self::OPTION_PREFIX . 'model' ),
			esc_attr( $value )
		);
	}

	public static function render_category_field() {
		$current    = self::get( 'category' );
		$name       = esc_attr( self::OPTION_PREFIX . 'category' );
		$categories = get_categories( [
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		echo '<select name="' . $name . '">';
		echo '<option value="">-- Select a category --</option>';
		foreach ( $categories as $cat ) {
			printf(
				'<option value="%s" %s>%s (slug: %s)</option>',
				esc_attr( $cat->slug ),
				selected( $current, $cat->slug, false ),
				esc_html( $cat->name ),
				esc_html( $cat->slug )
			);
		}
		echo '</select>';
		echo '<p class="description">Posts published in this category will get auto-generated featured images.</p>';
	}

	public static function render_aspect_ratio_field() {
		$current = self::get( 'aspect_ratio' );
		$name    = esc_attr( self::OPTION_PREFIX . 'aspect_ratio' );

		echo '<select name="' . $name . '">';
		foreach ( self::ASPECT_RATIOS as $ratio ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $ratio ),
				selected( $current, $ratio, false ),
				esc_html( $ratio )
			);
		}
		echo '</select>';
	}

	public static function render_prompt_field() {
		$value = self::get( 'prompt' );
		printf(
			'<textarea name="%s" rows="8" class="large-text code">%s</textarea>
			<p class="description">Use <code>{urls}</code> as a placeholder for extracted post links.</p>',
			esc_attr( self::OPTION_PREFIX . 'prompt' ),
			esc_textarea( $value )
		);
	}

	public static function render_ignored_domains_field() {
		$value = self::get( 'ignored_domains' );
		printf(
			'<textarea name="%s" rows="6" class="large-text code">%s</textarea>
			<p class="description">One domain per line. Subdomains are automatically excluded.<br>Example: <code>facebook.com</code> (will also exclude www.facebook.com, m.facebook.com)</p>',
			esc_attr( self::OPTION_PREFIX . 'ignored_domains' ),
			esc_textarea( $value )
		);
	}

	public static function render_filename_field() {
		$value = self::get( 'filename' );
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" />
			<p class="description">Filename template (without extension). Available placeholders:<br>
			<code>{date}</code> = current date (Y-m-d)<br>
			<code>{post_id}</code> = post ID<br>
			<code>{timestamp}</code> = Unix timestamp</p>',
			esc_attr( self::OPTION_PREFIX . 'filename' ),
			esc_attr( $value )
		);
	}

	public static function render_max_urls_field() {
		$value = self::get( 'max_urls' );
		printf(
			'<input type="number" name="%s" value="%s" min="0" max="20" step="1" class="small-text" />
			<p class="description">Maximum number of URLs from the post to include in the prompt. If the post has fewer links, all available links will be used.</p>',
			esc_attr( self::OPTION_PREFIX . 'max_urls' ),
			esc_attr( $value )
		);
	}

	public static function render_randomize_urls_field() {
		$value = self::get( 'randomize_urls' );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> Shuffle extracted URLs before selecting the first N for the prompt</label>',
			esc_attr( self::OPTION_PREFIX . 'randomize_urls' ),
			checked( $value, '1', false )
		);
	}

	public static function render_debug_field() {
		$value = self::get( 'debug' );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> Write detailed logs to <code>%s</code></label>',
			esc_attr( self::OPTION_PREFIX . 'debug' ),
			checked( $value, '1', false ),
			esc_html( Daily_Bananas_Logger::get_log_file() )
		);
	}

	// --- Sanitize callbacks ---

	public static function sanitize_api_key( $value ) {
		return trim( $value );
	}

	public static function sanitize_model( $value ) {
		return sanitize_text_field( $value );
	}

	public static function sanitize_category( $value ) {
		$value = sanitize_text_field( $value );
		// Validate it's an actual category slug
		if ( ! empty( $value ) && ! term_exists( $value, 'category' ) ) {
			add_settings_error( 'daily_bananas_category', 'invalid_category', "Category '{$value}' not found." );
			return self::get( 'category' ); // keep the old value
		}
		return $value;
	}

	public static function sanitize_aspect_ratio( $value ) {
		return in_array( $value, self::ASPECT_RATIOS, true ) ? $value : '16:9';
	}

	public static function sanitize_prompt( $value ) {
		return wp_kses_post( $value );
	}

	public static function sanitize_filename( $value ) {
		return sanitize_text_field( $value );
	}

	public static function sanitize_max_urls( $value ) {
		$value = (int) $value;
		return (string) max( 0, min( 20, $value ) );
	}

	public static function sanitize_randomize_urls( $value ) {
		return $value === '1' ? '1' : '0';
	}

	public static function sanitize_debug( $value ) {
		return $value === '1' ? '1' : '0';
	}

	public static function sanitize_ignored_domains( $value ) {
		$lines = explode( "\n", $value );
		$clean = array_map( function ( $line ) {
			$line = trim( $line );
			$line = preg_replace( '#^https?://#i', '', $line );
			$line = rtrim( $line, '/' );
			return sanitize_text_field( $line );
		}, $lines );
		return implode( "\n", array_filter( $clean ) );
	}

	// --- Page render ---

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle clear log action
		if ( isset( $_POST['daily_bananas_clear_log'] ) && check_admin_referer( 'daily_bananas_clear_log' ) ) {
			Daily_Bananas_Logger::clear();
			echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
		}

		?>
		<div class="wrap">
			<h1>Daily Bananas</h1>
			<p>Auto-generate featured images for posts using Google Gemini API.</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'daily_bananas_options' );
				do_settings_sections( 'daily-bananas' );
				submit_button();
				?>
			</form>

			<hr>
			<h2>Debug Log</h2>
			<p>Log file: <code><?php echo esc_html( Daily_Bananas_Logger::get_log_file() ); ?></code></p>
			<textarea readonly rows="20" class="large-text code" style="font-size: 12px; line-height: 1.4;"><?php echo esc_textarea( Daily_Bananas_Logger::get_recent_lines( 200 ) ); ?></textarea>
			<form method="post" style="margin-top: 8px;">
				<?php wp_nonce_field( 'daily_bananas_clear_log' ); ?>
				<button type="submit" name="daily_bananas_clear_log" value="1" class="button">Clear Log</button>
			</form>
		</div>
		<?php
	}
}
