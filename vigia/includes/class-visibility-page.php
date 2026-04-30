<?php
/**
 * AI Visibility Page
 *
 * Registers the AI Visibility submenu (first item) and renders
 * the analysis interface with URL selector and results area.
 *
 * @package VigIA
 * @since   1.8.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility Page class
 */
class VigIA_Visibility_Page {

	/**
	 * Register submenu page.
	 * Called before Analytics and Extras to appear first in the menu.
	 */
	public static function register_menu() {
		add_submenu_page(
			'vigia',
			__( 'AI Visibility Score', 'vigia' ),
			__( 'AI Visibility', 'vigia' ),
			'manage_options',
			'vigia-visibility',
			array( __CLASS__, 'render_page' ),
			0 // Position: first submenu item.
		);
	}

	/**
	 * Render the AI Visibility page.
	 */
	public static function render_page() {
		$home_url = home_url( '/' );
		?>
		<div class="wrap vigia-wrap vigia-vis-wrap">
			<h1>
				<span class="vigia-title-icon">
					<img src="<?php echo esc_url( VIGIA_PLUGIN_URL . 'assets/images/icon-header-color.png' ); ?>" alt="" width="32" height="32">
				</span>
				<?php echo esc_html__( 'AI Visibility Score', 'vigia' ); ?>
			</h1>

			<div class="vigia-vis-layout">
				<div class="vigia-vis-main">
					<?php // Intro text. ?>
					<div class="vigia-vis-intro">
						<p><?php esc_html_e( 'Analyze how well your site is optimized to be discovered, understood, and used by artificial intelligences. Get a score out of 100 and actionable recommendations to improve your AI visibility.', 'vigia' ); ?></p>
					</div>

					<?php // URL selector. ?>
					<div class="vigia-vis-url-selector">
						<div class="vigia-vis-url-field">
							<label for="vigia-vis-url-input"><?php esc_html_e( 'URL to analyze:', 'vigia' ); ?></label>
							<div class="vigia-vis-url-wrapper">
								<input
									type="text"
									id="vigia-vis-url-input"
									class="regular-text"
									value="<?php echo esc_url( $home_url ); ?>"
									placeholder="<?php esc_attr_e( 'Search for a page or post...', 'vigia' ); ?>"
									autocomplete="off"
								/>
								<div id="vigia-vis-autocomplete" class="vigia-vis-autocomplete" style="display:none;"></div>
							</div>
							<button type="button" id="vigia-vis-analyze-btn" class="button button-primary">
								<?php esc_html_e( 'Analyze', 'vigia' ); ?>
							</button>
							<button type="button" id="vigia-vis-home-btn" class="button" title="<?php esc_attr_e( 'Analyze homepage', 'vigia' ); ?>">
								<span class="dashicons dashicons-admin-home"></span>
							</button>
						</div>
						<p class="description">
							<?php esc_html_e( 'Type to search for any published page, post, or custom content. The homepage is analyzed by default.', 'vigia' ); ?>
						</p>
					</div>

					<?php // Results area (populated via AJAX). ?>
					<div id="vigia-vis-results" style="display:none;"></div>

					<?php // Scoring reference (collapsible). ?>
					<div class="vigia-vis-reference">
						<button type="button" id="vigia-vis-reference-toggle" class="vigia-vis-reference-toggle" aria-expanded="false">
							<span class="vigia-vis-toggle-icon">&#9654;</span>
							<?php esc_html_e( 'Scoring reference', 'vigia' ); ?>
						</button>
						<div id="vigia-vis-reference-content" class="vigia-vis-reference-content" style="display:none;">
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Category', 'vigia' ); ?></th>
										<th><?php esc_html_e( 'Max points', 'vigia' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><?php esc_html_e( 'Access and AI discovery', 'vigia' ); ?></td>
										<td>37</td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Structured data and semantic context', 'vigia' ); ?></td>
										<td>25</td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Content structure and readability', 'vigia' ); ?></td>
										<td>20</td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'AI interaction and distribution', 'vigia' ); ?></td>
										<td>8</td>
									</tr>
									<tr>
										<td><?php esc_html_e( 'Access performance', 'vigia' ); ?></td>
										<td>10</td>
									</tr>
								</tbody>
								<tfoot>
									<tr>
										<th><strong><?php esc_html_e( 'Total', 'vigia' ); ?></strong></th>
										<th><strong>100</strong></th>
									</tr>
								</tfoot>
							</table>

							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Score', 'vigia' ); ?></th>
										<th><?php esc_html_e( 'Grade', 'vigia' ); ?></th>
										<th><?php esc_html_e( 'Verdict', 'vigia' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr><td>&ge; 90</td><td>A+</td><td><?php esc_html_e( 'Excellent', 'vigia' ); ?></td></tr>
									<tr><td>&ge; 80</td><td>A</td><td><?php esc_html_e( 'Very good', 'vigia' ); ?></td></tr>
									<tr><td>&ge; 65</td><td>B</td><td><?php esc_html_e( 'Good', 'vigia' ); ?></td></tr>
									<tr><td>&ge; 50</td><td>C</td><td><?php esc_html_e( 'Fair', 'vigia' ); ?></td></tr>
									<tr><td>&ge; 35</td><td>D</td><td><?php esc_html_e( 'Poor', 'vigia' ); ?></td></tr>
									<tr><td>&ge; 20</td><td>E</td><td><?php esc_html_e( 'Very poor', 'vigia' ); ?></td></tr>
									<tr><td>&lt; 20</td><td>F</td><td><?php esc_html_e( 'Critical', 'vigia' ); ?></td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<aside class="vigia-vis-sidebar">
					<?php
					if ( class_exists( 'Vigia_Promo_Banner' ) ) {
						$promo_banner = new Vigia_Promo_Banner( 'vigia', 'vigia', 'vigia' );
						$promo_banner->render( 'vertical' );
					}
					?>
				</aside>
			</div>
		</div>
		<?php
	}
}