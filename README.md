# PartJoo Product Sync

Sync WooCommerce products to PartJoo search engine via API v1.2, with change tracking, logs, deletion handling, and WP-CLI.

## Table of Contents
- [Description](#description)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [WP-CLI Commands](#wp-cli-commands)
- [API Documentation](#api-documentation)
- [Troubleshooting](#troubleshooting)
- [Extension Points](#extension-points)
- [FAQ](#faq)

## Description

The PartJoo Product Sync plugin enables WooCommerce store owners to automatically synchronize their products with the PartJoo search engine. The plugin intelligently tracks changes and only sends updated products, reducing bandwidth and processing overhead.

## Features

- **API v1.2 Integration**: Uses the official PartJoo API with route `crawler/addProductsToPartjoo`
- **Smart Change Tracking**: Sends only changed products using content signatures; Force resend available
- **Batch Processing**: Efficient batch sending (max 100 products per request)
- **Event Handling**: Handles stock/price events and product deletions
- **Deletion Support**: Tombstone records with availability set to -1 for deleted products
- **Authentication**: Optional API key header `X-PartJoo-Key` (if provided)
- **Admin Interface**: User-friendly admin panel with sync controls and status monitoring
- **Logging System**: Comprehensive logging with recent logs display
- **WP-CLI Support**: Command-line interface for advanced operations
- **Multisite Compatible**: Works in WordPress multisite environments
- **Queue System**: Robust queue mechanism for reliable processing

## Requirements

- WordPress 5.8 or higher
- WooCommerce (required)
- PHP 7.4 or higher
- cURL extension enabled

## Installation

1. Install and activate WooCommerce if not already installed
2. Upload the plugin ZIP file through WordPress admin or extract to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to WooCommerce → PartJoo Sync in your admin panel
5. Configure your **Assigned Domain** and other preferences
6. Test synchronization using "Sync CHANGED products" or rely on scheduled cron

## Configuration

Access the configuration panel via WooCommerce → PartJoo Sync. Available settings include:

- **API Endpoint**: The PartJoo API endpoint (default: `https://partjoo.com/partjoo/apiv1`)
- **API Key**: Optional authentication key for PartJoo API
- **Assigned Domain**: Your unique domain assigned by PartJoo (required)
- **Batch Size**: Number of products per batch (1-100, default: 100)
- **Send on Save/Update**: Automatically sync products when saved
- **Send on Stock/Price Events**: Sync when stock or prices change
- **Convert Toman → Rial**: Multiply prices by 10 for rial conversion
- **Unit to Send**: Currency unit (rial, toman, dollar, yuan)
- **Default Condition**: Default product condition (new, oem, copy, renew, used, nos)
- **Send Variations Separately**: Treat variations as individual products
- **Cron Recurrence**: Schedule frequency (hourly, twice daily, daily)

## Architecture

The plugin follows a modern service-oriented architecture with clear separation of concerns:

### Core Components

- **PartJoo_Container**: Dependency injection container managing service lifecycles
- **PartJoo_Product_Sync**: Main plugin class coordinating operations
- **PartJoo_Admin**: Administrative interface and settings management
- **PartJoo_Config**: Configuration management and option handling

### Data Layer

- **PartJoo_Product_Repository**: Manages all product-related data access and metadata operations
- **PartJoo_State**: Database schema management and sync log storage
- **PartJoo_Queue_Repository**: Queue persistence layer for reliable processing

### Business Services

- **PartJoo_Sync_Orchestrator**: Coordinates product synchronization workflows
- **PartJoo_Payload_Builder**: Constructs API payloads according to PartJoo specifications
- **PartJoo_Signature_Service**: Generates content signatures for change detection
- **PartJoo_Validation_Service**: Validates payloads before transmission
- **PartJoo_Queue_Service**: Manages queue operations and scheduling
- **PartJoo_Queue_Processor**: Processes queued items with retry logic

### Infrastructure Services

- **PartJoo_Api_Client**: Handles HTTP communication with PartJoo API
- **PartJoo_Logger**: Records sync events and maintains status history
- **PartJoo_WP_HTTP_Transport**: HTTP transport abstraction layer

### Queue System

The robust queue system ensures reliable processing:

- **Asynchronous Processing**: Products are queued and processed separately
- **Retry Mechanism**: Failed operations are retried with exponential backoff
- **Idempotency**: Safe to retry operations without side effects
- **Concurrency Control**: Locking mechanisms prevent duplicate processing
- **Priority System**: Different priority levels for sync vs deletion operations

## WP-CLI Commands

The plugin provides several WP-CLI commands for advanced administration:

```bash
# Sync changed products
wp partjoo sync

# Force resync all products
wp partjoo sync --force

# Count dirty (changed) products
wp partjoo count-dirty

# View recent sync logs
wp partjoo logs

# Process queue items
wp partjoo process-queue

# Recalculate all product signatures
wp partjoo recalc-signatures
```

## API Documentation

Detailed API specifications are available in [doc/api-v1.2.md](doc/api-v1.2.md). The API uses POST requests to `https://partjoo.com/partjoo/apiv1` with JSON payloads containing the required route and product data.

## Troubleshooting

### Common Issues

1. **Missing Assigned Domain**: Ensure you've received your domain from PartJoo team and entered it exactly as provided
2. **Sync Not Working**: Check WordPress cron is functioning and permissions are correct
3. **Rate Limiting**: Large stores should adjust batch sizes to avoid API limits
4. **SSL Issues**: Ensure SSL certificates are valid for HTTPS API communication

### Debugging

- Monitor the "Recent Logs" section in the admin panel
- Check WordPress error logs for PHP errors
- Verify API connectivity and authentication
- Monitor queue processing status

### Performance Tips

- Adjust batch size based on server capacity (default: 100)
- Use cron for large catalogs rather than manual sync
- Consider disabling "Send on Save" for bulk operations
- Monitor database growth of sync log table

## Extension Points

The plugin provides several hooks for customization:

### Filters

- `partjoo_product_data`: Modify product data before sending
- `partjoo_bulk_prices`: Modify bulk pricing data
- `partjoo_sync_response`: Process API responses
- `partjoo_payload_validate`: Customize payload validation

### Actions

- `partjoo_sync_response`: Hook into sync responses
- Custom actions available throughout the sync process

## FAQ

### What is the "Assigned Domain"?

The exact domain PartJoo uses to identify your site in their index. This must be provided by PartJoo support team.

### Does this plugin require WooCommerce?

Yes, this plugin requires WooCommerce to be installed and activated to function.

### How often are products synced?

Products sync based on your configured cron schedule, or immediately when saved if "Send on Save" is enabled.

### Can I sync only changed products?

Yes, the plugin uses content signatures to detect changes and only sends updated products by default.

### Is there a limit to how many products can be synced?

The API accepts up to 100 products per request. The plugin handles batching automatically for larger catalogs.

### What happens to deleted products?

Deleted products are sent to PartJoo with availability set to -1 and stock to 0, creating tombstone records.

## License

GPLv2 or later