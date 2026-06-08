<?php
/**
 * Admin settings page for CF-Summarize.
 *
 * @package CF_Summarize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CFS_Settings
 *
 * Registers and renders the plugin settings page using the WordPress Settings API.
 */
class CFS_Settings {

	/**
	 * Constructor - hook into WordPress admin.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_cache_clear' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Return the list of supported AI providers.
	 *
	 * Filterable via `cfs_providers` so third-party code can add providers
	 * without touching this file.
	 *
	 * @return array<string,string> provider_slug => display_label
	 */
	private function get_providers(): array {
		return (array) apply_filters(
			'cfs_providers',
			[
				'openai'    => __( 'OpenAI', 'cf-summarize' ),
				'anthropic' => __( 'Anthropic (Claude)', 'cf-summarize' ),
			]
		);
	}

	/**
	 * Return the list of user-customizable panel color options.
	 *
	 * option_key => [ 'label' => string, 'default' => hex, 'hint' => string ]
	 *
	 * @return array<string,array{label:string,default:string,hint:string}>
	 */
	private function get_color_options(): array {
		return [
			'cfs_color_bg' => [
				'label'   => __( 'Background Color', 'cf-summarize' ),
				'default' => '#eef2ff',
				'hint'    => __( 'Card background.', 'cf-summarize' ),
			],
			'cfs_color_text' => [
				'label'   => __( 'Text Color', 'cf-summarize' ),
				'default' => '#374151',
				'hint'    => __( 'Key points and conclusion body text.', 'cf-summarize' ),
			],
			'cfs_color_title' => [
				'label'   => __( 'Title Color', 'cf-summarize' ),
				'default' => '#1e1b4b',
				'hint'    => __( '"Article Overview" heading.', 'cf-summarize' ),
			],
			'cfs_color_accent' => [
				'label'   => __( 'Accent Color', 'cf-summarize' ),
				'default' => '#6366f1',
				'hint'    => __( 'Section labels, bullet markers, conclusion border.', 'cf-summarize' ),
			],
			'cfs_color_gradient_1' => [
				'label'   => __( 'Top Gradient - Start', 'cf-summarize' ),
				'default' => '#6366f1',
				'hint'    => __( 'Left colour of the decorative top stripe.', 'cf-summarize' ),
			],
			'cfs_color_gradient_2' => [
				'label'   => __( 'Top Gradient - Middle', 'cf-summarize' ),
				'default' => '#a855f7',
				'hint'    => __( 'Middle colour of the decorative top stripe.', 'cf-summarize' ),
			],
			'cfs_color_gradient_3' => [
				'label'   => __( 'Top Gradient - End', 'cf-summarize' ),
				'default' => '#ec4899',
				'hint'    => __( 'Right colour of the decorative top stripe.', 'cf-summarize' ),
			],
			'cfs_btn_bg' => [
				'label'   => __( 'Button Background', 'cf-summarize' ),
				'default' => '#ffffff',
				'hint'    => __( 'Fill colour of the "Article Overview" button.', 'cf-summarize' ),
				'group'   => 'button',
			],
			'cfs_btn_text' => [
				'label'   => __( 'Button Text Color', 'cf-summarize' ),
				'default' => '#1e1b4b',
				'hint'    => __( 'Label colour inside the button.', 'cf-summarize' ),
				'group'   => 'button',
			],
			'cfs_btn_border' => [
				'label'   => __( 'Button Border', 'cf-summarize' ),
				'default' => '#e5e7eb',
				'hint'    => __( 'Outline colour of the button in its idle state.', 'cf-summarize' ),
				'group'   => 'button',
			],
		];
	}

	/**
	 * Supported button shape slugs → border-radius CSS value.
	 *
	 * @return array<string,array{label:string,radius:string}>
	 */
	public function get_button_shapes(): array {
		return [
			'pill'    => [ 'label' => __( 'Pill (fully rounded)', 'cf-summarize' ), 'radius' => '999px' ],
			'rounded' => [ 'label' => __( 'Rounded corners',      'cf-summarize' ), 'radius' => '10px'  ],
			'square'  => [ 'label' => __( 'Square corners',       'cf-summarize' ), 'radius' => '4px'   ],
		];
	}

	/**
	 * Sanitize the button-shape option against the supported list.
	 *
	 * @param mixed $value Raw input value.
	 * @return string Valid shape slug; falls back to 'pill'.
	 */
	public function sanitize_button_shape( $value ): string {
		$allowed = array_keys( $this->get_button_shapes() );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'pill';
	}

	/**
	 * Return the effective panel colours: saved value or default.
	 *
	 * @return array<string,string> var_name (without leading --) => hex colour
	 */
	public function get_effective_colors(): array {
		$out = [];
		foreach ( $this->get_color_options() as $key => $meta ) {
			$saved = (string) get_option( $key, '' );
			$out[ $key ] = '' !== $saved ? $saved : $meta['default'];
		}
		return $out;
	}

