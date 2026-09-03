<?php
/**
 * The rig's whole theme. Deliberately bare: the assertion is about WHERE the
 * block lands, and a document with nothing else in it makes that unambiguous.
 *
 * @package Citecue
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<main>
<?php
while ( have_posts() ) {
	the_post();
	echo '<h1>' . esc_html( get_the_title() ) . '</h1>';
	the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
