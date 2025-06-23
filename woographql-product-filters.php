<?php
/**
 * Plugin Name: WooGraphQL Product Filters
 * Description: Complete product filtering solution with attribute ranges and aggregations for WooCommerce GraphQL
 * Version: 1.1.0
 * Author: Andrei Baturyn
 * License: MIT
 * Text Domain: woographql-product-filters
 * WC requires at least: 4.0.0
 * WC tested up to: 8.4.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
	exit;
}

define('WOOGRAPHQL_PRODUCT_FILTERS_VERSION', '1.1.0');
define('WOOGRAPHQL_PRODUCT_FILTERS_PLUGIN_NAME', 'WooGraphQL Product Filters');

add_action('plugins_loaded', ['WooGraphQL_Product_Filters', 'check_dependencies']);

class WooGraphQL_Product_Filters
{
	private static $captured_where_args = [];
	private static $taxonomy_mapping = [
		'PRODUCT_CAT' => 'product_cat',
		'PRODUCT_TAG' => 'product_tag',
		'PRODUCT_BRAND' => 'product_brand'
	];

	public static function check_dependencies(): void
	{
		if (!class_exists('WPGraphQL')) {
			add_action('admin_notices', [__CLASS__, 'notice_wpgraphql_required']);
			return;
		}

		if (!class_exists('WooCommerce')) {
			add_action('admin_notices', [__CLASS__, 'notice_woocommerce_required']);
			return;
		}

		self::register_hooks();
	}

	private static function register_hooks(): void
	{
		add_action('graphql_register_types', [__CLASS__, 'register_types']);
		add_filter('graphql_map_input_fields_to_wp_query', [__CLASS__, 'capture_where_args'], 10, 2);
		add_filter('graphql_connection_query_args', [__CLASS__, 'filter_products_by_attribute_range'], 10, 3);
	}

	public static function notice_wpgraphql_required(): void
	{
		printf('<div class="notice notice-error"><p>%s requires WPGraphQL plugin to be active.</p></div>',
			esc_html(WOOGRAPHQL_PRODUCT_FILTERS_PLUGIN_NAME));
	}

	public static function notice_woocommerce_required(): void
	{
		printf('<div class="notice notice-error"><p>%s requires WooCommerce plugin to be active.</p></div>',
			esc_html(WOOGRAPHQL_PRODUCT_FILTERS_PLUGIN_NAME));
	}

	public static function register_types(): void
	{
		self::register_enum_types();
		self::register_input_types();
		self::register_object_types();
		self::register_fields();
	}

	// ================= TYPE REGISTRATION =================

	private static function register_enum_types(): void
	{
		// Product attributes enum
		$wc_attributes = wc_get_attribute_taxonomies();
		$enum_values = [];

		foreach ($wc_attributes as $attribute) {
			$enum_values[$attribute->attribute_name] = [
				'value' => $attribute->attribute_name,
				'description' => $attribute->attribute_label ?: $attribute->attribute_name
			];
		}

		if (!empty($enum_values)) {
			register_graphql_enum_type('ProductAttributeEnum', [
				'description' => 'Available product attributes',
				'values' => $enum_values
			]);
		}
	}

	private static function register_input_types(): void
	{
		register_graphql_input_type('AttributeRangeFilter', [
			'description' => 'Filter products by attribute range',
			'fields' => [
				'attribute' => [
					'type' => 'ProductAttributeEnum',
					'description' => 'Product attribute to filter by'
				],
				'min' => [
					'type' => 'Float',
					'description' => 'Minimum value'
				],
				'max' => [
					'type' => 'Float',
					'description' => 'Maximum value'
				]
			]
		]);
	}

	private static function register_object_types(): void
	{
		// Aggregations main type
		register_graphql_object_type('ProductFilterAggregations', [
			'description' => 'Product filter aggregations with ranges and counts',
			'fields' => [
				'priceRange' => [
					'type' => 'ProductPriceRange',
					'description' => 'Price range for products'
				],
				'attributes' => [
					'type' => ['list_of' => 'ProductAttributeAggregation'],
					'description' => 'Available product attributes with ranges and terms'
				],
				'brands' => [
					'type' => ['list_of' => 'ProductBrandAggregation'],
					'description' => 'Available brands with counts'
				]
			]
		]);

		// Price range
		register_graphql_object_type('ProductPriceRange', [
			'description' => 'Price range data for products',
			'fields' => [
				'min' => ['type' => 'Float', 'description' => 'Minimum price'],
				'max' => ['type' => 'Float', 'description' => 'Maximum price']
			]
		]);

		// Attribute aggregation with range support
		register_graphql_object_type('ProductAttributeAggregation', [
			'description' => 'Product attribute aggregation with range and terms',
			'fields' => [
				'name' => ['type' => 'String', 'description' => 'Attribute name'],
				'slug' => ['type' => 'String', 'description' => 'Attribute slug'],
				'type' => ['type' => 'String', 'description' => 'Attribute type (text, numeric, etc.)'],
				'range' => [
					'type' => 'ProductAttributeRange',
					'description' => 'Range data for numeric attributes'
				],
				'terms' => [
					'type' => ['list_of' => 'ProductAttributeTermAggregation'],
					'description' => 'Attribute terms with counts'
				]
			]
		]);

		// Attribute range
		register_graphql_object_type('ProductAttributeRange', [
			'description' => 'Range data for numeric attributes',
			'fields' => [
				'min' => ['type' => 'Float', 'description' => 'Minimum value'],
				'max' => ['type' => 'Float', 'description' => 'Maximum value']
			]
		]);

		// Attribute term with count
		register_graphql_object_type('ProductAttributeTermAggregation', [
			'description' => 'Product attribute term with count',
			'fields' => [
				'name' => ['type' => 'String', 'description' => 'Term name'],
				'slug' => ['type' => 'String', 'description' => 'Term slug'],
				'count' => ['type' => 'Int', 'description' => 'Number of products']
			]
		]);

		// Brand aggregation
		register_graphql_object_type('ProductBrandAggregation', [
			'description' => 'Product brand aggregation data',
			'fields' => [
				'name' => ['type' => 'String', 'description' => 'Brand name'],
				'slug' => ['type' => 'String', 'description' => 'Brand slug'],
				'count' => ['type' => 'Int', 'description' => 'Number of products']
			]
		]);
	}

	private static function register_fields(): void
	{
		// Add range filter to product queries
		register_graphql_field('RootQueryToProductUnionConnectionWhereArgs', 'attributeRange', [
			'type' => ['list_of' => 'AttributeRangeFilter'],
			'description' => 'Filter products by attribute ranges'
		]);

		// Add aggregations to product connections
		$connection_types = [
			'RootQueryToProductUnionConnection',
			'ProductCategoryToProductUnionConnection'
		];

		foreach ($connection_types as $connection_type) {
			register_graphql_field($connection_type, 'aggregations', [
				'type' => 'ProductFilterAggregations',
				'description' => 'Filter aggregations for the current product query',
				'resolve' => [__CLASS__, 'resolve_aggregations']
			]);
		}
	}

	// ================= QUERY HANDLING =================

	public static function capture_where_args($query_args, $where_args)
	{
		self::$captured_where_args = $where_args;
		return $query_args;
	}

	public static function filter_products_by_attribute_range($query_args, $source, $args)
	{
		if (!isset($args['where']['attributeRange'])) {
			return $query_args;
		}

		if (!isset($query_args['post_type'])) {
			$query_args['post_type'] = 'product';
		}

		$range_filters = $args['where']['attributeRange'];
		if (empty($range_filters)) {
			return $query_args;
		}

		try {
			$product_ids = self::get_range_filtered_product_ids($range_filters);

			if (empty($product_ids)) {
				$query_args['post__in'] = [0];
			} else {
				if (isset($query_args['post__in']) && !empty($query_args['post__in'])) {
					$query_args['post__in'] = array_intersect($query_args['post__in'], $product_ids);
				} else {
					$query_args['post__in'] = $product_ids;
				}
			}
		} catch (Exception $e) {
			error_log('WooGraphQL Product Filters Error: ' . $e->getMessage());
			$query_args['post__in'] = [0];
		}

		return $query_args;
	}

	// ================= RESOLVERS =================

	public static function resolve_aggregations($source, $args, $context, $info): array
	{
		try {
			$where_args = self::$captured_where_args;

			return [
				'priceRange' => self::get_price_range($where_args),
				'attributes' => self::get_attribute_aggregations($where_args),
				'brands' => self::get_taxonomy_aggregations('product_brand', $where_args)
			];
		} catch (Exception $e) {
			error_log('WooGraphQL Product Filters Error: ' . $e->getMessage());
			return [
				'priceRange' => ['min' => 0, 'max' => 0],
				'attributes' => [],
				'brands' => []
			];
		}
	}

	// ================= DATA METHODS =================

	private static function get_price_range($where_args): array
	{
		global $wpdb;

		try {
			$product_ids = self::get_filtered_product_ids($where_args, true);

			if (empty($product_ids)) {
				return ['min' => 0, 'max' => 0];
			}

			$ids_placeholder = implode(',', array_map('absint', $product_ids));
			$result = $wpdb->get_row("
                SELECT 
                    MIN(CAST(meta_value AS DECIMAL(10,2))) as min_price,
                    MAX(CAST(meta_value AS DECIMAL(10,2))) as max_price
                FROM {$wpdb->postmeta}
                WHERE post_id IN ({$ids_placeholder})
                AND meta_key = '_price'
                AND meta_value IS NOT NULL
                AND meta_value != ''
            ");

			return [
				'min' => $result->min_price ? (float) $result->min_price : 0,
				'max' => $result->max_price ? (float) $result->max_price : 0
			];
		} catch (Exception $e) {
			error_log('WooGraphQL Product Filters Error: ' . $e->getMessage());
			return ['min' => 0, 'max' => 0];
		}
	}

	private static function get_attribute_aggregations($where_args): array
	{
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		if (empty($attribute_taxonomies)) {
			return [];
		}

		$result = [];
		foreach ($attribute_taxonomies as $attribute) {
			$taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
			$terms = self::get_taxonomy_terms_with_counts($taxonomy, $where_args);
			$range = self::get_attribute_range_data($taxonomy, $where_args);

			if (!empty($terms) || ($range['min'] !== $range['max'])) {
				$result[] = [
					'name' => $attribute->attribute_label ?: $attribute->attribute_name,
					'slug' => $taxonomy,
					'type' => $attribute->attribute_type ?: 'select',
					'range' => $range,
					'terms' => $terms
				];
			}
		}

		return $result;
	}

	private static function get_attribute_range_data($taxonomy, $where_args): array
	{
		global $wpdb;

		try {
			$product_ids = self::get_filtered_product_ids($where_args, false, $taxonomy);

			if (empty($product_ids)) {
				return ['min' => 0, 'max' => 0];
			}

			$ids_placeholder = implode(',', array_map('absint', $product_ids));

			$results = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    MIN(CAST(t.name AS DECIMAL(10,2))) as min_value,
                    MAX(CAST(t.name AS DECIMAL(10,2))) as max_value,
                    COUNT(DISTINCT t.term_id) as numeric_terms_count
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tr.object_id IN ({$ids_placeholder})
                AND tt.taxonomy = %s
                AND t.name REGEXP '^[0-9]+(\\.[0-9]+)?$'
            ", $taxonomy));

			if (!$results || $results->numeric_terms_count == 0) {
				return ['min' => 0, 'max' => 0];
			}

			return [
				'min' => $results ? (float) $results->min_value : 0,
				'max' => $results ? (float) $results->max_value : 0
			];
		} catch (Exception $e) {
			error_log('WooGraphQL Product Filters Error: ' . $e->getMessage());
			return ['min' => 0, 'max' => 0];
		}
	}

	private static function get_taxonomy_aggregations($taxonomy, $where_args): array
	{
		return self::get_taxonomy_terms_with_counts($taxonomy, $where_args);
	}

	private static function get_taxonomy_terms_with_counts($taxonomy, $where_args): array
	{
		global $wpdb;

		try {
			$filter_data = self::build_filters_sql($where_args, $taxonomy);

			$sql = "
                SELECT t.name, t.slug, COUNT(DISTINCT p.ID) as count
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                {$filter_data['joins']}
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = %s
                {$filter_data['wheres']}
                GROUP BY t.term_id 
                HAVING count > 0 
                ORDER BY t.name
            ";

			$prepare_args = array_merge([$taxonomy], $filter_data['args']);
			$results = $wpdb->get_results($wpdb->prepare($sql, ...$prepare_args));

			return array_map(function($term) {
				return [
					'name' => $term->name,
					'slug' => $term->slug,
					'count' => (int) $term->count
				];
			}, $results ?: []);
		} catch (Exception $e) {
			error_log('WooGraphQL Product Filters Error: ' . $e->getMessage());
			return [];
		}
	}

	// ================= UTILITY METHODS =================

	private static function get_filtered_product_ids($where_args, $exclude_price = false, $exclude_taxonomy = null): array
	{
		global $wpdb;

		$filter_data = self::build_filters_sql($where_args, $exclude_taxonomy, $exclude_price);

		$sql = "
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p 
            {$filter_data['joins']}
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            {$filter_data['wheres']}
        ";

		if (!empty($filter_data['args'])) {
			return $wpdb->get_col($wpdb->prepare($sql, ...$filter_data['args']));
		}

		return $wpdb->get_col($sql);
	}

	private static function get_range_filtered_product_ids($range_filters): array
	{
		global $wpdb;

		$product_ids = null;

		foreach ($range_filters as $filter) {
			$attribute = sanitize_text_field($filter['attribute']);
			$min = floatval($filter['min']);
			$max = floatval($filter['max']);
			$taxonomy = 'pa_' . $attribute;

			$valid_term_ids = $wpdb->get_col($wpdb->prepare("
                SELECT t.term_id
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                WHERE tt.taxonomy = %s
                AND t.name REGEXP '^[0-9]+(\\.[0-9]+)?$'
                AND CAST(t.name AS DECIMAL(10,2)) >= %f
                AND CAST(t.name AS DECIMAL(10,2)) <= %f
            ", $taxonomy, $min, $max));

			if (empty($valid_term_ids)) {
				$product_ids = [];
				break;
			}

			$term_ids_placeholder = implode(',', array_map('absint', $valid_term_ids));

			$ids = $wpdb->get_col(
				"SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = '" . esc_sql($taxonomy) . "'
                AND tt.term_id IN ({$term_ids_placeholder})"
			);

			if ($product_ids === null) {
				$product_ids = $ids;
			} else {
				$product_ids = array_intersect($product_ids, $ids);
			}
		}

		return $product_ids ?: [];
	}

	private static function build_filters_sql($where_args, $exclude_taxonomy = null, $exclude_price = false): array
	{
		global $wpdb;

		$joins = [];
		$wheres = [];
		$prepare_args = [];

		// Price filters
		if (!$exclude_price && (isset($where_args['minPrice']) || isset($where_args['maxPrice']))) {
			$joins[] = "LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'";

			if (isset($where_args['minPrice'])) {
				$wheres[] = "CAST(pm_price.meta_value AS DECIMAL(10,2)) >= %f";
				$prepare_args[] = $where_args['minPrice'];
			}
			if (isset($where_args['maxPrice'])) {
				$wheres[] = "CAST(pm_price.meta_value AS DECIMAL(10,2)) <= %f";
				$prepare_args[] = $where_args['maxPrice'];
			}
		}

		// Taxonomy filters
		if (isset($where_args['taxonomyFilter']['filters'])) {
			$filters = (array) $where_args['taxonomyFilter']['filters'];
			$tax_join_count = 0;

			foreach ($filters as $filter) {
				if (!isset($filter['taxonomy'])) continue;

				$taxonomy_name = self::convert_graphql_taxonomy($filter['taxonomy']);

				if ($exclude_taxonomy && $taxonomy_name === $exclude_taxonomy) {
					continue;
				}

				$tax_alias = "tr_filter_{$tax_join_count}";
				$tt_alias = "tt_filter_{$tax_join_count}";
				$t_alias = "t_filter_{$tax_join_count}";

				$joins[] = "INNER JOIN {$wpdb->term_relationships} {$tax_alias} ON p.ID = {$tax_alias}.object_id";
				$joins[] = "INNER JOIN {$wpdb->term_taxonomy} {$tt_alias} ON {$tax_alias}.term_taxonomy_id = {$tt_alias}.term_taxonomy_id";
				$joins[] = "INNER JOIN {$wpdb->terms} {$t_alias} ON {$tt_alias}.term_id = {$t_alias}.term_id";

				$wheres[] = "{$tt_alias}.taxonomy = %s";
				$prepare_args[] = $taxonomy_name;

				if (!empty($filter['terms'])) {
					$terms = (array) $filter['terms'];
					$expanded_terms = [];

					foreach ($terms as $term_slug) {
						$expanded_terms = array_merge($expanded_terms, self::get_term_and_children_slugs($term_slug, $taxonomy_name));
					}

					if (!empty($expanded_terms)) {
						$placeholders = implode(',', array_fill(0, count($expanded_terms), '%s'));
						$wheres[] = "{$t_alias}.slug IN ({$placeholders})";
						$prepare_args = array_merge($prepare_args, $expanded_terms);
					}
				}

				$tax_join_count++;
			}
		}

		return [
			'joins' => !empty($joins) ? implode(' ', array_unique($joins)) : '',
			'wheres' => !empty($wheres) ? 'AND ' . implode(' AND ', $wheres) : '',
			'args' => $prepare_args
		];
	}

	private static function get_term_and_children_slugs($slug, $taxonomy): array
	{
		static $cache = [];
		$cache_key = "{$taxonomy}:{$slug}";

		if (isset($cache[$cache_key])) {
			return $cache[$cache_key];
		}

		$term = get_term_by('slug', $slug, $taxonomy);
		if (!$term || is_wp_error($term)) {
			return $cache[$cache_key] = [];
		}

		$slugs = [$term->slug];
		$children = get_terms([
			'taxonomy' => $taxonomy,
			'parent' => $term->term_id,
			'hide_empty' => false,
			'fields' => 'slugs'
		]);

		foreach ($children as $child_slug) {
			$slugs = array_merge($slugs, self::get_term_and_children_slugs($child_slug, $taxonomy));
		}

		return $cache[$cache_key] = array_unique($slugs);
	}

	private static function convert_graphql_taxonomy($graphql_taxonomy): string
	{
		if (isset(self::$taxonomy_mapping[$graphql_taxonomy])) {
			return self::$taxonomy_mapping[$graphql_taxonomy];
		}

		if (strpos($graphql_taxonomy, 'PA_') === 0) {
			return 'pa_' . strtolower(substr($graphql_taxonomy, 3));
		}

		return strtolower($graphql_taxonomy);
	}
}

// Activation/Deactivation hooks
register_activation_hook(__FILE__, function() {
	if (!class_exists('WooCommerce') || !class_exists('WPGraphQL')) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(__('WooGraphQL Product Filters requires WooCommerce and WPGraphQL to be active.', 'woographql-product-filters'));
	}

	if (function_exists('graphql_clear_schema')) {
		graphql_clear_schema();
	}
});

register_deactivation_hook(__FILE__, function() {
	if (function_exists('graphql_clear_schema')) {
		graphql_clear_schema();
	}
});