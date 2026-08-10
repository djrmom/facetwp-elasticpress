<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class FacetWP_ElasticPress_Integration {

	public $engines;

	/** Refers to a single instance of this class. */
	private static $instance = null;

	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	/**
	 * Creates or returns an instance of this class.
	 *
	 * @return  FacetWP_ElasticPress_Integration A single instance of this class.
	 */
	public static function instance() {

		// Check that dependencies are installed and activated
		if ( ! isset( self::$instance ) ) {

            self::$instance = new self;

        }

        return self::$instance;

	} // end instance;

	/**
	 * FacetWP_ElasticPress_Integration constructor.
	 */
	private function __construct() {

		add_action( 'init', array( $this, 'init' )  );

	}

	/**
	 * initialize plugin
	 */
	function init() {

		// Soft deps: FacetWP + ElasticPress must both be loaded.
		if ( ! function_exists( 'FWP' ) || ! defined( 'EP_VERSION' ) ) {
			return;
		}

		add_filter( 'facetwp_search_query_args', array( $this, 'default_search' ), 10, 2 );
		add_filter( 'facetwp_facet_filter_posts', array( $this, 'search_facet' ), 10, 2 );
		add_filter( 'facetwp_facet_search_engines', array( $this, 'search_engines' ) );

		// Register default search engine
		$this->register_engine( 'ElasticPress Default' );

		// action to register additional engines
		do_action( 'facetwp_elasticpress_register_engine' );

	}

	/**
	 * Register a new engine
	 *
	 * @param $engine_name
	 * @param $args
	 *
	 * @return bool
	 */
	function register_engine( $engine_name, $args = array() ) {

		if ( ! is_array( $this->engines ) ) {
			$this->engines = array();
		}

		$args['label'] = $engine_name;

		$engine_key = 'elp_' . sanitize_key( $engine_name );

		if ( empty( $engine_key ) || strlen( $engine_key ) > 40 ) {
			return false;
		};

		$engine_properties = $this->set_engine_properties( $engine_key, $args );

		$this->engines[ $engine_key ] = $engine_properties;

		/**
		 * Fires after a engine is registered.
		 *
		 *
		 * @param string $engine_key Engine name.
		 * @param $engine_properties Arguments used to register the engine.
		 */
		do_action( 'facetwp_elasticpress_engine_registered', $engine_key, $engine_properties );

		return $engine_properties;

	}

	/**
	 * Set search engine properties
	 *
	 * @param string $engine_key
	 * @param array $args
	 *
	 * @return array
	 */
	function set_engine_properties( $engine_key = '', $args = array() ) {

		$args = wp_parse_args( $args );

		/**
		 * Filter the arguments for registering a new engine.
		 *
		 * @since 0.1.0
		 *
		 * @param array $args Array of arguments for registering a new elasticpress engine.
		 * @param string $engine_key Engine key.
		 */
		$args = apply_filters( 'facetwp_elasticpress_engine_default_args', $args, $engine_key );

		$defaults = [];  // a search with no added $args will use the elasticpress settings in dashboard

		$args = array_merge( $defaults, $args );

		if ( null === $args['label'] ) {
			$args['label'] = $engine_key;
		}

		return $args;

	}


	/**
	 * Deregister search engine
	 *
	 * @param $engine_key
	 *
	 * @return bool
	 */
	function deregister_engine( $engine_key ) {

		if ( ! is_array( $this->engines ) ) {
			$this->engines = array();
		}

		$engine_key = sanitize_key( $engine_key );

		if ( ! $this->engine_exists( $engine_key ) ) {
			return false;
		}

		unset( $this->engines[ $engine_key ] );

		/**
		 * Fires after a search engine was deregistered.
		 *
		 * @since 0.1.0
		 *
		 * @param string $engine_key Search Engine key.
		 */
		do_action( 'facetwp_elasticpress_engine_deregistered', $engine_key );

		return true;

	}

	/**
	 * Check if engine exists
	 *
	 * @param string $engine_key
	 *
	 * @return bool
	 */
	function engine_exists( $engine_key = '' ) {

		if ( ! $engine_key ) {
			return false;
		}

		$engine_key = sanitize_key( $engine_key );

		return isset( $this->engines[ $engine_key ] );

	}

	/**
	 * Intercept search facets using ElasticPress engine
	 * @since 0.1.0
	 */
	function search_facet( $return, $params ) {

		$facet = $params['facet'];
        $selected_values = $params['selected_values'];
        $selected_values = is_array( $selected_values ) ? $selected_values[0] : $selected_values;
        $engine = $facet['search_engine'] ?? '';

		if ( 'search' == $facet['type'] && 0 === strpos( $engine, 'elp_' ) ) {

			if ( empty( $selected_values ) ) {
				return 'continue';
			}

			$defaults = array(
				's' => $selected_values
			);

			// Merge defaults with engine settings
			$elasticpress_query_args = array_merge( $defaults, $this->engines[ $facet['search_engine'] ] );

			if ( 'no' == $facet['enable_relevance'] ) {
				add_filter( 'facetwp_use_search_relevancy', '__return_false' );
			}

			$elasticpress_query = new WP_Query( $elasticpress_query_args );

			return ( isset( $elasticpress_query->posts ) ) ? wp_list_pluck( $elasticpress_query->posts, 'ID' ) : false;

		}

		return $return;
	}


	/**
	 * Add engines to the search facet
	 */
	function search_engines( $engines ) {

		if ( $this->engines and is_array( $this->engines ) ) {
			foreach ( $this->engines as $engine_key => $engine_properties ) {
				$engines[ $engine_key ] = $engine_properties['label'];
			}
		}

		return $engines;

	}

	/**
	 * bypasses elasticpress if engine is 'WP Default'
	 */
	function default_search( $search_args, $params ) {

		$facet = $params['facet'];
        $engine = $facet['search_engine'] ?? '';

		if ( 'search' == $facet['type'] && empty( $facet['search_engine'] ) ) {
			$search_args['ep_integrate'] = false;
		}

		return $search_args;
	}

}

/**
 * Create a instance of the class
 */
function FWPEP() {
	return FacetWP_ElasticPress_Integration::instance();
}

$fwpep = FWPEP();