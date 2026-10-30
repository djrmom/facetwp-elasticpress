<?php
/*
Plugin Name: FacetWP - ElasticPress Integration
Plugin URI: https://facetwp.com
Description: Adds ElasticPress search provider for FacetWP. Supports multiple engines.
Version: 0.1.0
Requires PHP: 7.4
Author: FacetWP
Author URI: https://facetwp.com

Copyright 2016 Valu Digital Oy
Copyright 2026 FacetWP, LLC

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 3
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, see <http://www.gnu.org/licenses/>.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// setup variables
define( 'FACETWP_ELASTICPRESS_VERSION', '0.1.1' );
define( 'FACETWP_ELASTICPRESS_DIR', __DIR__ );
define( 'FACETWP_ELASTICPRESS_BASENAME', plugin_basename( __FILE__ ) );

require_once FACETWP_ELASTICPRESS_DIR . '/class-facetwp-elasticpress-integration.php';
