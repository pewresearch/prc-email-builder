<?php
// This file is generated. Do not modify it manually.
return array(
	'campaign-email-preview' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-email-builder/campaign-email-preview',
		'version' => '0.1.0',
		'title' => 'Campaign Email Preview',
		'category' => 'theme',
		'description' => 'Renders a phone-width, scrollable live preview of a campaign\'s email HTML.',
		'keywords' => array(
			'email',
			'newsletter',
			'preview',
			'campaign'
		),
		'textdomain' => 'prc-email-builder',
		'attributes' => array(
			'frameWidth' => array(
				'type' => 'number',
				'default' => 375
			),
			'frameHeight' => array(
				'type' => 'number',
				'default' => 560
			),
			'showFade' => array(
				'type' => 'boolean',
				'default' => true
			),
			'showLink' => array(
				'type' => 'boolean',
				'default' => true
			),
			'linkText' => array(
				'type' => 'string',
				'default' => 'Read the latest issue'
			),
			'linkUrl' => array(
				'type' => 'string',
				'default' => ''
			)
		),
		'supports' => array(
			'html' => false,
			'align' => array(
				'wide',
				'full'
			),
			'spacing' => array(
				'margin' => array(
					'top',
					'bottom'
				)
			)
		),
		'usesContext' => array(
			'postId',
			'postType'
		),
		'editorScript' => 'file:./index.js',
		'style' => 'file:./style-index.css',
		'render' => 'file:./render.php'
	),
	'campaign-query' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-email-builder/campaign-query',
		'version' => '0.1.0',
		'title' => 'Campaign Query',
		'category' => 'theme',
		'description' => 'Editor script that registers the Newsletter Campaigns Query Loop variation.',
		'textdomain' => 'prc-email-builder',
		'editorScript' => 'file:./index.js'
	),
	'latest-campaign-query' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'prc-email-builder/latest-campaign-query',
		'version' => '0.1.0',
		'title' => 'Latest Campaign Query',
		'category' => 'theme',
		'description' => 'Editor script that registers the Latest Newsletter Preview Query Loop variation.',
		'textdomain' => 'prc-email-builder',
		'editorScript' => 'file:./index.js'
	)
);
