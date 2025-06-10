# WooGraphQL Filter Aggregations

Extension for WPGraphQL and WooCommerce that adds aggregated data for creating dynamic product filters on the frontend.

## Description

The plugin provides data about price ranges, attributes, and brands with product counts for each value, which is necessary for creating interactive filters in headless WooCommerce stores.

The plugin **does not perform** product filtering itself, but provides metadata for building a user interface on the frontend.

## Features

- Get minimum and maximum prices for the current product set
- Retrieve all available attribute values with product counts
- Obtain all available brands with product counts
- Compatibility with existing WooGraphQL filters
- Support for category hierarchy when building aggregations

## Installation

1. Upload the plugin files to the `/wp-content/plugins/woographql-filter-aggregations` directory or install through the WordPress admin panel.
2. Activate the plugin in the "Plugins" section of WordPress.
3. Make sure the WPGraphQL and WPGraphQL for WooCommerce plugins are installed and activated.

## Requirements

- WordPress 5.8+
- WooCommerce 4.0+
- [WPGraphQL](https://wordpress.org/plugins/wp-graphql/)
- [WPGraphQL for WooCommerce](https://github.com/wp-graphql/wp-graphql-woocommerce)

## Usage

After activation, the plugin adds an `aggregations` field to product connections in the GraphQL schema:

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

## License

This plugin is distributed under the MIT License. See LICENSE.txt for more information.
