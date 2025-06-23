# WooGraphQL Product Filters

Complete product filtering solution for WPGraphQL and WooCommerce with attribute ranges and aggregations.

## Description

Provides comprehensive filter data for building dynamic product filters in headless WooCommerce stores, including:
- Price range filtering and aggregations
- Numeric attribute range filtering (e.g., size: 10-50)
- Aggregated data with counts for all attributes, brands, and categories
- Full compatibility with existing WooGraphQL filters

## Features

### Aggregations
- **Price Range Data**: Get min/max prices from current product set
- **Attribute Aggregations**: All available attribute values with counts + numeric ranges
- **Brand Aggregations**: All available brands with product counts
- **Category Hierarchy**: Full support for parent/child categories

### Range Filtering
- **Numeric Attribute Filtering**: Filter products by numeric attribute ranges
- **Dynamic Ranges**: Automatically detects numeric attributes and provides min/max values
- **Performance Optimized**: Efficient SQL queries with proper indexing

## Installation

1. Upload plugin to `/wp-content/plugins/woographql-product-filters/`
2. Activate plugin in WordPress admin
3. Ensure WPGraphQL and WPGraphQL for WooCommerce are active

## Requirements

- WordPress 6.0+
- WooCommerce 9.0+
- [WPGraphQL](https://wordpress.org/plugins/wp-graphql/)
- [WPGraphQL for WooCommerce](https://github.com/wp-graphql/wp-graphql-woocommerce)

## Usage

### Aggregations Query
```graphql
query ProductsWithFilters {
  products {
    nodes {
      id
      name
      price
    }
    aggregations {
      priceRange {
        min
        max
      }
      attributes {
        name
        slug
        type
        range {
          min
          max
        }
        terms {
          name
          slug
          count
        }
      }
      brands {
        name
        slug
        count
      }
    }
  }
}
```

### Range Filtering
```graphql
query FilteredProducts {
  products(where: {
    attributeRange: [
      { attribute: "size", min: 10, max: 50 },
      { attribute: "weight", min: 5, max: 25 }
    ]
  }) {
    nodes {
      id
      name
      price
    }
  }
}
```

### Combined Filtering
```graphql
query CombinedFilters {
  products(where: {
    minPrice: 100
    maxPrice: 500
    taxonomyFilter: {
      filters: [
        { taxonomy: PRODUCT_CAT, terms: ["electronics"] }
      ]
    }
    attributeRange: [
      { attribute: "screen_size", min: 15, max: 27 }
    ]
  }) {
    nodes {
      id
      name
      price
    }
    aggregations {
      priceRange { min max }
      attributes {
        name
        range { min max }
        terms { name count }
      }
    }
  }
}
```

## GraphQL Schema

### Input Types
- `AttributeRangeFilter`: Filter by numeric attribute ranges

### Object Types
- `ProductFilterAggregations`: Main aggregations container
- `ProductAttributeAggregation`: Attribute with range + terms
- `ProductAttributeRange`: Min/max values for numeric attributes
- `ProductPriceRange`: Price min/max values
- `ProductAttributeTermAggregation`: Attribute term with count
- `ProductBrandAggregation`: Brand with count

## Use Cases

- **Faceted Navigation**: Build complete filter interfaces
- **Price Sliders**: Dynamic price ranges based on actual data
- **Attribute Filters**: Both text-based and numeric range filters
- **Category Filters**: With accurate product counts
- **Brand Filters**: All brands with availability counts

## Performance

- Optimized SQL queries with proper joins
- Caching for term hierarchies
- Minimal database impact
- Compatible with WooCommerce product visibility settings

## License

MIT License - see LICENSE file for details.