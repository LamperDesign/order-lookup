<?php
/**
 * Plugin Name: Order Lookup
 * Description: Retourformulier op basis van ordernummer + e-mailadres. Gebruik shortcode [order_lookup] of [order_lookup admin_email="info@jouwsite.nl"] op elke pagina.
 * Version:     1.11
 * Author:      Lamper Design
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── 1. From-header voor WordPress mails vanuit deze plugin ──────────────────

add_filter( 'wp_mail_from', function ( $email ) {
    if ( strpos( $email, 'wordpress@' ) !== false ) {
        return 'noreply@' . parse_url( home_url(), PHP_URL_HOST );
    }
    return $email;
} );

add_filter( 'wp_mail_from_name', function ( $name ) {
    if ( $name === 'WordPress' ) {
        return get_bloginfo( 'name' );
    }
    return $name;
} );

// ─── 2. Script + nonce doorgeven aan de frontend ─────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'order-lookup',
        WPMU_PLUGIN_URL . '/order-lookup/assets/js/order-lookup.js',
        [ 'jquery' ],
        '1.9',
        true
    );
    wp_localize_script( 'order-lookup', 'orderLookup', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'order_lookup_nonce' ),
    ] );
} );

// ─── 3. Hulpfunctie: order zoeken op bestelnummer ────────────────────────────

function order_lookup_find_by_number( $order_number ) {
    $direct = wc_get_order( intval( $order_number ) );
    if ( $direct && $direct->get_order_number() == $order_number ) {
        return $direct;
    }

    $orders = wc_get_orders( [
        'limit'      => 1,
        'meta_key'   => '_order_number',
        'meta_value' => $order_number,
    ] );

    return ! empty( $orders ) ? $orders[0] : false;
}

// ─── 4. Rate limiting hulpfunctie ────────────────────────────────────────────

function order_lookup_check_rate_limit() {
    $ip   = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    $key  = 'order_lookup_rl_' . md5( $ip );
    $hits = (int) get_transient( $key );

    if ( $hits >= 10 ) {
        wp_send_json_error( 'Te veel pogingen. Probeer het over 5 minuten opnieuw.' );
    }

    set_transient( $key, $hits + 1, 5 * MINUTE_IN_SECONDS );
}

// ─── 5. AJAX: producten ophalen ──────────────────────────────────────────────

add_action( 'wp_ajax_get_order_products',        'order_lookup_ajax' );
add_action( 'wp_ajax_nopriv_get_order_products', 'order_lookup_ajax' );

function order_lookup_ajax() {
    check_ajax_referer( 'order_lookup_nonce', 'nonce' );
    order_lookup_check_rate_limit();

    $order_number = sanitize_text_field( $_POST['order_id'] ?? '' );
    $order_number = preg_replace( '/^FL/i', '', strtoupper( $order_number ) );
    $email        = sanitize_email( $_POST['email'] ?? '' );

    if ( ! $order_number || ! $email ) {
        wp_send_json_error( 'Vul beide velden in.' );
    }

    $order = order_lookup_find_by_number( $order_number );

    if ( ! $order || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
        wp_send_json_error( 'Order niet gevonden of e-mailadres klopt niet.' );
    }

    $items = [];
    foreach ( $order->get_items() as $item ) {
        $items[] = [
            'id'       => $item->get_id(),
            'name'     => $item->get_name(),
            'qty'      => $item->get_quantity(),
            'subtotal' => strip_tags( wc_price( $item->get_subtotal() ) ),
        ];
    }

    wp_send_json_success( [
        'order_number' => $order->get_order_number(),
        'items'        => $items,
    ] );
}

// ─── 6. AJAX: retourverzoek versturen ────────────────────────────────────────

add_action( 'wp_ajax_submit_return_request',        'order_return_ajax' );
add_action( 'wp_ajax_nopriv_submit_return_request', 'order_return_ajax' );

function order_return_ajax() {
    check_ajax_referer( 'order_lookup_nonce', 'nonce' );
    order_lookup_check_rate_limit();

    $order_number = sanitize_text_field( $_POST['order_number'] ?? '' );
    $email        = sanitize_email( $_POST['email'] ?? '' );

    $raw_admin    = sanitize_text_field( $_POST['admin_email'] ?? '' );
    $admin_emails = array_filter(
        array_map( 'sanitize_email', explode( ',', $raw_admin ) )
    );

    if ( empty( $admin_emails ) ) {
        $admin_emails = [ get_option( 'admin_email' ) ];
    }

    if ( ! $order_number || ! $email ) {
        wp_send_json_error( 'Ongeldige aanvraag.' );
    }

    $order = order_lookup_find_by_number( $order_number );

    if ( ! $order || strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
        wp_send_json_error( 'Order niet gevonden of e-mailadres klopt niet.' );
    }

    $valid_items = [];
    foreach ( $order->get_items() as $item ) {
        $key                 = $item->get_id() . '|' . $item->get_name();
        $valid_items[ $key ] = $item->get_name() . ' (x' . $item->get_quantity() . ')';
    }

    $posted   = isset( $_POST['items'] ) ? (array) $_POST['items'] : [];
    $selected = [];
    foreach ( $posted as $raw ) {
        $raw = sanitize_text_field( $raw );
        if ( isset( $valid_items[ $raw ] ) ) {
            $selected[] = $valid_items[ $raw ];
        }
    }

    if ( empty( $selected ) ) {
        wp_send_json_error( 'Selecteer minimaal één product.' );
    }

    $name       = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
    $items_list = implode( "\n", array_map( fn( $i ) => '- ' . $i, $selected ) );

    $admin_headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $name . ' <' . $email . '>',
    ];

    $customer_headers = [
        'Content-Type: text/plain; charset=UTF-8',
    ];

    foreach ( $admin_emails as $admin_email ) {
        wp_mail(
            $admin_email,
            'Retourverzoek order #' . $order_number,
            "Er is een retourverzoek ontvangen.\n\nOrder: #$order_number\nKlant: $name\nE-mail: $email\n\nProducten:\n$items_list",
            $admin_headers
        );
    }

    wp_mail(
        $email,
        'Bevestiging retourverzoek #' . $order_number,
        "Beste $name,\n\nBedankt voor je retourverzoek. We hebben het volgende ontvangen:\n\nOrder: #$order_number\n\nProducten:\n$items_list\n\nWe nemen zo snel mogelijk contact met je op.\n\nMet vriendelijke groet,\n" . get_bloginfo( 'name' ),
        $customer_headers
    );

    wp_send_json_success( 'Retourverzoek verstuurd. Je ontvangt een bevestiging per e-mail.' );
}

// ─── 7. Shortcode [order_lookup] ─────────────────────────────────────────────

add_shortcode( 'order_lookup', function ( $atts ) {
    $atts = shortcode_atts( [
        'admin_email' => get_option( 'admin_email' ),
    ], $atts, 'order_lookup' );

    $admin_email = implode( ',', array_filter(
        array_map( 'sanitize_email', explode( ',', $atts['admin_email'] ) )
    ) );

    ob_start(); ?>
    <div class="order-lookup-wrap">
        <label>E-mailadres<br>
            <input type="email" id="ol-email" placeholder="jouw@email.nl">
        </label><br><br>
        <label>Ordernummer<br>
            <input type="text" id="ol-order" placeholder="12345">
        </label><br><br>
        <a href="#" id="ol-btn">Toon alle producten uit de bestelling</a>
        <input type="hidden" id="ol-admin-email" value="<?php echo esc_attr( $admin_email ); ?>">
        <div id="ol-results" style="margin-top:1.5em;"></div>
    </div>
    <?php
    return ob_get_clean();
} );

// ─── 8. Link in klassieke WooCommerce order-mails via wp_mail filter ──────────
// (werkt niet met de nieuwe block-gebaseerde WooCommerce email editor —
//  gebruik daarvoor sectie 9: de custom personalization tag)

add_action( 'woocommerce_email_customer_on_hold_order',    'order_lookup_set_inject', 10, 3 );
add_action( 'woocommerce_email_customer_completed_order',  'order_lookup_set_inject', 10, 3 );
function order_lookup_set_inject( $order, $sent_to_admin, $plain_text ) {
    if ( $sent_to_admin || $plain_text ) return;
    $GLOBALS['order_lookup_inject'] = $order;
}

add_filter( 'wp_mail', 'order_lookup_inject_mail_link' );
function order_lookup_inject_mail_link( $args ) {
    $order = $GLOBALS['order_lookup_inject'] ?? null;
    if ( ! $order ) return $args;

    $page_url   = home_url( '/bestelling-herroepen/' );
    $return_url = $page_url
        . '?ol_email=' . rawurlencode( $order->get_billing_email() )
        . '&ol_order=' . rawurlencode( $order->get_order_number() );

    $args['message'] .= '<p style="margin-top:20px;text-align:center;">'
        . '<a href="' . esc_url( $return_url ) . '">Ik wil mijn bestelling herroepen</a>'
        . '</p>';

    unset( $GLOBALS['order_lookup_inject'] );
    return $args;
}

// ─── 9. Custom personalization tag voor nieuwe WooCommerce email editor ───────
// Voeg in de email editor een knop-blok toe met als URL: <!--[order-lookup/return-url]-->
// De tag is ook beschikbaar via het personalization tags menu (categorie "Order").

add_filter( 'woocommerce_email_editor_register_personalization_tags', function ( $registry ) {
    if ( ! class_exists( '\Automattic\WooCommerce\EmailEditor\Engine\PersonalizationTags\Personalization_Tag' ) ) {
        return $registry;
    }

    $registry->register(
        new \Automattic\WooCommerce\EmailEditor\Engine\PersonalizationTags\Personalization_Tag(
            'Herroepen URL',
            'order-lookup/return-url',
            'Order',
            function ( $context ) {
                $order = $context['order'] ?? null;
                if ( ! $order ) return '';

                return home_url( '/bestelling-herroepen/' )
                    . '?ol_email=' . rawurlencode( $order->get_billing_email() )
                    . '&ol_order=' . rawurlencode( $order->get_order_number() );
            }
        )
    );

    return $registry;
} );

// ─── 10. Custom placeholder {return_url} voor klassieke WooCommerce email editor ──
// Typ {return_url} in het veld "Aanvullende inhoud" van een WooCommerce e-mailtype.
// Voorbeeld: <a href="{return_url}">Ik wil mijn bestelling herroepen</a>

add_filter( 'woocommerce_email_format_string', function ( $string, $email ) {
    if ( strpos( $string, '{return_url}' ) === false ) {
        return $string;
    }

    $order = $email->object ?? null;
    if ( ! $order instanceof \WC_Order ) {
        return $string;
    }

    $return_url = home_url( '/bestelling-herroepen/' )
        . '?ol_email=' . rawurlencode( $order->get_billing_email() )
        . '&ol_order=' . rawurlencode( $order->get_order_number() );

    return str_replace( '{return_url}', esc_url( $return_url ), $string );
}, 10, 2 );

// ─── 11. Knop "Herroepen" in Mijn account → Bestellingen ──────────────────────

add_filter( 'woocommerce_my_account_my_orders_actions', function ( $actions, $order ) {
    $return_url = home_url( '/bestelling-herroepen/' )
        . '?ol_email=' . rawurlencode( $order->get_billing_email() )
        . '&ol_order=' . rawurlencode( $order->get_order_number() );

    $actions['herroepen'] = [
        'url'  => $return_url,
        'name' => 'Herroepen',
    ];

    return $actions;
}, 10, 2 );
