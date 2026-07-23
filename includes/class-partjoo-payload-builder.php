<?php
if (! defined('ABSPATH')) {
    exit;
}

class PartJoo_Payload_Builder
{

    private $config;
    private $products;
    private $signatures;

    public function __construct(PartJoo_Config $config, PartJoo_Product_Repository $products, PartJoo_Signature_Service $signatures)
    {
        $this->config     = $config;
        $this->products   = $products;
        $this->signatures = $signatures;
    }

    public function build_payload($domain, array $product_ids, $force = false)
    {
        return $this->build_payload_from_entries($domain, $this->build_product_entries($product_ids, $force));
    }

    public function build_product_entries(array $product_ids, $force = false)
    {
        $entries = [];

        foreach ($product_ids as $product_id) {
            $item = $this->build_product_item($product_id);

            if (! $item) {
                $entries[] = [
                    'product_id' => $product_id,
                    'item'       => null,
                ];

                continue;
            }

            if (! $force) {
                $signature_current = $this->signatures->make($item);
                $signature_sent    = $this->products->get_meta($product_id, '_partjoo_sig_sent');

                if ($signature_sent && $signature_current === $signature_sent) {
                    continue;
                }
            }

            $entries[] = [
                'product_id' => $product_id,
                'item'       => $item,
            ];
        }

        return $entries;
    }

    public function build_payload_from_entries($domain, array $entries)
    {
        $products = [];

        foreach ($entries as $entry) {
            if (! empty($entry['item'])) {
                $products[] = $entry['item'];
            }
        }

        return [
            'route'   => PartJoo_Product_Sync::ROUTE,
            'content' => [
                'domain'   => $domain,
                'allLinks' => [],
                'products' => array_values($products),
            ],
        ];
    }

    public function build_product_item($product_id)
    {
        $product = $this->products->get_product($product_id);
        if (! $product) {
            return null;
        }

        if ('publish' !== $this->products->get_post_status($product_id)) {
            return null;
        }

        $is_variation = $product->is_type('variation');
        $title        = $product->get_name();

        if ($is_variation) {
            $attributes = wc_get_formatted_variation($product, true, false, true);
            if ($attributes) {
                $title .= ' - ' . wp_strip_all_tags($attributes);
            }
        }

        $url       = get_permalink($is_variation ? $product->get_parent_id() : $product_id);
        $image_url = '';
        $image_id  = $product->get_image_id();

        if ($image_id) {
            $source = wp_get_attachment_image_src($image_id, 'full');
            if ($source && ! empty($source[0])) {
                $image_url = $source[0];
            }
        }

        $price = $product->get_price();
        $price = is_numeric($price) ? (float) $price : null;
        $unit  = $this->config->get('force_unit');

        if (! empty($this->config->get('convert_toman_rial'))) {
            if (null !== $price) {
                $price *= 10.0;
            }

            $unit = 'rial';
        }

        $manage_stock = (bool) $product->get_manage_stock();
        $quantity     = $manage_stock ? (int) $product->get_stock_quantity() : 0;
        $availability = $product->is_in_stock() ? '1' : '-1';
        $stock        = $manage_stock ? max(0, $quantity) : 0;

        $condition_meta = $this->products->get_meta($product_id, '_partjoo_condition');
        $condition      = '' !== $condition_meta ? $condition_meta : ($this->config->get('default_condition') ?: 'new');
        $part_name      = $this->products->get_meta($product_id, '_partjoo_part_name');
        $part_number    = $this->products->get_meta($product_id, '_partjoo_part_number');

        if (! $part_number) {
            $part_number = $product->get_sku();
        }

        $description = $product->get_short_description();
        if (! $description) {
            $description = $product->get_description();
        }

        $description = wp_strip_all_tags($description);
        if (mb_strlen($description) > 3000) {
            $description = mb_substr($description, 0, 3000);
        }

        $bulk_json   = $this->products->get_meta($product_id, '_partjoo_bulk_prices');
        $bulk_prices = [];

        if ($bulk_json) {
            $decoded = json_decode($bulk_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    $quantity = isset($row['quantity']) ? (int) $row['quantity'] : null;
                    $bulk_price = isset($row['price']) ? (float) $row['price'] : null;

                    if ($quantity && null !== $bulk_price) {
                        if (! empty($this->config->get('convert_toman_rial'))) {
                            $bulk_price *= 10.0;
                        }

                        $bulk_prices[] = [
                            'quantity' => $quantity,
                            'price'    => (int) round($bulk_price),
                        ];
                    }
                }
            }
        }

        $bulk_prices = apply_filters('partjoo_bulk_prices', $bulk_prices, $product);

        $item = [
            'title'        => (string) $title,
            'url'          => (string) $url,
            'availability' => $availability,
        ];

        if ($image_url) {
            $item['image'] = $image_url;
        }
        if (null !== $price) {
            $item['price'] = (int) round($price);
        }
        if ($unit) {
            $item['unit'] = (string) $unit;
        }
        if ($stock > 0 || $manage_stock) {
            $item['stock'] = (int) $stock;
        }
        if ($condition) {
            $item['condition'] = (string) $condition;
        }
        if ($part_number) {
            $item['partNumber'] = (string) $part_number;
        }
        if ($part_name) {
            $item['partName'] = (string) $part_name;
        }
        if ($description) {
            $item['description'] = (string) $description;
        }
        if (! empty($bulk_prices)) {
            $item['bulkPrices'] = $bulk_prices;
        }

        return apply_filters('partjoo_product_data', $item, $product);
    }

    public function build_deletion_payload($product, $product_id, $domain)
    {
        $is_variation = $product->is_type('variation');
        $title        = $product->get_name();

        if ($is_variation) {
            $attributes = wc_get_formatted_variation($product, true, false, true);
            if ($attributes) {
                $title .= ' - ' . wp_strip_all_tags($attributes);
            }
        }

        return [
            'route'   => PartJoo_Product_Sync::ROUTE,
            'content' => [
                'domain'   => $domain,
                'allLinks' => [],
                'products' => [
                    [
                        'title'        => (string) $title,
                        'url'          => (string) get_permalink($is_variation ? $product->get_parent_id() : $product_id),
                        'availability' => '-1',
                        'stock'        => 0,
                    ],
                ],
            ],
        ];
    }
}
