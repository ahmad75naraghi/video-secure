<div class="falnic-preview-container" data-falnic-preview id="<?php echo esc_attr( $args['html_id'] . '-wrap' ); ?>">
	<div class="falnic-preview-video-wrap">
		<video
			id="<?php echo esc_attr( $args['html_id'] ); ?>"
			class="falnic-preview-video falnic-preview-hidden-video"
			playsinline
			preload="metadata"
			controlsList="nodownload noplaybackrate nofullscreen"
			disablePictureInPicture
			disableRemotePlayback
			oncontextmenu="return false;"
			tabindex="-1"
		></video>
		<canvas class="falnic-preview-canvas" id="<?php echo esc_attr( $args['html_id'] . '-canvas' ); ?>" aria-hidden="true"></canvas>
		<div class="falnic-preview-loading" id="<?php echo esc_attr( $args['html_id'] . '-loading' ); ?>">
			<span class="falnic-preview-spinner"></span>
			<span><?php esc_html_e( 'در حال بارگذاری ویدیو…', 'falnic-secure-hls' ); ?></span>
		</div>
		<div class="falnic-preview-error" id="<?php echo esc_attr( $args['html_id'] . '-error' ); ?>" style="display:none"></div>
	</div>
	<script type="application/json" class="falnic-player-config"><?php echo $args['config_json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* اعمال شده است. ?></script>
</div>
