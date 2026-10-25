<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<?php \Falnic\SecureHLS\Assets::print_tags(); ?>
	<style>
		html, body { margin: 0; padding: 0; height: 100%; background: #000; }
		body { display: flex; align-items: center; justify-content: center; }
	</style>
</head>
<body>
	<?php echo $player; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- مارک‌آپ امن تولیدشده. ?>
</body>
</html>
