<?php
/*
 * Plugin Name: Sontechbot - WooCommerce Senkron
 * Plugin URI:  https://github.com/tyfnacici/sontechbot-woocommerce-sync
 * Description: Exposes single & bulk REST endpoints to get the product, update price & stock by barcode (_original_id), with rate limiting and logging for not-found products.
 * Version:     1.0.0
 * Author:      Tayfun Açıcı
 * Author URI:  https://tyfnacici.xyz
 * License:     GPL-3.0
 * GitHub Plugin URI: tyfnacici/sontechbot-woocommerce-sync
 * GitHub Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCBSS_RATE_LIMIT', 100 );
define( 'WCBSS_RATE_PERIOD', 60 );
define( 'WCBSS_BULK_MAX', 1000 );
define( 'WCBSS_NOT_FOUND_LOG_OPTION', 'wcbss_not_found_barcodes_log' ); 

// Uninstall hook to clean up the database when the plugin is deleted.
register_uninstall_hook( __FILE__, 'wcbss_on_uninstall' );
function wcbss_on_uninstall() {
    delete_option( WCBSS_NOT_FOUND_LOG_OPTION );
}

// Register REST endpoints
add_action( 'rest_api_init', function() {
    // single‑item update
    register_rest_route( 'sontechbot', '/update', [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => 'wcbss_update_product_by_barcode',
        'permission_callback' => function() {
            return current_user_can( 'manage_woocommerce' );
        },
        'args' => [
            'barcode'        => [ 'required' => true ],
            'regular_price'  => [ 'required' => true ],
            'stock_quantity' => [ 'required' => true ],
            'manage_stock'   => [ 'required' => false, 'default' => true ],
        ],
    ] );

    // bulk update endpoint
    register_rest_route( 'sontechbot', '/sync', [
        'methods'             => WP_REST_Server::EDITABLE,
        'callback'            => 'wcbss_bulk_update',
        'permission_callback' => function() {
            return current_user_can( 'manage_woocommerce' );
        },
        'args' => [
            'items' => [
                'required'          => true,
                'validate_callback' => function( $items ) {
                    return is_array( $items );
                },
            ],
        ],
    ] );

    // Endpoint to get a single product by barcode
    register_rest_route( 'sontechbot', '/get-product', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'wcbss_get_product_by_barcode',
        'permission_callback' => function() {
            return current_user_can( 'manage_woocommerce' );
        },
        'args' => [
            'barcode' => [ 
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
        ],
    ] );
});

/**
 * Rate‑limit helper
 */
function wcbss_rate_limit_check() {
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key      = 'wcbss_rate_' . md5( $ip );
    $count    = (int) get_transient( $key );
    $remaining = max( 0, WCBSS_RATE_LIMIT - $count );

    header( 'X-RateLimit-Limit: ' . WCBSS_RATE_LIMIT );
    header( 'X-RateLimit-Remaining: ' . $remaining );
    header( 'X-RateLimit-Reset: ' . ( WCBSS_RATE_PERIOD - ( time() - ( get_option( $key . '_ts', 0 ) ) ) ) );

    if ( $count >= WCBSS_RATE_LIMIT ) {
        return new WP_Error(
            'rate_limit_exceeded',
            'Rate limit of ' . WCBSS_RATE_LIMIT . ' req/min exceeded.',
            [ 'status' => 429, 'Retry-After' => WCBSS_RATE_PERIOD ]
        );
    }

    set_transient( $key, $count + 1, WCBSS_RATE_PERIOD );
    if ( false === get_option( $key . '_ts' ) ) {
        update_option( $key . '_ts', time() );
    }

    return true;
}

/**
 * Callback to get a product by its barcode.
 */
