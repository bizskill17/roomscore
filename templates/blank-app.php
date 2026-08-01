<?php if (!defined('ABSPATH')) exit; ?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?><meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
</head>
<body <?php body_class('hrim-blank-body'); ?>><?php wp_body_open(); ?>
<?php while (have_posts()): the_post(); the_content(); endwhile; ?>
<?php wp_footer(); ?></body></html>
