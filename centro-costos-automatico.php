<?php
/**
 * Plugin Name: Centro Costos Automatico
 * Description: Añade un metabox a los productos de WooCommerce para seleccionar centros de costos desde la API de Siigo.
 * Version: 1.0
 * Author: Cristian Torres
 * Text Domain: centro-costos-automatico
 */
defined('ABSPATH') or die('No script kiddies please!');
 // Reemplaza con tu clave de acceso
/**
 * Obtiene el token de acceso de la API de Siigo.
 *
 * @return string|false El token de acceso en caso de éxito, false en caso de error.
 */
function get_siigo_access_token() {
  // Prepara los datos de la solicitud
  $request_body = json_encode(array(
      'username'   => SIIGO_USER, //Acceso wp-config
      'access_key' => SIIGO_TOKEN, //Acceso wp-config
  ));

  // Registra el cuerpo de la solicitud para depuración
  error_log('Cuerpo de la solicitud: ' . $request_body);

  $response = wp_remote_post(SIIGO_API_URL_LOGIN, array(
      'body'    => $request_body,
      'headers' => array(
          'Content-Type' => 'application/json',
      ),
  ));



  // Verifica si hubo un error en la solicitud
  if (is_wp_error($response)) {
      error_log('Error al obtener el token de acceso: ' . $response->get_error_message());
      return false;
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body);

  // Registra la respuesta para depuración
  error_log('Respuesta de la API: ' . print_r($data, true));

  // Verifica si el token de acceso está presente
  if (isset($data->access_token)) {
      return $data->access_token;
  }

  return false;
}


// Función para obtener centros de costos desde la API de Siigo
/**
 * Obtiene los centros de costos de la API de Siigo.
 *
 * @return array|false Array de centros de costos en caso de éxito, false en caso de error.
 */
/**
 * Obtiene los centros de costos de la API de Siigo.
 *
 * @return array|false Array de centros de costos en caso de éxito, false en caso de error.
 */
function fetch_cost_centers_from_siigo() {
  // Obtener el token de acceso
  $access_token = get_siigo_access_token();

  // Verificar si se obtuvo el token de acceso
  if (!$access_token) {
      return false; 
  }

  // Configurar la solicitud GET a la API
  $response = wp_remote_get(SIIGO_API_URL_COST_CENTERS, array(
      'headers' => array(
          'Authorization' => 'Bearer ' . $access_token,
          'Partner-Id'    => 'tallerproduction', // Encabezado Partner-Id
      ),
  ));

  // Verificar si hubo un error en la solicitud
  if (is_wp_error($response)) {
      error_log('Error en la solicitud wp_remote_get: ' . $response->get_error_message());
      return false;
  }

  // Obtener el cuerpo de la respuesta
  $body = wp_remote_retrieve_body($response);

  // Decodificar el cuerpo de la respuesta
  $data = json_decode($body);
  
  // Depuración
  error_log('Respuesta de la API: ' . print_r($data, true));

  // Verificar si la respuesta contiene datos válidos
  if ($data && isset($data) && count($data) > 0) {
      return $data;
  }

  return false;
}



// Función para añadir el metabox
function add_custom_metabox() {
    add_meta_box(
        'custom_metabox_id',           // ID del metabox
        'Centros de Costos',           // Título del metabox
        'custom_metabox_callback',     // Función callback
        'product',                     // Pantalla (tipo de post)
        'side',                        // Contexto
        'default'                      // Prioridad
    );
}
add_action('add_meta_boxes', 'add_custom_metabox');

// Función callback para mostrar el contenido del metabox
function custom_metabox_callback($post) {
    // Obtener centros de costos desde la API de Siigo
    $centros_costos = fetch_cost_centers_from_siigo();
   
    if (!$centros_costos) {
        echo 'Error al obtener centros de costos desde la API.';
        return;
    }

    // Obtener el valor guardado (si existe)
    $selected_centro = get_post_meta($post->ID, '_centro_costos', true);

    // Generar el select
    wp_nonce_field(basename(__FILE__), 'custom_metabox_nonce');
    echo '<select name="centro_costos" id="centro_costos">';
    echo '<option value="">Selecciona un centro de costo</option>';
    foreach ($centros_costos as $centro) {
        $selected = ($selected_centro == $centro->id) ? ' selected="selected"' : '';
        echo '<option value="' . esc_attr($centro->id) . '"' . $selected . '>' . esc_html($centro->name) . '</option>'; // Ajusta el campo 'name' según la estructura de la respuesta de la API
    }
    echo '</select>';
}

// Función para guardar los datos del metabox
function save_custom_metabox_data($post_id) {
    // Verificar si es una revisión
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Verificar el nonce (seguridad)
    if (!isset($_POST['custom_metabox_nonce']) || !wp_verify_nonce($_POST['custom_metabox_nonce'], basename(__FILE__))) {
        return;
    }

    // Verificar el tipo de post
    if (get_post_type($post_id) != 'product') {
        return;
    }

    // Guardar el valor del select
    if (isset($_POST['centro_costos'])) {
        update_post_meta($post_id, '_centro_costos', sanitize_text_field($_POST['centro_costos']));
    } else {
        delete_post_meta($post_id, '_centro_costos');
    }
}
add_action('save_post', 'save_custom_metabox_data');

function populate_billing_cost_field($checkout) {
 
  $cart = WC()->cart->get_cart();
  $centro_costos = array();
  
  foreach ($cart as $cart_item) {
      $product_id = $cart_item['product_id'];
      $product_centro_costos = get_post_meta($product_id, '_centro_costos', true);

      // Si es array se combina
      if (is_array($product_centro_costos)) {
          $centro_costos = array_merge($centro_costos, $product_centro_costos);
      } else if ($product_centro_costos) {
          $centro_costos[] = $product_centro_costos;
      }
  }

  // Eliminar duplicados
  $centro_costos = array_unique($centro_costos);
  //Se Combina los centros de costos en un solo string separado por comas o usa el formato que prefieras
  $billing_costs_value = implode(', ', $centro_costos);

  // Guardar el valor en el campo `billing_cost`
  WC()->session->set('billing_cost_hide', $billing_costs_value);

/* $getcosts = WC()->session->get('_billing_cost_hide');
  var_dump($getcosts); */
}
add_action('woocommerce_checkout_init', 'populate_billing_cost_field');


add_action('woocommerce_checkout_update_order_meta', 'add_data_in_orden');

function add_data_in_orden($order_id) {
    // Información del campo
    $getcosts = WC()->session->get('billing_cost_hide');
    $hide_info = $getcosts;
 // Clave y valor del campo
 $meta_key_cost = 'billing_cost_hide';
 $meta_value_cost = $hide_info;

 // Añadir metadato a la orden
 if (!empty($meta_key_cost)) {
     $order = wc_get_order($order_id);
     $order->update_meta_data($meta_key_cost, $meta_value_cost);
     $order->save();
 }
}

add_action('woocommerce_admin_order_data_after_order_details', 'show_data_in_admin_order');

function show_data_in_admin_order($order) {

  $meta_key_cost = 'billing_cost_hide';
  $meta_value_cost = $order->get_meta($meta_key_cost);
	
    if ($meta_value_cost) {
      echo '<p><strong>' . esc_html(ucwords(str_replace('_', ' ', $meta_key_cost))) . ':</strong> ' . esc_html($meta_value_cost) . '</p>';
    }
}