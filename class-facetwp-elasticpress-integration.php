<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class FacetWP_ElasticPress_Integration {

	public array $engines = [];

	/** Search keywords from the native WordPress search page. */
	public string $keywords = '';

	/** Cached ElasticPress results for the native search page. */
	public array $posts = [];

	/**
	 * True until FacetWP's render query has been short-circuited.
	 *
	 * The main query runs after that, so pre_get_posts can tell the two apart.
	 */
	public bool $first_run = true;

	/** Refers to a single instance of this class. */
	private static ?self $instance = null;

	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	/**
	 * Creates or returns an instance of this class.
	 *
	 * @return FacetWP_ElasticPress_Integration A single instance of this class.
	 */
	public static function instance(): self {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;

	}

	/**
	 * FacetWP_ElasticPress_Integration constructor.
	 */
	private function __construct() {

		add_action( 'init', [ $this, 'init' ] );

	}

	/**
	 * initialize plugin
	 */
	public function init(): void {

		// Soft deps: FacetWP + ElasticPress must both be loaded.
		if ( ! function_exists( 'FWP' ) || ! defined( 'EP_VERSION' ) ) {
			return;
		}

		add_filter( 'facetwp_search_query_args', [ $this, 'default_search' ], 10, 2 );
		add_filter( 'facetwp_facet_filter_posts', [ $this, 'search_facet' ], 10, 2 );
		add_filter( 'facetwp_facet_search_engines', [ $this, 'search_engines' ] );
		add_filter( 'ep_ajax_wp_query_integration', [ $this, 'integrate_facetwp_refresh' ] );

		// Native WordPress search page. The setting is read inside is_main_query()
		// because FWP()->helper does not exist until FacetWP's init at priority 20.
		add_filter( 'facetwp_is_main_query', [ $this, 'is_main_query' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'pre_get_posts' ], 1000 );
		add_filter( 'posts_pre_query', [ $this, 'posts_pre_query' ], 10, 2 );
		add_filter( 'posts_results', [ $this, 'posts_results' ], 10, 2 );
		add_filter( 'facetwp_settings_admin', [ $this, 'admin_settings' ], 10, 2 );

		// Register default search engine
		$this->register_engine( 'ElasticPress Default' );

		// action to register additional engines
		do_action( 'facetwp_elasticpress_register_engine' );

	}

	/**
	 * Register a new engine
	 *
	 * @param string $engine_name
	 * @param array  $args
	 *
	 * @return array|false
	 */
	public function register_engine( string $engine_name, array $args = [] ) {

		$args['label'] = $engine_name;

		$engine_key = 'elp_' . sanitize_key( $engine_name );

		if ( empty( $engine_key ) || strlen( $engine_key ) > 40 ) {
			return false;
		}

		$engine_properties = $this->set_engine_properties( $engine_key, $args );

		$this->engines[ $engine_key ] = $engine_properties;

		/**
		 * Fires after a engine is registered.
		 *
		 * @param string $engine_key Engine name.
		 * @param array  $engine_properties Arguments used to register the engine.
		 */
		do_action( 'facetwp_elasticpress_engine_registered', $engine_key, $engine_properties );

		return $engine_properties;

	}

	/**
	 * Set search engine properties
	 *
	 * @param string $engine_key
	 * @param array  $args
	 *
	 * @return array
	 */
	public function set_engine_properties( string $engine_key = '', array $args = [] ): array {

		$args = wp_parse_args( $args );

		/**
		 * Filter the arguments for registering a new engine.
		 *
		 * @since 0.1.0
		 *
		 * @param array  $args Array of arguments for registering a new elasticpress engine.
		 * @param string $engine_key Engine key.
		 */
		$args = apply_filters( 'facetwp_elasticpress_engine_default_args', $args, $engine_key );

		// Missing or null label falls back to engine key; empty string is kept.
		$args['label'] ??= $engine_key;

		return $args;

	}

	/**
	 * Deregister search engine
	 *
	 * @param string $engine_key
	 *
	 * @return bool
	 */
	public function deregister_engine( string $engine_key ): bool {

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
	public function engine_exists( string $engine_key = '' ): bool {

		if ( ! $engine_key ) {
			return false;
		}

		$engine_key = sanitize_key( $engine_key );

		return isset( $this->engines[ $engine_key ] );

	}

	/**
	 * Intercept search facets using ElasticPress engine
	 *
	 * @since 0.1.0
	 */
	public function search_facet( $return, $params ) {

		$facet           = $params['facet'] ?? [];
		$selected_values = $params['selected_values'] ?? '';
		$selected_values = is_array( $selected_values ) ? $selected_values[0] : $selected_values;
		$engine          = $facet['search_engine'] ?? '';

		if ( 'search' === ( $facet['type'] ?? '' ) && 0 === strpos( $engine, 'elp_' ) ) {

			if ( empty( $selected_values ) ) {
				return 'continue';
			}

			$defaults = [
				's' => $selected_values,
			];

			// Merge defaults with engine settings
			$elasticpress_query_args = [ ...$defaults, ...$this->engines[ $facet['search_engine'] ] ];

			if ( 'no' === ( $facet['enable_relevance'] ?? '' ) ) {
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
	public function search_engines( array $engines ): array {

		if ( $this->engines ) {
			foreach ( $this->engines as $engine_key => $engine_properties ) {
				$engines[ $engine_key ] = $engine_properties['label'];
			}
		}

		return $engines;

	}

	/**
	 * bypasses elasticpress if engine is 'WP Default'
	 */
	public function default_search( array $search_args, array $params ): array {

		$facet = $params['facet'] ?? [];

		if ( 'search' === ( $facet['type'] ?? '' ) && empty( $facet['search_engine'] ) ) {
			$search_args['ep_integrate'] = false;
		}

		return $search_args;
	}

	/**
	 * Allow ElasticPress to integrate FacetWP admin-ajax refreshes.
	 *
	 * FacetWP copies the JSON body into $_POST before the search query runs.
	 *
	 * @param bool $integrate Whether admin-ajax queries are integrated.
	 * @return bool
	 */
	public function integrate_facetwp_refresh( bool $integrate ): bool {

		if ( $integrate ) {
			return true;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';

		return 'facetwp_refresh' === $action;

	}

	/**
	 * Run and cache an ElasticPress search for the native WordPress search page.
	 *
	 * Fires from FacetWP before it snapshots the main query, so using_elasticpress
	 * is inherited by the bucket query and the render query.
	 *
	 * @param bool     $is_main_query Whether FacetWP treats this as its main query.
	 * @param WP_Query $query         Query being checked.
	 * @return bool
	 */
	public function is_main_query( bool $is_main_query, $query ): bool {

		if ( 'yes' === FWP()->helper->get_setting( 'elp_disable_default_search', 'no' ) ) {
			return $is_main_query;
		}

		$keywords = $query->get( 's' );

		if ( $is_main_query && $query->is_main_query() && $query->is_search() && is_string( $keywords ) && '' !== $keywords ) {
			$args           = stripslashes_deep( $this->get_valid_args( $query ) );
			$this->keywords = (string) $args['s'];
			$this->posts    = $this->run_query( $args );
			$query->set( 'using_elasticpress', true );
		}

		return $is_main_query;

	}

	/**
	 * Whitelist query arguments ElasticPress can apply to the native search.
	 *
	 * @param WP_Query $query Main search query.
	 * @return array
	 */
	public function get_valid_args( $query ): array {

		$output = [];

		$valid = [
			's', 'post__in', 'post__not_in', 'post_type', 'post_status',
			'tax_query', 'meta_query', 'date_query', 'order', 'orderby',
		];

		foreach ( $valid as $arg ) {
			$val = $query->get( $arg );
			if ( ! empty( $val ) || 's' === $arg ) {
				$output[ $arg ] = $val;
			}
		}

		return $output;

	}

	/**
	 * Run one ElasticPress search and return the posts.
	 *
	 * Full post objects are kept so highlighting merged by ElasticPress survives.
	 * facetwp_elasticpress marks the query so the callbacks below leave it alone.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function run_query( array $args ): array {

		/**
		 * Filter how many ElasticPress matches are kept for the native search page.
		 *
		 * This caps the whole listing, not one facet. ElasticPress itself will not
		 * return more than ep_max_results_window (10000 by default).
		 *
		 * @param int $max_results Maximum posts to request.
		 */
		$max_results = (int) apply_filters( 'facetwp_elasticpress_max_results', 200 );

		$overrides = [
			'ep_integrate'         => true,
			'posts_per_page'       => $max_results,
			'paged'                => 1,
			'no_found_rows'        => true,
			'facetwp'              => false,
			'facetwp_elasticpress' => true,
		];

		$query = new WP_Query( array_merge( $args, $overrides ) );

		return is_array( $query->posts ) ? $query->posts : [];

	}

	/**
	 * Point the main query at the filtered ElasticPress IDs.
	 *
	 * Priority 1000 runs after FacetWP_Request::update_query_vars(). Every
	 * using_elasticpress query except the cached search itself is kept off
	 * Elasticsearch. Once the render query has been short-circuited, the main
	 * query is rewritten so MySQL does not re-apply the search SQL.
	 *
	 * @param WP_Query $query Query being prepared.
	 */
	public function pre_get_posts( $query ): void {

		if ( true === $query->get( 'facetwp_elasticpress' ) || true !== $query->get( 'using_elasticpress' ) ) {
			return;
		}

		$query->set( 'ep_integrate', false );

		if ( true === $query->get( 'facetwp' ) && ! $this->first_run ) {
			$query->set( 's', '' );

			$post_ids = FWP()->filtered_post_ids;
			$post_ids = empty( $post_ids ) ? [ 0 ] : $post_ids;
			$query->set( 'post__in', $post_ids );

			if ( '' === $query->get( 'post_type' ) ) {
				$query->set( 'post_type', 'any' );
				$query->set( 'post_status', 'any' );
			}

			if ( '' === $query->get( 'orderby' ) ) {
				$query->set( 'orderby', 'post__in' );
			}
		}

	}

	/**
	 * Serve cached ElasticPress IDs, and skip the queries FacetWP does not use.
	 *
	 * facetwp false is get_filtered_post_ids(). Return the cached IDs and skip SQL.
	 *
	 * facetwp true on the first pass is FacetWP's render query. Return an empty
	 * list so it does not hit the database; the theme loop uses the main query.
	 *
	 * facetwp true after that is the main query. Restore the keyword for the
	 * search form, and let the SQL built in pre_get_posts run.
	 *
	 * @param array|null $posts Posts, or null when the query has not run.
	 * @param WP_Query   $query Query about to run.
	 * @return array|null
	 */
	public function posts_pre_query( $posts, $query ) {

		if ( true === $query->get( 'facetwp_elasticpress' ) || true !== $query->get( 'using_elasticpress' ) ) {
			return $posts;
		}

		if ( true === $query->get( 'facetwp' ) ) {
			$query->set( 's', $this->keywords );

			if ( $this->first_run ) {
				$this->first_run = false;

				$filtered             = FWP()->filtered_post_ids;
				$per_page             = (int) $query->get( 'posts_per_page', get_option( 'posts_per_page' ) );
				$query->found_posts   = is_array( $filtered ) ? count( $filtered ) : 0;
				$query->max_num_pages = ( 0 < $per_page ) ? (int) ceil( $query->found_posts / $per_page ) : 0;

				return [];
			}
		} else {
			return wp_list_pluck( $this->posts, 'ID' );
		}

		return $posts;

	}

	/**
	 * Swap the main query's posts for the cached ElasticPress objects.
	 *
	 * ElasticPress merges search highlights onto those objects. The SQL query
	 * only supplies order and pagination.
	 *
	 * @param array    $posts Posts for the current page.
	 * @param WP_Query $query Query that produced them.
	 * @return array
	 */
	public function posts_results( $posts, $query ): array {

		if ( true === $query->get( 'facetwp_elasticpress' ) || true !== $query->get( 'using_elasticpress' ) ) {
			return $posts;
		}

		if ( true === $query->get( 'facetwp' ) && ! $this->first_run ) {
			$lookup = [];

			foreach ( $this->posts as $post ) {
				if ( is_object( $post ) && isset( $post->ID ) ) {
					$lookup[ (int) $post->ID ] = $post;
				}
			}

			foreach ( $posts as $index => $post ) {
				$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;

				if ( ! isset( $lookup[ $post_id ] ) ) {
					continue;
				}

				$cached = $lookup[ $post_id ];

				// stdClass hits lose their highlight fields if the loop reloads them by ID.
				$posts[ $index ] = ( $cached instanceof WP_Post ) ? $cached : new WP_Post( $cached );
			}
		}

		return $posts;

	}

	/**
	 * Add the ElasticPress section to the FacetWP settings screen.
	 *
	 * @param array $settings Registered FacetWP settings.
	 * @param FacetWP_Settings $class FacetWP settings renderer.
	 * @return array
	 */
	public function admin_settings( array $settings, $class ): array {

		$settings['elasticpress']['label'] = 'ElasticPress';
		$settings['elasticpress']['fields']['elp_disable_default_search'] = [
			'label' => 'Disable on default search page',
			'notes' => 'Leaves the default WordPress search page to ElasticPress\' own query integration.',
			'html'  => $class->get_setting_html( 'elp_disable_default_search', 'toggle' ),
		];

		return $settings;

	}

}

/**
 * Create a instance of the class
 *
 * @return FacetWP_ElasticPress_Integration
 */
function FWPEP(): FacetWP_ElasticPress_Integration {
	return FacetWP_ElasticPress_Integration::instance();
}

$fwpep = FWPEP();
