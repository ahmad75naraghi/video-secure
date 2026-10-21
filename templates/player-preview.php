<div class="falnic-preview-container" data-falnic-preview id="<?php echo esc_attr( $args['html_id'] . '-wrap' ); ?>">
	<div class="falnic-preview-video-wrap">
		<video
			id="<?php echo esc_attr( $args['html_id'] ); ?>"
			class="falnic-preview-video"
			playsinline
			preload="metadata"
			controls
			controlsList="nodownload noplaybackrate"
			disablePictureInPicture
			disableRemotePlayback
			oncontextmenu="return false;"
		></video>
		<div class="falnic-preview-watermark" id="<?php echo esc_attr( $args['html_id'] . '-wm' ); ?>" aria-hidden="true">
			<span class="falnic-wm-line1"></span>
			<span class="falnic-wm-line2"></span>
		</div>
		<div class="falnic-preview-loading" id="<?php echo esc_attr( $args['html_id'] . '-loading' ); ?>">
			<span class="falnic-preview-spinner"></span>
			<span><?php esc_html_e( 'در حال بارگذاری ویدیو…', 'falnic-secure-hls' ); ?></span>
		</div>
		<div class="falnic-preview-error" id="<?php echo esc_attr( $args['html_id'] . '-error' ); ?>" style="display:none"></div>
	</div>
	<script type="application/json" class="falnic-player-config"><?php echo $args['config_json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* اعمال شده است. ?></script>
</div>
