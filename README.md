# FacetWP ElasticPress Integration

Adds ElasticPress support for FacetWP search facets. Supports multiple ElasticPress engines with their own settings (for example `post_type` and `search_fields`). Engine settings use ElasticPress-supported `WP_Query` arguments when registering a search engine.

## Requirements

- [FacetWP](https://facetwp.com/)
- [ElasticPress](https://github.com/10up/ElasticPress) and an Elasticsearch server

## Installation

- Clone or copy this repo into your WordPress plugins directory
- Activate **FacetWP - ElasticPress Integration** in the WordPress admin

## Default search engine

The default search engine searches all registered post types using ElasticPress' configuration in the plugin settings.

## Register new engines

Register additional engines with `FWPEP()->register_engine()` on the `facetwp_elasticpress_register_engine` hook. The first parameter is the engine name; the second accepts engine `WP_Query` arguments.

```php
add_action( 'facetwp_elasticpress_register_engine', function() {
	FWPEP()->register_engine( 'ElasticPress Page search', $array( 'post_type' => 'page' )$ );
} );
```

Args can be in the form of:

```php
$args = array(
	'post_type'     => 'post',
	'search_fields' => array(
		'post_title',
		'post_content',
		'post_excerpt',
		'taxonomies' => array( 'category', 'post_tag' ),
	)
);
```

Registered ElasticPress engines appear in the search engine dropdown on search facets.

## Usage

1. Add a new search facet
2. Select **ElasticPress Default** or one of your custom engines
3. Insert the facet into your site

## Copyright and license

Copyright 2016 Valu Digital Oy  
Copyright 2026 FacetWP, LLC

This project is a fork of the original [Valu FacetWP ElasticPress Integration](https://github.com/MikkoVirenius/vafael) plugin by Valu Digital Oy.

Licensed under the GNU General Public License v3 or later. See [LICENSE](LICENSE) for the full license text.
