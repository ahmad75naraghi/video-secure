<div class="falnic-secure-container" data-falnic-player>
	<video
		id="<?php echo esc_attr( $args['html_id'] ); ?>"
		class="falnic-hidden-video"
		playsinline
		preload="metadata"
		controlsList="nodownload noplaybackrate"
		disablePictureInPicture
		disableRemotePlayback
		oncontextmenu="return false;"
	></video>
	<script type="application/json" class="falnic-player-config"><?php echo $args['config_json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* اعمال شده است. ?></script>
</div>