	/**
	 * Add submenu page under Settings.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'CF-Summarize Settings', 'cf-summarize' ),
			__( 'CF-Summarize', 'cf-summarize' ),
			'manage_options',
			'cf-summarize-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Enqueue inline admin JS - only on our settings page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_cf-summarize-settings' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'cfs-admin', CFS_PLUGIN_URL . 'assets/admin.css', [], CFS_VERSION );

		// Register a virtual script handle (no src) so we can attach inline JS.
		wp_register_script( 'cfs-admin', false, [], CFS_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'cfs-admin' );
		wp_add_inline_script( 'cfs-admin', $this->get_admin_js() );
	}

	/**
	 * Return the inline JS string for provider-field toggling and key removal.
	 *
	 * @return string Raw JavaScript (no <script> tags).
	 */
	private function get_admin_js(): string {
		$initial = esc_js( (string) get_option( 'cfs_provider', 'openai' ) );

		return <<<JSCODE
( function () {
	'use strict';

	/**
	 * Show field rows belonging to the selected provider; hide all others.
	 *
	 * @param {string} selected Provider slug.
	 */
	function toggleProviderRows( selected ) {
		document.querySelectorAll( '.cfs-field-row[data-provider-field]' ).forEach( function ( row ) {
			row.style.display = ( row.dataset.providerField === selected ) ? '' : 'none';
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		// Apply initial state based on saved provider.
		toggleProviderRows( '{$initial}' );

		// Re-apply whenever the provider dropdown changes.
		var select = document.getElementById( 'cfs_provider' );
		if ( select ) {
			select.addEventListener( 'change', function () {
				toggleProviderRows( this.value );
			} );
		}


		// "Remove saved key" buttons - clear the input so an empty value is saved.
		document.querySelectorAll( '.cfs-remove-key' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var input = document.getElementById( btn.dataset.target );
				if ( input ) {
					input.value = '';
					input.type  = 'text'; // reveal empty state visually
					input.focus();
				}
				// Hide the "Key saved" badge and this button.
				var badge = document.querySelector( '.cfs-key-badge[data-for="' + btn.dataset.target + '"]' );
				if ( badge ) badge.style.display = 'none';
				btn.style.display = 'none';
			} );
		} );

		// ── Modern custom color picker (hex-first) ─────────────────────────
		// Click a swatch → popover with saturation/value area + hue slider + hex input.

		function clamp01( n ) { return Math.max( 0, Math.min( 1, n ) ); }

		function isValidHex( v ) {
			return /^#[0-9a-fA-F]{6}$/.test( v );
		}

		function normalizeHex( v ) {
			v = ( v || '' ).trim().toLowerCase();
			if ( v.charAt( 0 ) !== '#' ) { v = '#' + v; }
			if ( /^#[0-9a-f]{3}$/.test( v ) ) {
				v = '#' + v.charAt(1) + v.charAt(1) + v.charAt(2) + v.charAt(2) + v.charAt(3) + v.charAt(3);
			}
			return v;
		}

		function hexToRgb( hex ) {
			var m = /^#?([0-9a-fA-F]{6})$/.exec( hex );
			if ( ! m ) { return null; }
			var n = parseInt( m[1], 16 );
			return { r: ( n >> 16 ) & 0xff, g: ( n >> 8 ) & 0xff, b: n & 0xff };
		}

		function rgbToHex( r, g, b ) {
			function h( v ) {
				v = Math.max( 0, Math.min( 255, Math.round( v ) ) ).toString( 16 );
				return v.length === 1 ? '0' + v : v;
			}
			return '#' + h( r ) + h( g ) + h( b );
		}

		function rgbToHsv( r, g, b ) {
			r /= 255; g /= 255; b /= 255;
			var max = Math.max( r, g, b ), min = Math.min( r, g, b );
			var d = max - min;
			var h = 0, s = max === 0 ? 0 : d / max, v = max;
			if ( d !== 0 ) {
				if ( max === r )      { h = ( ( g - b ) / d ) % 6; }
				else if ( max === g ) { h = ( b - r ) / d + 2; }
				else                  { h = ( r - g ) / d + 4; }
				h *= 60;
				if ( h < 0 ) { h += 360; }
			}
			return { h: h, s: s * 100, v: v * 100 };
		}

		function hsvToRgb( h, s, v ) {
			s /= 100; v /= 100;
			var c = v * s;
			var x = c * ( 1 - Math.abs( ( ( h / 60 ) % 2 ) - 1 ) );
			var m = v - c;
			var r = 0, g = 0, b = 0;
			if      ( h < 60 )  { r = c; g = x; }
			else if ( h < 120 ) { r = x; g = c; }
			else if ( h < 180 ) { g = c; b = x; }
			else if ( h < 240 ) { g = x; b = c; }
			else if ( h < 300 ) { r = x; b = c; }
			else                { r = c; b = x; }
			return { r: ( r + m ) * 255, g: ( g + m ) * 255, b: ( b + m ) * 255 };
		}

		function rgbToHsl( r, g, b ) {
			r /= 255; g /= 255; b /= 255;
			var max = Math.max( r, g, b ), min = Math.min( r, g, b );
			var h = 0, s, l = ( max + min ) / 2;
			if ( max === min ) {
				s = 0;
			} else {
				var d = max - min;
				s = l > 0.5 ? d / ( 2 - max - min ) : d / ( max + min );
				if ( max === r )      { h = ( g - b ) / d + ( g < b ? 6 : 0 ); }
				else if ( max === g ) { h = ( b - r ) / d + 2; }
				else                  { h = ( r - g ) / d + 4; }
				h *= 60;
			}
			return { h: h, s: s * 100, l: l * 100 };
		}

		function hslToRgb( h, s, l ) {
			h /= 360; s /= 100; l /= 100;
			var r, g, b;
			if ( s === 0 ) {
				r = g = b = l;
			} else {
				var hue2rgb = function ( p, q, t ) {
					if ( t < 0 ) { t += 1; }
					if ( t > 1 ) { t -= 1; }
					if ( t < 1 / 6 ) { return p + ( q - p ) * 6 * t; }
					if ( t < 1 / 2 ) { return q; }
					if ( t < 2 / 3 ) { return p + ( q - p ) * ( 2 / 3 - t ) * 6; }
					return p;
				};
				var q = l < 0.5 ? l * ( 1 + s ) : l + s - l * s;
				var p = 2 * l - q;
				r = hue2rgb( p, q, h + 1 / 3 );
				g = hue2rgb( p, q, h );
				b = hue2rgb( p, q, h - 1 / 3 );
			}
			return { r: r * 255, g: g * 255, b: b * 255 };
		}

		var currentPicker = null;

		function closePicker() {
			if ( ! currentPicker ) { return; }
			document.removeEventListener( 'pointerdown', currentPicker.onDocDown, true );
			document.removeEventListener( 'keydown', currentPicker.onKey );
			window.removeEventListener( 'resize', currentPicker.onReflow );
			window.removeEventListener( 'scroll', currentPicker.onReflow, true );
			currentPicker.el.remove();
			currentPicker = null;
		}

		function buildPopover() {
			var pop = document.createElement( 'div' );
			pop.className = 'cfs-picker-popover';
			pop.setAttribute( 'role', 'dialog' );
			pop.innerHTML =
				'<div class="cfs-picker-sv"><div class="cfs-picker-sv-thumb"></div></div>' +
				'<div class="cfs-picker-hue"><div class="cfs-picker-hue-thumb"></div></div>' +
				'<div class="cfs-picker-footer" data-mode="hex">' +
					'<span class="cfs-picker-preview"></span>' +
					'<div class="cfs-picker-fields">' +
						'<div class="cfs-picker-fields-hex">' +
							'<span class="cfs-picker-hash">#</span>' +
							'<input type="text" class="cfs-picker-hex" maxlength="6" spellcheck="false" autocomplete="off" />' +
						'</div>' +
						'<div class="cfs-picker-fields-rgb">' +
							'<label class="cfs-picker-num-wrap"><input type="text" class="cfs-picker-num" data-ch="r" maxlength="3" inputmode="numeric" spellcheck="false" autocomplete="off" /><span>R</span></label>' +
							'<label class="cfs-picker-num-wrap"><input type="text" class="cfs-picker-num" data-ch="g" maxlength="3" inputmode="numeric" spellcheck="false" autocomplete="off" /><span>G</span></label>' +
							'<label class="cfs-picker-num-wrap"><input type="text" class="cfs-picker-num" data-ch="b" maxlength="3" inputmode="numeric" spellcheck="false" autocomplete="off" /><span>B</span></label>' +
						'</div>' +
						'<div class="cfs-picker-fields-hsl">' +
							'<label class="cfs-picker-num-wrap"><input type="text" class="cfs-picker-num" data-ch="h" maxlength="3" inputmode="numeric" spellcheck="false" autocomplete="off" /><span>H</span></label>' +
							'<label class="cfs-picker-num-wrap"><input type="text" class="cfs-picker-num" data-ch="s" maxlength="3" inputmode="numeric" spellcheck="false" autocomplete="off" /><span>S</span></label>' +
							'<label class="cfs-picker-num-wrap"><input type="text" class="cfs-picker-num" data-ch="l" maxlength="3" inputmode="numeric" spellcheck="false" autocomplete="off" /><span>L</span></label>' +
						'</div>' +
					'</div>' +
					'<button type="button" class="cfs-picker-format-toggle" title="Switch format (HEX / RGB / HSL)" aria-label="Switch format">' +
						'<span class="cfs-picker-format-label">HEX</span>' +
						'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="12" viewBox="0 0 10 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="2 4.5 5 1.5 8 4.5"/><polyline points="2 7.5 5 10.5 8 7.5"/></svg>' +
					'</button>' +
				'</div>';
			return pop;
		}

		function positionPopover( pop, trigger ) {
			var r = trigger.getBoundingClientRect();
			var pw = pop.offsetWidth || 240;
			var left = window.scrollX + r.left;
			var maxLeft = window.scrollX + document.documentElement.clientWidth - pw - 12;
			if ( left > maxLeft ) { left = maxLeft; }
			pop.style.top  = ( window.scrollY + r.bottom + 8 ) + 'px';
			pop.style.left = left + 'px';
		}

		function openPicker( trigger, hexInput ) {
			closePicker();

			var initial = normalizeHex( hexInput.value );
			if ( ! isValidHex( initial ) ) { initial = '#000000'; }
			var rgb = hexToRgb( initial );
			var hsv = rgbToHsv( rgb.r, rgb.g, rgb.b );

			var pop = buildPopover();
			document.body.appendChild( pop );

			var sv       = pop.querySelector( '.cfs-picker-sv' );
			var svThumb  = pop.querySelector( '.cfs-picker-sv-thumb' );
			var hue      = pop.querySelector( '.cfs-picker-hue' );
			var hueThumb = pop.querySelector( '.cfs-picker-hue-thumb' );
			var preview  = pop.querySelector( '.cfs-picker-preview' );
			var hexField = pop.querySelector( '.cfs-picker-hex' );
			var footer   = pop.querySelector( '.cfs-picker-footer' );
			var toggle   = pop.querySelector( '.cfs-picker-format-toggle' );
			var toggleLbl = pop.querySelector( '.cfs-picker-format-label' );

			// Per-channel number inputs for RGB + HSL modes.
			var num = {};
			pop.querySelectorAll( '.cfs-picker-num' ).forEach( function ( el ) {
				num[ el.dataset.ch ] = el;
			} );

			var formats   = [ 'hex', 'rgb', 'hsl' ];
			var formatIdx = 0; // Default: HEX.

			function activeFormat() { return formats[ formatIdx ]; }

			function render() {
				var c   = hsvToRgb( hsv.h, hsv.s, hsv.v );
				var hex = rgbToHex( c.r, c.g, c.b );

				sv.style.background =
					'linear-gradient(to top, #000, rgba(0,0,0,0)),' +
					'linear-gradient(to right, #fff, hsl(' + hsv.h + ', 100%, 50%))';
				svThumb.style.left  = hsv.s + '%';
				svThumb.style.top   = ( 100 - hsv.v ) + '%';
				svThumb.style.background = hex;
				hueThumb.style.left = ( hsv.h / 360 * 100 ) + '%';
				preview.style.background = hex;
				trigger.style.background = hex;
				hexInput.value = hex;
				hexInput.setCustomValidity( '' );

				// Fill inputs for the active format - don't overwrite whichever
				// field the user is currently typing into.
				var active = document.activeElement;
				var fmt    = activeFormat();

				if ( fmt === 'hex' && active !== hexField ) {
					hexField.value = hex.slice( 1 );
				} else if ( fmt === 'rgb' ) {
					if ( active !== num.r ) { num.r.value = Math.round( c.r ); }
					if ( active !== num.g ) { num.g.value = Math.round( c.g ); }
					if ( active !== num.b ) { num.b.value = Math.round( c.b ); }
				} else if ( fmt === 'hsl' ) {
					var hsl = rgbToHsl( c.r, c.g, c.b );
					if ( active !== num.h ) { num.h.value = Math.round( hsl.h ); }
					if ( active !== num.s ) { num.s.value = Math.round( hsl.s ); }
					if ( active !== num.l ) { num.l.value = Math.round( hsl.l ); }
				}
			}

			function cycleFormat() {
				formatIdx = ( formatIdx + 1 ) % formats.length;
				var fmt = activeFormat();
				footer.dataset.mode = fmt;
				toggleLbl.textContent = fmt.toUpperCase();
				render();
				// Focus the first input of the new mode for quick editing.
				var first = fmt === 'hex' ? hexField : ( fmt === 'rgb' ? num.r : num.h );
				first.focus();
				first.select();
			}

			toggle.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				cycleFormat();
			} );

			function dragHandler( el, moveFn ) {
				function updateFromEvent( ev ) {
					var rect = el.getBoundingClientRect();
					var x = clamp01( ( ev.clientX - rect.left ) / rect.width );
					var y = clamp01( ( ev.clientY - rect.top ) / rect.height );
					moveFn( x, y );
					render();
				}
				el.addEventListener( 'pointerdown', function ( ev ) {
					ev.preventDefault();
					try { el.setPointerCapture( ev.pointerId ); } catch ( _ ) {}
					updateFromEvent( ev );
					function onMove( e ) { updateFromEvent( e ); }
					function onUp() {
						el.removeEventListener( 'pointermove', onMove );
						el.removeEventListener( 'pointerup', onUp );
						el.removeEventListener( 'pointercancel', onUp );
					}
					el.addEventListener( 'pointermove', onMove );
					el.addEventListener( 'pointerup', onUp );
					el.addEventListener( 'pointercancel', onUp );
				} );
			}

			dragHandler( sv, function ( x, y ) {
				hsv.s = x * 100;
				hsv.v = ( 1 - y ) * 100;
			} );

			dragHandler( hue, function ( x ) {
				hsv.h = Math.min( 359.999, x * 360 );
			} );

			hexField.addEventListener( 'input', function () {
				var v = hexField.value.trim().toLowerCase();
				if ( v.charAt( 0 ) === '#' ) { v = v.slice( 1 ); }
				if ( /^[0-9a-f]{6}$/.test( v ) ) {
					var rgb2 = hexToRgb( '#' + v );
					var nh   = rgbToHsv( rgb2.r, rgb2.g, rgb2.b );
					hsv.h = nh.h; hsv.s = nh.s; hsv.v = nh.v;
					render();
				}
			} );

			function readRgbInputs() {
				var r = parseInt( num.r.value, 10 );
				var g = parseInt( num.g.value, 10 );
				var b = parseInt( num.b.value, 10 );
				if ( ! Number.isFinite( r ) || ! Number.isFinite( g ) || ! Number.isFinite( b ) ) { return; }
				r = Math.max( 0, Math.min( 255, r ) );
				g = Math.max( 0, Math.min( 255, g ) );
				b = Math.max( 0, Math.min( 255, b ) );
				var nh = rgbToHsv( r, g, b );
				hsv.h = nh.h; hsv.s = nh.s; hsv.v = nh.v;
				render();
			}

			function readHslInputs() {
				var h = parseFloat( num.h.value );
				var s = parseFloat( num.s.value );
				var l = parseFloat( num.l.value );
				if ( ! Number.isFinite( h ) || ! Number.isFinite( s ) || ! Number.isFinite( l ) ) { return; }
				h = ( ( h % 360 ) + 360 ) % 360;
				s = Math.max( 0, Math.min( 100, s ) );
				l = Math.max( 0, Math.min( 100, l ) );
				var rgb2 = hslToRgb( h, s, l );
				var nh   = rgbToHsv( rgb2.r, rgb2.g, rgb2.b );
				hsv.h = nh.h; hsv.s = nh.s; hsv.v = nh.v;
				render();
			}

			[ 'r', 'g', 'b' ].forEach( function ( ch ) {
				num[ ch ].addEventListener( 'input', readRgbInputs );
			} );
			[ 'h', 's', 'l' ].forEach( function ( ch ) {
				num[ ch ].addEventListener( 'input', readHslInputs );
			} );

			// Enter inside any field closes the popover.
			pop.querySelectorAll( 'input' ).forEach( function ( inp ) {
				inp.addEventListener( 'keydown', function ( ev ) {
					if ( ev.key === 'Enter' ) { ev.preventDefault(); closePicker(); }
				} );
			} );

			var onDocDown = function ( ev ) {
				if ( pop.contains( ev.target ) || trigger.contains( ev.target ) ) { return; }
				closePicker();
			};
			var onKey = function ( ev ) {
				if ( ev.key === 'Escape' ) { closePicker(); trigger.focus(); }
			};
			var onReflow = function () { positionPopover( pop, trigger ); };

			document.addEventListener( 'pointerdown', onDocDown, true );
			document.addEventListener( 'keydown', onKey );
			window.addEventListener( 'resize', onReflow );
			window.addEventListener( 'scroll', onReflow, true );

			currentPicker = { el: pop, onDocDown: onDocDown, onKey: onKey, onReflow: onReflow };

			render();
			positionPopover( pop, trigger );
			hexField.focus();
			hexField.select();
		}

		// Wire up each color group.
		document.querySelectorAll( '.cfs-color-group' ).forEach( function ( group ) {
			var trigger = group.querySelector( '.cfs-color-trigger' );
			var hex     = group.querySelector( '.cfs-color-hex' );
			var reset   = group.querySelector( '.cfs-color-reset' );

			if ( ! trigger || ! hex ) { return; }

			function syncTrigger() {
				var v = normalizeHex( hex.value );
				if ( isValidHex( v ) ) { trigger.style.background = v; }
			}
			syncTrigger();

			trigger.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openPicker( trigger, hex );
			} );

			hex.addEventListener( 'input', function () {
				var v = normalizeHex( hex.value );
				if ( isValidHex( v ) ) {
					trigger.style.background = v;
					hex.setCustomValidity( '' );
				} else {
					hex.setCustomValidity( 'Enter a valid hex colour (e.g. #6366f1).' );
				}
			} );

			hex.addEventListener( 'blur', function () {
				var v = normalizeHex( hex.value );
				if ( isValidHex( v ) ) { hex.value = v; }
			} );

			if ( reset ) {
				reset.addEventListener( 'click', function () {
					var def = reset.dataset.default || '#000000';
					hex.value = def;
					trigger.style.background = def;
					hex.setCustomValidity( '' );
					if ( currentPicker ) { closePicker(); }
				} );
			}
		} );

		// ── Appearance tabs ───────────────────────────────────────────────────
		document.querySelectorAll( '.cfs-app-tab-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var tab  = btn.dataset.tab;
				var body = btn.closest( '.cfs-admin-card-body' );
				if ( ! body ) { return; }
				body.querySelectorAll( '.cfs-app-tab-btn' ).forEach( function ( b ) {
					b.classList.remove( 'is-active' );
					b.setAttribute( 'aria-selected', 'false' );
				} );
				body.querySelectorAll( '.cfs-tab-panel' ).forEach( function ( p ) {
					p.classList.remove( 'is-active' );
				} );
				btn.classList.add( 'is-active' );
				btn.setAttribute( 'aria-selected', 'true' );
				var panel = body.querySelector( '.cfs-tab-panel[data-tab="' + tab + '"]' );
				if ( panel ) { panel.classList.add( 'is-active' ); }
				closePicker();
			} );
		} );

	} );
}() );
JSCODE;
	}

	/**
	 * Register all settings, sections, and fields.
	 */
	public function register_settings(): void {
		// ── API Configuration ─────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_api',
			__( 'API Configuration', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		register_setting( 'cfs_settings_group', 'cfs_provider', [ $this, 'sanitize_provider' ] );
		add_settings_field(
			'cfs_provider',
			__( 'Provider', 'cf-summarize' ),
			[ $this, 'field_provider' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_openai_api_key', 'sanitize_text_field' );
		add_settings_field(
			'cfs_openai_api_key',
			__( 'OpenAI API Key', 'cf-summarize' ),
			[ $this, 'field_openai_api_key' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_model_openai', 'sanitize_text_field' );
		add_settings_field(
			'cfs_model_openai',
			__( 'OpenAI Model', 'cf-summarize' ),
			[ $this, 'field_openai_model' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_anthropic_api_key', 'sanitize_text_field' );
		add_settings_field(
			'cfs_anthropic_api_key',
			__( 'Anthropic API Key', 'cf-summarize' ),
			[ $this, 'field_anthropic_api_key' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		register_setting( 'cfs_settings_group', 'cfs_model_anthropic', 'sanitize_text_field' );
		add_settings_field(
			'cfs_model_anthropic',
			__( 'Anthropic Model', 'cf-summarize' ),
			[ $this, 'field_anthropic_model' ],
			'cf-summarize-settings',
			'cfs_section_api'
		);

		// ── Display Settings ──────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_display',
			__( 'Display Settings', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		register_setting( 'cfs_settings_group', 'cfs_button_label', 'sanitize_text_field' );
		add_settings_field(
			'cfs_button_label',
			__( 'Button Label', 'cf-summarize' ),
			[ $this, 'field_button_label' ],
			'cf-summarize-settings',
			'cfs_section_display'
		);

		register_setting( 'cfs_settings_group', 'cfs_button_position', [ $this, 'sanitize_position' ] );
		add_settings_field(
			'cfs_button_position',
			__( 'Button Position', 'cf-summarize' ),
			[ $this, 'field_button_position' ],
			'cf-summarize-settings',
			'cfs_section_display'
		);

		register_setting( 'cfs_settings_group', 'cfs_enable_all', [ $this, 'sanitize_checkbox' ] );
		add_settings_field(
			'cfs_enable_all',
			__( 'Enable on All Posts by Default', 'cf-summarize' ),
			[ $this, 'field_enable_all' ],
			'cf-summarize-settings',
			'cfs_section_display'
		);

		// ── Appearance ────────────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_appearance',
			__( 'Appearance', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		foreach ( $this->get_color_options() as $key => $_def ) {
			register_setting( 'cfs_settings_group', $key, 'sanitize_hex_color' );
		}

		register_setting( 'cfs_settings_group', 'cfs_btn_shape', [ $this, 'sanitize_button_shape' ] );

		// ── Performance ───────────────────────────────────────────────────────
		add_settings_section(
			'cfs_section_performance',
			__( 'Performance', 'cf-summarize' ),
			'__return_false',
			'cf-summarize-settings'
		);

		register_setting( 'cfs_settings_group', 'cfs_cache_enabled', [ $this, 'sanitize_checkbox' ] );
		add_settings_field(
			'cfs_cache_enabled',
			__( 'Enable Caching', 'cf-summarize' ),
			[ $this, 'field_cache_enabled' ],
			'cf-summarize-settings',
			'cfs_section_performance'
		);

		register_setting( 'cfs_settings_group', 'cfs_cache_duration', [ $this, 'sanitize_cache_duration' ] );

	}

	/**
	 * Handle the "Clear All Summaries Cache" button action.
	 */
	public function handle_cache_clear(): void {
		if (
			! isset( $_POST['cfs_clear_cache'] ) ||
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cfs_settings_group-options' )
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_cfs_summary' ] );
		// phpcs:enable

		add_settings_error(
			'cfs_settings',
			'cfs_cache_cleared',
			__( 'All summary caches have been cleared.', 'cf-summarize' ),
			'updated'
		);
	}

	/**
	 * Render the full settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_provider = (string) get_option( 'cfs_provider', 'openai' );
		$cache_enabled    = (bool) get_option( 'cfs_cache_enabled', false );
		$providers        = $this->get_providers();

		$openai_key   = (string) get_option( 'cfs_openai_api_key', '' );
		$openai_model = (string) get_option( 'cfs_model_openai', '' );
		$anth_key     = (string) get_option( 'cfs_anthropic_api_key', '' );
		$anth_model   = (string) get_option( 'cfs_model_anthropic', '' );
		$btn_label    = (string) get_option( 'cfs_button_label', 'Article Overview' );
		$btn_pos      = (string) get_option( 'cfs_button_position', 'before' );
		$enable_all   = (bool) get_option( 'cfs_enable_all', true );
		settings_errors( 'cfs_settings' );
		?>
		<style>
			/* Hide non-active provider rows before JS runs - prevents flicker */
			.cfs-field-row[data-provider-field]:not([data-provider-field="<?php echo esc_attr( $current_provider ); ?>"]) { display: none; }
		</style>
		<div class="cfs-admin-wrap">

			<div class="cfs-admin-header">
				<div class="cfs-admin-logo" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
						<path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09z"/>
						<path d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456z"/>
					</svg>
				</div>
				<div class="cfs-admin-header-text">
					<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<p><?php esc_html_e( 'Configure your AI-powered content summarizer', 'cf-summarize' ); ?></p>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'cfs_settings_group' ); ?>

				<div class="cfs-admin-cards">

				<!-- ── API Configuration ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25z"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'API Configuration', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_provider"><?php esc_html_e( 'Provider', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Only that provider\'s key and model fields will appear below.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<select name="cfs_provider" id="cfs_provider" class="cfs-select">
									<?php foreach ( $providers as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_provider, $slug ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="openai">
							<div class="cfs-field-label">
								<label for="cfs_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<input type="password" name="cfs_openai_api_key" id="cfs_openai_api_key"
									value="<?php echo esc_attr( $openai_key ); ?>"
									placeholder="sk-..." autocomplete="new-password" />
								<?php if ( $openai_key ) : ?>
									<span class="cfs-key-badge" data-for="cfs_openai_api_key">&#10003; <?php esc_html_e( 'Key saved', 'cf-summarize' ); ?></span>
									<button type="button" class="cfs-remove-key" data-target="cfs_openai_api_key"><?php esc_html_e( 'Remove', 'cf-summarize' ); ?></button>
								<?php endif; ?>
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="openai">
							<div class="cfs-field-label">
								<label for="cfs_model_openai"><?php esc_html_e( 'OpenAI Model', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'e.g. gpt-4o-mini, gpt-4o, gpt-4-turbo', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="text" name="cfs_model_openai" id="cfs_model_openai"
									value="<?php echo esc_attr( $openai_model ); ?>"
									placeholder="gpt-4o-mini" />
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="anthropic">
							<div class="cfs-field-label">
								<label for="cfs_anthropic_api_key"><?php esc_html_e( 'Anthropic API Key', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<input type="password" name="cfs_anthropic_api_key" id="cfs_anthropic_api_key"
									value="<?php echo esc_attr( $anth_key ); ?>"
									placeholder="sk-ant-..." autocomplete="new-password" />
								<?php if ( $anth_key ) : ?>
									<span class="cfs-key-badge" data-for="cfs_anthropic_api_key">&#10003; <?php esc_html_e( 'Key saved', 'cf-summarize' ); ?></span>
									<button type="button" class="cfs-remove-key" data-target="cfs_anthropic_api_key"><?php esc_html_e( 'Remove', 'cf-summarize' ); ?></button>
								<?php endif; ?>
							</div>
						</div>

						<div class="cfs-field-row" data-provider-field="anthropic">
							<div class="cfs-field-label">
								<label for="cfs_model_anthropic"><?php esc_html_e( 'Anthropic Model', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'e.g. claude-haiku-4-5-20251001, claude-sonnet-4-6', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="text" name="cfs_model_anthropic" id="cfs_model_anthropic"
									value="<?php echo esc_attr( $anth_model ); ?>"
									placeholder="claude-haiku-4-5-20251001" />
							</div>
						</div>

					</div>
				</div>

				<!-- ── Display Settings ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
								<path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'Display Settings', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_button_label"><?php esc_html_e( 'Button Label', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<input type="text" name="cfs_button_label" id="cfs_button_label"
									value="<?php echo esc_attr( $btn_label ); ?>" />
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_button_position"><?php esc_html_e( 'Button Position', 'cf-summarize' ); ?></label>
							</div>
							<div>
								<select name="cfs_button_position" id="cfs_button_position" class="cfs-select">
									<option value="before" <?php selected( $btn_pos, 'before' ); ?>><?php esc_html_e( 'Before content', 'cf-summarize' ); ?></option>
									<option value="after"  <?php selected( $btn_pos, 'after' ); ?>><?php esc_html_e( 'After content',  'cf-summarize' ); ?></option>
								</select>
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label><?php esc_html_e( 'Enable on All Posts', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Individual posts can override this via the meta box.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<label class="cfs-toggle-wrap">
									<span class="cfs-toggle" aria-hidden="true">
										<input type="checkbox" class="cfs-toggle-input" name="cfs_enable_all" id="cfs_enable_all" value="1" <?php checked( $enable_all ); ?> />
										<span class="cfs-toggle-track"></span>
										<span class="cfs-toggle-thumb"></span>
									</span>
									<span class="cfs-toggle-text"><?php esc_html_e( 'Show the summary button on all posts by default', 'cf-summarize' ); ?></span>
								</label>
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label><?php esc_html_e( 'Custom Placement', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Show the button at an exact spot instead of the automatic position.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<p class="cfs-shortcode-note">
									<?php
									printf(
										/* translators: %s: the [cf-summarize] shortcode */
										esc_html__( 'Add the %s shortcode inside a post to place the Article Overview button right there. It summarizes the post it sits in and works even when that post is disabled above.', 'cf-summarize' ),
										'<code>[cf-summarize]</code>'
									);
									?>
								</p>
							</div>
						</div>

					</div>
				</div>

				<!-- ── Appearance ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M12 21a9 9 0 1 1 0-18 7.5 7.5 0 0 1 7.5 7.5c0 2.485-2.015 4.5-4.5 4.5h-1.5a1.5 1.5 0 0 0-1.5 1.5v.75A2.25 2.25 0 0 1 9.75 19.5v0A2.25 2.25 0 0 0 7.5 21z"/>
								<circle cx="7.5" cy="10.5" r="1"/>
								<circle cx="12" cy="7.5" r="1"/>
								<circle cx="16.5" cy="10.5" r="1"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'Appearance', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<!-- Tab navigation -->
						<div class="cfs-app-tabs" role="tablist">
							<button type="button" class="cfs-app-tab-btn is-active" role="tab" aria-selected="true" data-tab="panel"><?php esc_html_e( 'Panel Colors', 'cf-summarize' ); ?></button>
							<button type="button" class="cfs-app-tab-btn" role="tab" aria-selected="false" data-tab="button"><?php esc_html_e( 'Button Style', 'cf-summarize' ); ?></button>
						</div>

						<?php
						$colors        = $this->get_effective_colors();
						$current_shape = $this->sanitize_button_shape( (string) get_option( 'cfs_btn_shape', 'pill' ) );
						$shapes        = $this->get_button_shapes();
						?>

						<!-- Panel Colors tab panel -->
						<div class="cfs-tab-panel is-active" data-tab="panel" role="tabpanel">
							<?php foreach ( $this->get_color_options() as $key => $meta ) : ?>
								<?php if ( isset( $meta['group'] ) && 'button' === $meta['group'] ) : continue; endif; ?>
								<?php $val = $colors[ $key ]; ?>
								<div class="cfs-field-row">
									<div class="cfs-field-label">
										<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['label'] ); ?></label>
										<?php if ( ! empty( $meta['hint'] ) ) : ?>
											<span class="cfs-hint"><?php echo esc_html( $meta['hint'] ); ?></span>
										<?php endif; ?>
									</div>
									<div>
										<div class="cfs-color-group">
											<button
												type="button"
												class="cfs-color-trigger"
												style="background: <?php echo esc_attr( $val ); ?>;"
												aria-label="<?php echo esc_attr( sprintf( __( 'Open %s picker', 'cf-summarize' ), $meta['label'] ) ); ?>"
												aria-haspopup="dialog"
											></button>
											<input
												type="text"
												class="cfs-color-hex"
												name="<?php echo esc_attr( $key ); ?>"
												id="<?php echo esc_attr( $key ); ?>"
												value="<?php echo esc_attr( $val ); ?>"
												maxlength="7"
												spellcheck="false"
												autocomplete="off"
												pattern="^#[0-9a-fA-F]{6}$"
												placeholder="#rrggbb"
											/>
											<button
												type="button"
												class="cfs-color-reset"
												data-default="<?php echo esc_attr( $meta['default'] ); ?>"
												title="<?php esc_attr_e( 'Reset to default', 'cf-summarize' ); ?>"
											>
												<?php esc_html_e( 'Reset', 'cf-summarize' ); ?>
											</button>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<!-- Button Style tab panel -->
						<div class="cfs-tab-panel" data-tab="button" role="tabpanel">
							<?php foreach ( $this->get_color_options() as $key => $meta ) : ?>
								<?php if ( ! isset( $meta['group'] ) || 'button' !== $meta['group'] ) : continue; endif; ?>
								<?php $val = $colors[ $key ]; ?>
								<div class="cfs-field-row">
									<div class="cfs-field-label">
										<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['label'] ); ?></label>
										<?php if ( ! empty( $meta['hint'] ) ) : ?>
											<span class="cfs-hint"><?php echo esc_html( $meta['hint'] ); ?></span>
										<?php endif; ?>
									</div>
									<div>
										<div class="cfs-color-group">
											<button
												type="button"
												class="cfs-color-trigger"
												style="background: <?php echo esc_attr( $val ); ?>;"
												aria-label="<?php echo esc_attr( sprintf( __( 'Open %s picker', 'cf-summarize' ), $meta['label'] ) ); ?>"
												aria-haspopup="dialog"
											></button>
											<input
												type="text"
												class="cfs-color-hex"
												name="<?php echo esc_attr( $key ); ?>"
												id="<?php echo esc_attr( $key ); ?>"
												value="<?php echo esc_attr( $val ); ?>"
												maxlength="7"
												spellcheck="false"
												autocomplete="off"
												pattern="^#[0-9a-fA-F]{6}$"
												placeholder="#rrggbb"
											/>
											<button
												type="button"
												class="cfs-color-reset"
												data-default="<?php echo esc_attr( $meta['default'] ); ?>"
												title="<?php esc_attr_e( 'Reset to default', 'cf-summarize' ); ?>"
											>
												<?php esc_html_e( 'Reset', 'cf-summarize' ); ?>
											</button>
										</div>
									</div>
								</div>
							<?php endforeach; ?>

							<div class="cfs-field-row">
								<div class="cfs-field-label">
									<label for="cfs_btn_shape"><?php esc_html_e( 'Button Shape', 'cf-summarize' ); ?></label>
									<span class="cfs-hint"><?php esc_html_e( 'Corner style for the button outline.', 'cf-summarize' ); ?></span>
								</div>
								<div>
									<select name="cfs_btn_shape" id="cfs_btn_shape" class="cfs-select">
										<?php foreach ( $shapes as $slug => $shape ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_shape, $slug ); ?>>
												<?php echo esc_html( $shape['label'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>

					</div>
				</div>

				<!-- ── Performance ── -->
				<div class="cfs-admin-card">
					<div class="cfs-admin-card-head">
						<div class="cfs-card-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
								<path d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
							</svg>
						</div>
						<h2><?php esc_html_e( 'Performance', 'cf-summarize' ); ?></h2>
					</div>
					<div class="cfs-admin-card-body">

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label><?php esc_html_e( 'Enable Caching', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Avoid regenerating summaries on every page visit.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<label class="cfs-toggle-wrap">
									<span class="cfs-toggle" aria-hidden="true">
										<input type="checkbox" class="cfs-toggle-input" name="cfs_cache_enabled" id="cfs_cache_enabled" value="1" <?php checked( $cache_enabled ); ?> />
										<span class="cfs-toggle-track"></span>
										<span class="cfs-toggle-thumb"></span>
									</span>
									<span class="cfs-toggle-text"><?php esc_html_e( 'Serve cached summaries until they expire', 'cf-summarize' ); ?></span>
								</label>
							</div>
						</div>

						<div class="cfs-field-row">
							<div class="cfs-field-label">
								<label for="cfs_cache_duration"><?php esc_html_e( 'Cache Duration', 'cf-summarize' ); ?></label>
								<span class="cfs-hint"><?php esc_html_e( 'Seconds to keep a summary before regenerating (e.g. 86400 = 24 hours). 0 = never expire.', 'cf-summarize' ); ?></span>
							</div>
							<div>
								<input type="number" min="0" step="1" name="cfs_cache_duration" id="cfs_cache_duration"
									value="<?php echo esc_attr( (string) get_option( 'cfs_cache_duration', 86400 ) ); ?>" />
							</div>
						</div>

					</div>
				</div>

				</div><!-- /.cfs-admin-cards -->

				<div class="cfs-admin-actions">
					<button type="submit" class="cfs-save-btn"><?php esc_html_e( 'Save Settings', 'cf-summarize' ); ?></button>
					<button type="submit" name="cfs_clear_cache" value="1" class="cfs-clear-btn"><?php esc_html_e( 'Clear All Summaries Cache', 'cf-summarize' ); ?></button>
				</div>

			</form>
		</div>
		<?php
	}

	// ── Field renderers ───────────────────────────────────────────────────────

	/**
	 * Render the Provider select field.
	 * Options are driven by get_providers() - no hardcoded provider names.
	 */
	public function field_provider(): void {
		$current   = (string) get_option( 'cfs_provider', 'openai' );
		$providers = $this->get_providers();
		?>
		<select name="cfs_provider" id="cfs_provider">
			<?php foreach ( $providers as $slug => $label ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Choose your AI provider. Only that provider\'s API Key and Model fields will be shown below.', 'cf-summarize' ); ?></p>
		<?php
	}

	/**
	 * Open a provider-scoped wrapper div.
	 * The JS reads data-for-provider to decide which <tr> to hide/show.
	 *
	 * @param string $provider Provider slug (e.g. 'openai', 'anthropic').
	 */
	private function open_provider_row( string $provider ): void {
		printf(
			'<div class="cfs-provider-field" data-for-provider="%s">',
			esc_attr( $provider )
		);
	}

	/** Close the provider-scoped wrapper div. */
	private function close_provider_row(): void {
		echo '</div>';
	}

	/**
	 * Render a "✓ Key saved / Remove" indicator next to an API key input.
	 * Shown only when a key is already stored.  The JS "Remove" button clears
	 * the <input> so that saving the form writes an empty value.
	 *
	 * @param string $saved_value The currently stored key (may be empty).
	 * @param string $input_id    The id attribute of the associated <input>.
	 */
	private function render_key_status( string $saved_value, string $input_id ): void {
		if ( '' === $saved_value ) {
			return;
		}
		printf(
			'&nbsp;<span class="cfs-key-badge" data-for="%1$s" style="color:#3a7c3a;font-weight:600;" aria-label="%2$s">&#10003; %2$s</span>' .
			'&nbsp;<button type="button" class="button-link cfs-remove-key" data-target="%1$s" style="color:#a00;" aria-label="%3$s %2$s">%3$s</button>',
			esc_attr( $input_id ),
			esc_html__( 'Key saved', 'cf-summarize' ),
			esc_html__( 'Remove', 'cf-summarize' )
		);
	}

	/** Render OpenAI API Key field (visible only when OpenAI is selected). */
	public function field_openai_api_key(): void {
		$value = (string) get_option( 'cfs_openai_api_key', '' );
		$this->open_provider_row( 'openai' );
		?>
		<input
			type="password"
			name="cfs_openai_api_key"
			id="cfs_openai_api_key"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="sk-..."
			class="regular-text"
			autocomplete="new-password"
		/>
		<?php
		$this->render_key_status( $value, 'cfs_openai_api_key' );
		$this->close_provider_row();
	}

	/** Render OpenAI Model field (visible only when OpenAI is selected). */
	public function field_openai_model(): void {
		$value = (string) get_option( 'cfs_model_openai', '' );
		$this->open_provider_row( 'openai' );
		?>
		<input
			type="text"
			name="cfs_model_openai"
			id="cfs_model_openai"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="e.g. gpt-4o-mini"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Any OpenAI chat model, e.g.', 'cf-summarize' ); ?>
			<code>gpt-4o-mini</code>, <code>gpt-4o</code>, <code>gpt-4-turbo</code>
		</p>
		<?php
		$this->close_provider_row();
	}

	/** Render Anthropic API Key field (visible only when Anthropic is selected). */
	public function field_anthropic_api_key(): void {
		$value = (string) get_option( 'cfs_anthropic_api_key', '' );
		$this->open_provider_row( 'anthropic' );
		?>
		<input
			type="password"
			name="cfs_anthropic_api_key"
			id="cfs_anthropic_api_key"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="sk-ant-..."
			class="regular-text"
			autocomplete="new-password"
		/>
		<?php
		$this->render_key_status( $value, 'cfs_anthropic_api_key' );
		$this->close_provider_row();
	}

	/** Render Anthropic Model field (visible only when Anthropic is selected). */
	public function field_anthropic_model(): void {
		$value = (string) get_option( 'cfs_model_anthropic', '' );
		$this->open_provider_row( 'anthropic' );
		?>
		<input
			type="text"
			name="cfs_model_anthropic"
			id="cfs_model_anthropic"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="e.g. claude-haiku-4-5-20251001"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Any Anthropic messages-API model, e.g.', 'cf-summarize' ); ?>
			<code>claude-haiku-4-5-20251001</code>, <code>claude-sonnet-4-6</code>, <code>claude-opus-4-6</code>
		</p>
		<?php
		$this->close_provider_row();
	}

	/** Render Button Label field. */
	public function field_button_label(): void {
		$value = get_option( 'cfs_button_label', 'Article Overview' );
		?>
		<input
			type="text"
			name="cfs_button_label"
			id="cfs_button_label"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<?php
	}

	/** Render Button Position select field. */
	public function field_button_position(): void {
		$value = get_option( 'cfs_button_position', 'before' );
		?>
		<select name="cfs_button_position" id="cfs_button_position">
			<option value="before" <?php selected( $value, 'before' ); ?>><?php esc_html_e( 'Before content', 'cf-summarize' ); ?></option>
			<option value="after" <?php selected( $value, 'after' ); ?>><?php esc_html_e( 'After content', 'cf-summarize' ); ?></option>
		</select>
		<?php
	}

	/** Render Enable on All Posts by Default checkbox. */
	public function field_enable_all(): void {
		$value = (bool) get_option( 'cfs_enable_all', true );
		?>
		<label for="cfs_enable_all">
			<input
				type="checkbox"
				name="cfs_enable_all"
				id="cfs_enable_all"
				value="1"
				<?php checked( $value ); ?>
			/>
			<?php esc_html_e( 'Show the Article Overview button on all posts by default', 'cf-summarize' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Individual posts can override this via the CF-Summarize meta box in the post editor.', 'cf-summarize' ); ?></p>
		<?php
	}


	/** Render Enable Caching checkbox. */
	public function field_cache_enabled(): void {
		$value = (bool) get_option( 'cfs_cache_enabled', false );
		?>
		<label for="cfs_cache_enabled">
			<input
				type="checkbox"
				name="cfs_cache_enabled"
				id="cfs_cache_enabled"
				value="1"
				<?php checked( $value ); ?>
			/>
			<?php esc_html_e( 'Cache AI summaries to avoid regenerating on every visit', 'cf-summarize' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Disabled by default - every visit generates a fresh summary. Enable to serve the same summary until the cache expires.', 'cf-summarize' ); ?></p>
		<?php
	}


	// ── Sanitization callbacks ────────────────────────────────────────────────

	/**
	 * Sanitize provider option against the registered provider list.
	 *
	 * @param mixed $value Raw input value.
	 * @return string Valid provider slug; falls back to 'openai'.
	 */
	public function sanitize_provider( $value ): string {
		$allowed = array_keys( $this->get_providers() );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'openai';
	}

	/**
	 * Sanitize button position option.
	 *
	 * @param mixed $value Raw input value.
	 * @return string 'before' or 'after'.
	 */
	public function sanitize_position( $value ): string {
		$allowed = [ 'before', 'after' ];
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'before';
	}

	/**
	 * Sanitize a positive integer option.
	 *
	 * @param mixed $value Raw input value.
	 * @return int Non-negative integer.
	 */
	public function sanitize_absint( $value ): int {
		return absint( $value );
	}

	/**
	 * Sanitize a checkbox option.
	 *
	 * Unchecked checkboxes are not submitted in POST data, so $value may be
	 * null; treat that as 0.
	 *
	 * @param mixed $value Raw input value.
	 * @return int 1 if checked, 0 if unchecked/absent.
	 */
	public function sanitize_checkbox( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Sanitize the cache-duration option (seconds).
	 *
	 * @param mixed $value Raw input value.
	 * @return int Non-negative integer (0 = never expire).
	 */
	public function sanitize_cache_duration( $value ): int {
		return absint( $value );
	}
}
