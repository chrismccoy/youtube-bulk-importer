<?php
/**
 * Import page markup.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ytbi">
	<h1><?php esc_html_e( 'YouTube Import', 'ytbi' ); ?></h1>

	<?php if ( ! $has_key ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'No YouTube API key set.', 'ytbi' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open settings', 'ytbi' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div id="ytbi-notice" class="notice notice-error" hidden><p></p></div>

	<form id="ytbi-search" class="ytbi-search">
		<label class="screen-reader-text" for="ytbi-q"><?php esc_html_e( 'Search', 'ytbi' ); ?></label>
		<input type="search" id="ytbi-q" name="q" required placeholder="<?php esc_attr_e( 'Keyword, @handle, or playlist URL', 'ytbi' ); ?>">

		<select name="type" aria-label="<?php esc_attr_e( 'Search type', 'ytbi' ); ?>">
			<option value="keyword"><?php esc_html_e( 'Keyword', 'ytbi' ); ?></option>
			<option value="channel"><?php esc_html_e( 'Channel', 'ytbi' ); ?></option>
			<option value="playlist"><?php esc_html_e( 'Playlist', 'ytbi' ); ?></option>
		</select>

		<select name="order" aria-label="<?php esc_attr_e( 'Order', 'ytbi' ); ?>">
			<option value="relevance"><?php esc_html_e( 'Relevance', 'ytbi' ); ?></option>
			<option value="date"><?php esc_html_e( 'Newest', 'ytbi' ); ?></option>
			<option value="viewCount"><?php esc_html_e( 'Most viewed', 'ytbi' ); ?></option>
			<option value="rating"><?php esc_html_e( 'Top rated', 'ytbi' ); ?></option>
			<option value="title"><?php esc_html_e( 'Title', 'ytbi' ); ?></option>
		</select>

		<select name="published" aria-label="<?php esc_attr_e( 'Published within', 'ytbi' ); ?>">
			<option value="any"><?php esc_html_e( 'Any time', 'ytbi' ); ?></option>
			<option value="hour"><?php esc_html_e( 'Last hour', 'ytbi' ); ?></option>
			<option value="day"><?php esc_html_e( 'Last day', 'ytbi' ); ?></option>
			<option value="week"><?php esc_html_e( 'Last week', 'ytbi' ); ?></option>
			<option value="month"><?php esc_html_e( 'Last month', 'ytbi' ); ?></option>
			<option value="year"><?php esc_html_e( 'Last year', 'ytbi' ); ?></option>
		</select>



		<button type="submit" class="button button-primary" disabled><?php esc_html_e( 'Search', 'ytbi' ); ?></button>
	</form>

	<div id="ytbi-toolbar" class="ytbi-toolbar" hidden>
		<label><input type="checkbox" id="ytbi-select-all"> <?php esc_html_e( 'Select all', 'ytbi' ); ?></label>
		<select id="ytbi-category" aria-label="<?php esc_attr_e( 'Category', 'ytbi' ); ?>"></select>
		<select id="ytbi-status" aria-label="<?php esc_attr_e( 'Post status', 'ytbi' ); ?>"></select>
		<button type="button" id="ytbi-import" class="button button-primary"><?php esc_html_e( 'Import selected', 'ytbi' ); ?></button>
		<span id="ytbi-progress" class="ytbi-progress" aria-live="polite"></span>
		<button type="button" id="ytbi-hide-imported" class="button" hidden><?php esc_html_e( 'Hide imported', 'ytbi' ); ?></button>
		<button type="button" id="ytbi-clear" class="button"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> <?php esc_html_e( 'Clear results', 'ytbi' ); ?></button>
		<span class="ytbi-views" role="group" aria-label="<?php esc_attr_e( 'Layout', 'ytbi' ); ?>">
			<button type="button" class="button ytbi-view" data-view="grid" aria-pressed="true" title="<?php esc_attr_e( 'Grid', 'ytbi' ); ?>"><span class="dashicons dashicons-grid-view" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Grid', 'ytbi' ); ?></span></button>
			<button type="button" class="button ytbi-view" data-view="list" aria-pressed="false" title="<?php esc_attr_e( 'List', 'ytbi' ); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'List', 'ytbi' ); ?></span></button>
		</span>
	</div>

	<div id="ytbi-results" class="ytbi-results"></div>

	<p class="ytbi-more">
		<button type="button" id="ytbi-more" class="button button-hero" hidden><?php esc_html_e( 'Load more videos', 'ytbi' ); ?></button>
	</p>

	<template id="ytbi-card">
		<div class="ytbi-card" data-state="idle">
			<button type="button" class="ytbi-thumb" aria-pressed="false">
				<img alt="" loading="lazy">
				<span class="ytbi-duration"></span>
				<span class="ytbi-tick dashicons dashicons-yes-alt" aria-hidden="true"></span>
			</button>
			<div class="ytbi-body">
				<h3 class="ytbi-card-title"></h3>
				<p class="ytbi-meta"></p>
				<div class="ytbi-fields">
					<label><span><?php esc_html_e( 'Title', 'ytbi' ); ?></span><input type="text" class="ytbi-title"></label>
					<label class="ytbi-desc-field"><span><?php esc_html_e( 'Description', 'ytbi' ); ?></span><textarea class="ytbi-desc" rows="4"></textarea></label>
					<label class="ytbi-tags-field"><span><?php esc_html_e( 'Tags', 'ytbi' ); ?></span><input type="text" class="ytbi-tags" placeholder="<?php esc_attr_e( 'Comma separated', 'ytbi' ); ?>"></label>
				</div>
				<div class="ytbi-actions">
					<label class="ytbi-select-label"><input type="checkbox" class="ytbi-select"> <?php esc_html_e( 'Select', 'ytbi' ); ?></label>
					<button type="button" class="button button-small ytbi-edit"><span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e( 'Edit', 'ytbi' ); ?></button>
				</div>
				<div class="ytbi-status">
					<span class="ytbi-state"></span>
					<a class="ytbi-link" hidden></a>
					<button type="button" class="button button-small ytbi-retry" hidden><?php esc_html_e( 'Retry', 'ytbi' ); ?></button>
				</div>
			</div>
		</div>
	</template>

	<dialog id="ytbi-dialog" class="ytbi-dialog" aria-labelledby="ytbi-dialog-title">
		<form method="dialog">
			<h2 id="ytbi-dialog-title"><?php esc_html_e( 'Edit video before import', 'ytbi' ); ?></h2>
			<div class="ytbi-player"></div>
			<div class="ytbi-dialog-fields"></div>
			<p class="ytbi-dialog-actions">
				<a class="ytbi-yt-link" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open on YouTube', 'ytbi' ); ?></a>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Done', 'ytbi' ); ?></button>
			</p>
		</form>
	</dialog>

	<script type="application/json" id="ytbi-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
</div>