function wcbss_get_product_by_barcode( WP_REST_Request $request ) {
    if ( is_wp_error( $rl = wcbss_rate_limit_check() ) ) {
        return $rl;
    }

    $barcode = $request['barcode'];

    $found_products = wcbss_get_products_by_barcodes( [ $barcode ] );

    if ( empty( $found_products ) ) {
        return new WP_Error( 'not_found', "No product found with barcode {$barcode}", [ 'status' => 404 ] );
    }

    $product_id = $found_products[ $barcode ];
    $product = wc_get_product( $product_id );

    if ( ! $product ) {
        return new WP_Error( 'invalid_product', "Could not load product object for ID {$product_id}.", [ 'status' => 500 ] );
    }

    $response_data = [
        'id'             => $product->get_id(),
        'barcode'        => $barcode,
        'name'           => $product->get_name(),
        'sku'            => $product->get_sku(),
        'regular_price'  => $product->get_regular_price(),
        'stock_quantity' => $product->get_stock_quantity(),
        'manage_stock'   => $product->get_manage_stock(),
        'is_in_stock'    => $product->is_in_stock(),
    ];

    return rest_ensure_response( $response_data );
}

/**
 * Single‑item callback
 */
function wcbss_update_product_by_barcode( WP_REST_Request $request ) {
    if ( is_wp_error( $rl = wcbss_rate_limit_check() ) ) {
        return $rl;
    }

    $barcode      = sanitize_text_field( $request['barcode'] );
    $new_price    = wc_format_decimal( $request['regular_price'], '' );
    $new_stock    = intval( $request['stock_quantity'] );
    $manage_stock = filter_var( $request->get_param( 'manage_stock' ), FILTER_VALIDATE_BOOLEAN );

    return wcbss_do_update( $barcode, $new_price, $new_stock, $manage_stock );
}

/**
 * Bulk‑update callback with pre-check
 */
function wcbss_bulk_update( WP_REST_Request $request ) {
    if ( is_wp_error( $rl = wcbss_rate_limit_check() ) ) {
        return $rl;
    }

    $items = $request->get_param( 'items' );

    if ( count( $items ) > WCBSS_BULK_MAX ) {
        return new WP_Error( 'too_many_items', 'Maximum of ' . WCBSS_BULK_MAX . ' items allowed per request.', [ 'status' => 400 ] );
    }

    $results = [ 'success' => [], 'failed' => [] ];
    $all_requested_barcodes = wp_list_pluck( $items, 'barcode' );
    $all_requested_barcodes = array_filter( array_map( 'sanitize_text_field', $all_requested_barcodes ) );

    $existing_products_map = wcbss_get_products_by_barcodes( $all_requested_barcodes );
    $existing_barcodes = array_keys( $existing_products_map );
    
    $not_found_barcodes = array_diff( $all_requested_barcodes, $existing_barcodes );
    if ( ! empty( $not_found_barcodes ) ) {
        wcbss_log_not_found_barcodes( $not_found_barcodes );
    }

    foreach ( $items as $i => $item ) {
        $barcode = sanitize_text_field( $item['barcode'] ?? '' );

        if ( ! in_array( $barcode, $existing_barcodes ) ) {
            $results['failed'][] = [
                'index'   => $i,
                'barcode' => $barcode ?: '(missing)',
                'error'   => 'Product not found.',
            ];
            continue;
        }

        $quantity = isset( $item['quantity'] ) ? intval( $item['quantity'] ) : null;
        $price    = isset( $item['sellingPrice'] ) ? wc_format_decimal( $item['sellingPrice'], '' ) : null;

        if ( is_null( $quantity ) || is_null( $price ) ) {
            $results['failed'][] = [ 'index' => $i, 'barcode' => $barcode, 'error' => 'Invalid or missing quantity/sellingPrice fields' ];
            continue;
        }

        $product_id = $existing_products_map[ $barcode ];
        $res = wcbss_do_update( $barcode, $price, $quantity, true, $product_id );

        if ( is_wp_error( $res ) ) {
            $results['failed'][] = [ 'index' => $i, 'barcode' => $barcode, 'error' => $res->get_error_message() ];
        } else {
            $results['success'][] = [ 'index' => $i, 'barcode' => $barcode, 'id' => $res['id'] ];
        }
    }

    return rest_ensure_response( $results );
}

/**
 * Core update logic to accept an optional product ID to skip the query
 */
