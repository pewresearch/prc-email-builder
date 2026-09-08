<?php
/**
 * Pattern: Briefing
 * The standard Pew Research Center newsletter format.
 *
 * @package PRC\Platform\Email_Builder
 */

return array(
	'slug'        => 'prc-newsletter/briefing',
	'title'       => __( 'Briefing', 'prc-email-builder' ),
	'description' => __( 'Standard Pew Research Center briefing newsletter with lead story, and supporting items.', 'prc-email-builder' ),
	'categories'  => array( 'email-campaign' ),
	'postTypes'   => array( 'prc_email_campaign', 'prc_email_txn' ),
	'blockTypes'  => array( 'core/post-content' ),
	'content'     => <<<'PATTERN'
<!-- wp:post-date {"metadata":{"bindings":{"datetime":{"source":"core/post-data","args":{"field":"date"}}}}} /-->
	<!-- wp:heading {"level":2,"style":{"typography":{"fontFamily":"Georgia, serif","fontSize":"28px","fontWeight":"700"},"color":{"text":"#2a2a2a"}}} -->
<h2 class="wp-block-heading has-text-color" style="color:#2a2a2a;font-family:Georgia, serif;font-size:28px;font-weight:700">The Briefing</h2>
<!-- /wp:heading -->

<!-- wp:separator {"style":{"color":{"background":"#2a2a2a"}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background" style="background-color:#2a2a2a;color:#2a2a2a"/>
<!-- /wp:separator -->

<!-- wp:paragraph -->
<p><strong>☀️ Happy Thursday!</strong> <em>The Briefing is your guide to the world of news and information. <a href="https://www.pewresearch.org/sign-up-for-the-briefing/" target="_blank" rel="noreferrer noopener"><strong>Sign up here!</strong></a></em></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>In today's email:</strong></p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Featured story:</strong></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>New from Pew Research Center</strong></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>In other news:</strong></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Looking ahead:</strong></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong>Chart of the week:</strong></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:spacer {"height":"25px"} -->
<div style="height:25px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"level":3,"style":{"typography":{"fontFamily":"Georgia, serif","fontSize":"18px"},"color":{"text":"#2a2a2a"}},"anchor":"featured-story"} -->
<h3 id="featured-story" class="wp-block-heading has-text-color" style="color:#2a2a2a;font-family:Georgia, serif;font-size:18px">🔥 <strong>Featured story</strong></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Featured story ...</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"25px"} -->
<div style="height:25px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"level":3,"style":{"typography":{"fontFamily":"Georgia, serif","fontSize":"18px"},"color":{"text":"#2a2a2a"}},"anchor":"new-from-pew-research-center"} -->
<h3 id="new-from-pew-research-center" class="wp-block-heading has-text-color" style="color:#2a2a2a;font-family:Georgia, serif;font-size:18px">🚨 <strong>New from Pew Research Center</strong></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>New from Pew Research Center ...</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:spacer {"height":"25px"} -->
<div style="height:25px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"level":3,"style":{"typography":{"fontFamily":"Georgia, serif","fontSize":"18px"},"color":{"text":"#2a2a2a"}},"anchor":"in-other-news"} -->
<h3 id="in-other-news" class="wp-block-heading has-text-color" style="color:#2a2a2a;font-family:Georgia, serif;font-size:18px">📌 <strong>In other news</strong></h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading {"level":3,"style":{"typography":{"fontFamily":"Georgia, serif","fontSize":"18px"},"color":{"text":"#2a2a2a"}},"anchor":"looking-ahead"} -->
<h3 id="looking-ahead" class="wp-block-heading has-text-color" style="color:#2a2a2a;font-family:Georgia, serif;font-size:18px">📅 <strong>Looking ahead</strong></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Looking ahead ...</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"25px"} -->
<div style="height:25px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"level":3,"style":{"typography":{"fontFamily":"Georgia, serif","fontSize":"18px"},"color":{"text":"#2a2a2a"}}} -->
<h3 class="wp-block-heading has-text-color" style="color:#2a2a2a;font-family:Georgia, serif;font-size:18px">📊 <strong>Chart of the week</strong></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Chart of the week ...</p>
<!-- /wp:paragraph -->

<!-- wp:image {"sizeSlug":"large","linkDestination":"none","align":"center"} -->
<figure class="wp-block-image aligncenter size-large"><img src="" alt=""/></figure>
<!-- /wp:image -->

<!-- wp:spacer {"height":"25px"} -->
<div style="height:25px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph -->
<p><em>👋 That's all for this week.</em></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The Briefing is compiled by Pew Research Center staff, including Naomi Forman-Katz, Jacob Liedke, Christopher St. Aubin, Luxuan Wang, Emily Tomasik, Joanne Haner, and Mary Randolph. It is edited by Michael Lipka and copy edited by .</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><em>Do you like this newsletter? Email us at <a href="mailto:journalism@pewresearch.org">journalism@pewresearch.org</a> or fill out this <a href="https://us1.list-manage.com/survey?u=434f5d1199912232d416897e4&amp;id=449f3c3d35&amp;attribution=false" target="_blank" rel="noreferrer noopener">two-question survey</a> to tell us what you think</em>.</p>
<!-- /wp:paragraph -->
PATTERN
	,
);
