<?php
/*
Plugin Name: HERMESSO WooCommerce / Mesonic Rabattleiste Sync
Description: Fügt einen benutzerdefinierten REST-API-Endpunkt hinzu, um Rabattleisten zu aktualisieren.
Version: 1.0
Author: Hermesso EDV Dienstleistungs GesmbH
*/

// Code für den REST-API-Endpunkt
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/update-rabattleiste', array(
        'methods' => 'POST',
        'callback' => 'update_rabattleiste_table',
        'permission_callback' => function () {
            return true;
        },
    ));
});

function update_rabattleiste_table(WP_REST_Request $request) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'rabattleisten';
    $data = $request->get_json_params();

    // Tabelle erstellen, falls sie nicht existiert
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            rabattleiste int NOT NULL,
            rabattspalte_von int NOT NULL,
            rabattspalte_bis int NOT NULL,
            prozentsatz float NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    } else {
        // Tabelle leeren, falls bereits Daten vorhanden sind
        $wpdb->query("TRUNCATE TABLE $table_name");
    }

    $errors = []; // Array, um Fehler zu sammeln

    // Daten einfügen oder aktualisieren
    foreach ($data as $row) {
        $result = $wpdb->replace(
            $table_name,
            [
                'rabattleiste' => intval($row['rabattleiste']),
                'rabattspalte_von' => intval($row['rabattspalteVon']),
                'rabattspalte_bis' => intval($row['rabattspalteBis']),
                'prozentsatz' => floatval($row['prozentsatz']),
            ],
            ['%d', '%d', '%d', '%f']
        );

        if ($result === false) {
            // Fehler sammeln
            $errors[] = [
                'row' => $row,
                'error' => $wpdb->last_error,
            ];
            error_log("Fehler beim Einfügen: " . $wpdb->last_error . " - Daten: " . print_r($row, true));
        }
    }

    // Rückgabe bei Fehlern
    if (!empty($errors)) {
        status_header(500); // Setzt den HTTP-Statuscode auf 500
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Fehler beim Einfügen in die Tabelle',
            'errors' => $errors,
        ], 500);
    }

    // Erfolgreiche Rückgabe
    return new WP_REST_Response(['success' => true, 'message' => 'OK'], 200);
}


// Dynamische Preiskalkulation
add_filter('woocommerce_product_get_price', 'hermesso_calculate_custom_price', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'hermesso_calculate_custom_price', 10, 2);

function hermesso_calculate_custom_price($price, $product) {
    // Wenn kein Preis gesetzt ist, direkt zurückgeben
    if (empty($price)) {
        return $price;
    }

    $user_id = get_current_user_id();

    if (!$user_id) {
        return $price;
    }

    $rabattleiste = get_user_meta($user_id, 'rabattleiste', true);
    
    // Wenn keine Rabattleiste gesetzt ist, Original-Preis zurückgeben
    if (empty($rabattleiste)) {
        return $price;
    }
    
    $rabattleiste = intval($rabattleiste);
    $rabattspalte = $product->get_meta('rabattspalte', true);
    $rabattspalte = !empty($rabattspalte) ? intval($rabattspalte) : 1;

    global $wpdb;
    $table_name = $wpdb->prefix . 'rabattleisten';

    // Prüfen ob die Tabelle existiert
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return $price;
    }

    $discount = $wpdb->get_var($wpdb->prepare(
        "SELECT prozentsatz FROM $table_name WHERE rabattleiste = %d AND rabattspalte_von <= %d AND rabattspalte_bis >= %d",
        $rabattleiste,
        $rabattspalte,
        $rabattspalte
    ));

    if (is_null($discount)) {
        return $price;
    }

    $discounted_price = $price * (1 + $discount / 100);
    
    // Sicherstellen, dass der Preis nicht negativ wird
    return $discounted_price > 0 ? $discounted_price : $price;
}