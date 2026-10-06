<?php
/**
 * Block patterns for the recurring section shapes.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers the patterns an editor composes pages from.
 *
 * Each pattern is ordinary core blocks carrying this theme's own class names,
 * so inserting one produces exactly the markup the stylesheet already targets.
 * Nothing here is locked: once inserted, every heading, paragraph and button is
 * editable like any other block, and sections can be reordered or deleted.
 *
 * Patterns rather than reusable blocks on purpose. A reusable block is a single
 * shared instance — editing one edits every page that uses it, which is wrong
 * for a hero. A pattern is a starting point that becomes the page's own.
 */
function threebest_register_patterns(): void {
	if ( ! function_exists( 'register_block_pattern_category' ) ) {
		return;
	}

	register_block_pattern_category(
		'threebest',
		array( 'label' => __( '3Best sections', '3best' ) )
	);

	$patterns = array(
		'page-hero'      => array(
			'title'       => __( 'Page hero', '3best' ),
			'description' => __( 'Eyebrow, headline, short lead and an image, side by side.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-page-hero"} --><div class="wp-block-group tb-page-hero">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:group {"className":"tb-page-hero__grid"} --><div class="wp-block-group tb-page-hero__grid">'
				. '<!-- wp:group {"className":"tb-page-hero__inner"} --><div class="wp-block-group tb-page-hero__inner">'
				. '<!-- wp:paragraph {"className":"tb-eyebrow"} --><p class="tb-eyebrow">' . esc_html__( 'Section label', '3best' ) . '</p><!-- /wp:paragraph -->'
				. '<!-- wp:heading {"level":1} --><h1>' . esc_html__( 'A clear headline for this page', '3best' ) . '</h1><!-- /wp:heading -->'
				. '<!-- wp:paragraph {"className":"tb-page-hero__lead"} --><p class="tb-page-hero__lead">' . esc_html__( 'One or two sentences explaining what this page is for.', '3best' ) . '</p><!-- /wp:paragraph -->'
				. '</div><!-- /wp:group -->'
				. '<!-- wp:group {"className":"tb-page-hero__media"} --><div class="wp-block-group tb-page-hero__media">'
				. '<!-- wp:threebest/media {"imageKey":"page-about","ratio":"landscape","radius":"xl"} /-->'
				. '</div><!-- /wp:group -->'
				. '</div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->',
		),
		'section-header' => array(
			'title'       => __( 'Section header', '3best' ),
			'description' => __( 'Centred eyebrow, heading and lead, for the top of a section.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-section"} --><div class="wp-block-group tb-section">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:group {"className":"tb-section__head tb-section__head--center"} --><div class="wp-block-group tb-section__head tb-section__head--center">'
				. '<!-- wp:paragraph {"className":"tb-eyebrow"} --><p class="tb-eyebrow">' . esc_html__( 'Section label', '3best' ) . '</p><!-- /wp:paragraph -->'
				. '<!-- wp:heading --><h2>' . esc_html__( 'What this section covers', '3best' ) . '</h2><!-- /wp:heading -->'
				. '<!-- wp:paragraph {"className":"tb-section__lead"} --><p class="tb-section__lead">' . esc_html__( 'A sentence of context under the heading.', '3best' ) . '</p><!-- /wp:paragraph -->'
				. '</div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->',
		),
		'benefits'       => array(
			'title'       => __( 'Benefits list', '3best' ),
			'description' => __( 'A checked list of reasons, each with a title and a line of detail.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-section"} --><div class="wp-block-group tb-section">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:heading {"className":"tb-value__title"} --><h2 class="tb-value__title">' . esc_html__( 'Why this matters', '3best' ) . '</h2><!-- /wp:heading -->'
				. '<!-- wp:threebest/icon-list {"variant":"value","items":"'
					. esc_attr__( 'First reason | A line explaining it.', '3best' ) . '\n'
					. esc_attr__( 'Second reason | A line explaining it.', '3best' ) . '"} /-->'
				. '</div><!-- /wp:group --></div><!-- /wp:group -->',
		),
		'steps'          => array(
			'title'       => __( 'Numbered steps', '3best' ),
			'description' => __( 'A numbered process, three or more steps.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-section"} --><div class="wp-block-group tb-section">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:threebest/icon-list {"variant":"steps","items":"'
					. esc_attr__( 'First step | What happens here.', '3best' ) . '\n'
					. esc_attr__( 'Second step | What happens here.', '3best' ) . '\n'
					. esc_attr__( 'Third step | What happens here.', '3best' ) . '"} /-->'
				. '</div><!-- /wp:group --></div><!-- /wp:group -->',
		),
		'faq'            => array(
			'title'       => __( 'FAQ', '3best' ),
			'description' => __( 'Questions and answers in the site\'s accordion style.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-section"} --><div class="wp-block-group tb-section">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:heading --><h2>' . esc_html__( 'Frequently asked questions', '3best' ) . '</h2><!-- /wp:heading -->'
				. '<!-- wp:details {"className":"tb-faq__item"} --><details class="wp-block-details tb-faq__item">'
				. '<summary class="tb-faq__question">' . esc_html__( 'A question someone actually asks', '3best' ) . '</summary>'
				. '<!-- wp:paragraph {"className":"tb-faq__answer"} --><p class="tb-faq__answer">' . esc_html__( 'A direct answer, two or three sentences at most.', '3best' ) . '</p><!-- /wp:paragraph -->'
				. '</details><!-- /wp:details -->'
				. '</div><!-- /wp:group --></div><!-- /wp:group -->',
		),
		'cta'            => array(
			'title'       => __( 'Call to action', '3best' ),
			'description' => __( 'A closing heading and button.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-section tb-section--alt"} --><div class="wp-block-group tb-section tb-section--alt">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:group {"className":"tb-section__head tb-section__head--center"} --><div class="wp-block-group tb-section__head tb-section__head--center">'
				. '<!-- wp:heading --><h2>' . esc_html__( 'Ready to start?', '3best' ) . '</h2><!-- /wp:heading -->'
				. '</div><!-- /wp:group -->'
				. '<!-- wp:buttons {"className":"tb-cta"} --><div class="wp-block-buttons tb-cta">'
				. '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( home_url( '/#find' ) ) . '">' . esc_html__( 'Find My 3 Best', '3best' ) . '</a></div><!-- /wp:button -->'
				. '</div><!-- /wp:buttons -->'
				. '</div><!-- /wp:group --></div><!-- /wp:group -->',
		),
		'prose'          => array(
			'title'       => __( 'Informational text', '3best' ),
			'description' => __( 'A block of body copy with a heading, in the reading column.', '3best' ),
			'content'     => '<!-- wp:group {"className":"tb-page-body"} --><div class="wp-block-group tb-page-body">'
				. '<!-- wp:group {"className":"container"} --><div class="wp-block-group container">'
				. '<!-- wp:group {"className":"tb-prose"} --><div class="wp-block-group tb-prose">'
				. '<!-- wp:heading --><h2>' . esc_html__( 'A heading for this passage', '3best' ) . '</h2><!-- /wp:heading -->'
				. '<!-- wp:paragraph --><p>' . esc_html__( 'Body copy. Write as much as the reader needs and no more.', '3best' ) . '</p><!-- /wp:paragraph -->'
				. '</div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->',
		),
	);

	foreach ( $patterns as $slug => $pattern ) {
		register_block_pattern(
			'threebest/' . $slug,
			array(
				'title'       => $pattern['title'],
				'description' => $pattern['description'],
				'categories'  => array( 'threebest' ),
				'content'     => $pattern['content'],
			)
		);
	}
}
add_action( 'init', 'threebest_register_patterns' );