function wcbss_do_update( $barcode, $price, $stock, $manage_stock, $product_id = null ) {
    if ( ! $product_id ) {
        $found = wcbss_get_products_by_barcodes( [ $barcode ] );
        if ( empty( $found ) ) {
            return new WP_Error( 'not_found', "No product found with barcode {$barcode}", [ 'status' => 404 ] );
        }
        $product_id = $found[ $barcode ];
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_Error( 'invalid_product', 'Could not load product object.', [ 'status' => 500 ] );
    }

    $product->set_regular_price( $price );
    $product->set_manage_stock( $manage_stock );
    $product->set_stock_quantity( $stock );
    $product->save();

    return [ 'id' => $product_id, 'regular_price' => $price, 'stock_quantity'=> $stock ];
}

/**
 * Efficiently get an array of product IDs mapped by their barcode.
 */
function wcbss_get_products_by_barcodes( array $barcodes ) {
    if ( empty( $barcodes ) ) {
        return [];
    }
    
    global $wpdb;
    $sql = $wpdb->prepare(
        "SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p \
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id \
         WHERE p.post_type IN ('product', 'product_variation') \
         AND pm.meta_key = '_original_id' \
         AND pm.meta_value IN (" . implode( ',', array_fill( 0, count( $barcodes ), '%s' ) ) . ")",
        $barcodes
    );

    $results = $wpdb->get_results( $sql );
    
    $map = [];
    foreach ( $results as $result ) {
        $map[ $result->meta_value ] = $result->ID;
    }

    return $map;
}

/**
 * Log barcodes that were not found, ensuring no duplicates.
 */
function wcbss_log_not_found_barcodes( array $barcodes_to_log ) {
    $existing_log = get_option( WCBSS_NOT_FOUND_LOG_OPTION, [] );
    // Merge and remove duplicates to ensure the log is always unique.
    $new_log = array_unique( array_merge( $existing_log, $barcodes_to_log ) );
    update_option( WCBSS_NOT_FOUND_LOG_OPTION, $new_log );
}

/**
 * Add the admin page to view the log.
 */
add_action( 'admin_menu', 'wcbss_add_not_found_log_page' );
function wcbss_add_not_found_log_page() {
    add_menu_page(
        'Bulunamayan Barkod Logları',
        'Bulunamayan Barkodlar',
        'manage_woocommerce',
        'wcbss-not-found-log',
        'wcbss_render_not_found_log_page',
        'dashicons-barcode',
        58
    );
}

/**
 * Render the content for the admin log page.
 */
function wcbss_render_not_found_log_page() {
    if ( isset( $_POST['wcbss_clear_log_nonce'] ) && wp_verify_nonce( $_POST['wcbss_clear_log_nonce'], 'wcbss_clear_log_action' ) ) {
        delete_option( WCBSS_NOT_FOUND_LOG_OPTION );
        echo '<div class="notice notice-success is-dismissible"><p>Log temizlendi.</p></div>';
    }

    $logged_barcodes = get_option( WCBSS_NOT_FOUND_LOG_OPTION, [] );
    ?>
    <div class="wrap">
        <h1>Sitede Olmayıp Güncellenmeye Çalışılan Ürünlerin Barkodları</h1>
        <p>Bu sayfada, REST API üzerinden güncellenmeye çalışılan ancak sistemde `_original_id` ile eşleşen bir ürün bulunamayan barkodların bir listesi tutulmaktadır.</p>
        
        <?php if ( ! empty( $logged_barcodes ) ) : ?>
            <div id="wcbss-log-list" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; max-height: 500px; overflow-y: auto;">
                <ul style="margin-top: 0;">
                    <?php foreach ( $logged_barcodes as $barcode ) : ?>
                        <li><?php echo esc_html( $barcode ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <br>
            <form method="post" action="">
                <?php wp_nonce_field( 'wcbss_clear_log_action', 'wcbss_clear_log_nonce' ); ?>
                <button type="submit" class="button button-primary">Logları Temizle</button>
            </form>
        <?php else : ?>
            <p>Logda kayıtlı barkod bulunmamaktadır.</p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * GitHub-based auto-update integration
 */
require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

$updateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://github.com/tyfnacici/sontechbot-woocommerce-sync/',
    __FILE__,
    'sontechbot-woocommerce-sync'
);

// Optional: provide a GitHub personal access token if needed to avoid rate limits
// $updateChecker->setAuthentication('YOUR_GITHUB_PERSONAL_ACCESS_TOKEN');

// Ensure we check the 'main' branch for new releases
$updateChecker->setBranch('main');
