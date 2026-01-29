<?php
/**
 * Plugin Name: WP Libro de Reclamaciones
 * Description: Formulario oficial según normativa INDECOPI con registro en DB y avisos legales.
 * Version:     1.1.6
 * Author:      Percy Ll. Romero
 * License:     GPL2
 */

// Cargar la librería de actualizaciones
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Percyllromero/wp-libro-reclamaciones/', 
    __FILE__,
    'wp-libro-reclamaciones'
);
$myUpdateChecker->setBranch('main');

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * --- 1. ESTILOS Y ACTIVACIÓN ---
 */
function wplr_cargar_estilos() {
    wp_enqueue_style('wplr-estilos-globales', plugin_dir_url( __FILE__ ) . 'css/style.css', array(), '1.1.6');
}
add_action( 'wp_enqueue_scripts', 'wplr_cargar_estilos', 20 );

register_activation_hook( __FILE__, 'lr_crear_tabla_oficial' );
function lr_crear_tabla_oficial() {
    global $wpdb;
    $tabla = $wpdb->prefix . 'reclamaciones';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $tabla (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        fecha datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        correlativo varchar(20) NOT NULL,
        nombre_completo varchar(100) NOT NULL,
        dni_ce varchar(15) NOT NULL,
        domicilio varchar(150) NOT NULL,
        telefono varchar(20) NOT NULL,
        email_cliente varchar(80) NOT NULL,
        representante varchar(100),
        bien_tipo varchar(20) NOT NULL,
        bien_monto varchar(20),
        bien_descripcion varchar(200),
        tipo_incidencia varchar(20) NOT NULL,
        detalle text NOT NULL,
        pedido text NOT NULL,
        estado varchar(20) DEFAULT 'Pendiente' NOT NULL, 
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * --- 2. PANEL ADMINISTRATIVO (CORREGIDO) ---
 */
add_action( 'admin_menu', 'lr_crear_menu_completo' );
function lr_crear_menu_completo() {
    // Menú principal
    add_menu_page('Libro de Reclamaciones', 'Reclamaciones 📝', 'manage_options', 'lr_ajustes_plugin', 'lr_generar_interfaz_ajustes', 'dashicons-clipboard', 60);
    
    // CORRECCIÓN: Al usar el mismo slug 'lr_ajustes_plugin', renombramos el primer elemento automático
    add_submenu_page('lr_ajustes_plugin', 'Configuración de Empresa', 'Configuración ⚙️', 'manage_options', 'lr_ajustes_plugin', 'lr_generar_interfaz_ajustes');
    
    add_submenu_page('lr_ajustes_plugin', 'Ver Reclamaciones', 'Ver Reclamaciones 📂', 'manage_options', 'lr_ver_reclamaciones', 'lr_mostrar_tabla_reclamaciones');
}

add_action( 'admin_init', 'lr_registrar_ajustes_plugin' );
function lr_registrar_ajustes_plugin() {
    register_setting( 'lr_grupo_ajustes', 'lr_ruc_empresa' );
    register_setting( 'lr_grupo_ajustes', 'lr_razon_social' );
    register_setting( 'lr_grupo_ajustes', 'lr_email_destino' );
    add_settings_section( 'lr_seccion_principal', 'Configuración de Empresa', null, 'lr_ajustes_plugin' );
    add_settings_field( 'lr_campo_ruc', 'RUC', 'lr_html_ruc', 'lr_ajustes_plugin', 'lr_seccion_principal' );
    add_settings_field( 'lr_campo_razon', 'Razón Social', 'lr_html_razon', 'lr_ajustes_plugin', 'lr_seccion_principal' );
    add_settings_field( 'lr_campo_email', 'Email Notificación', 'lr_html_email', 'lr_ajustes_plugin', 'lr_seccion_principal' );
}
function lr_html_ruc() { echo '<input type="text" name="lr_ruc_empresa" value="'.esc_attr(get_option('lr_ruc_empresa')).'" class="regular-text">'; }
function lr_html_razon() { echo '<input type="text" name="lr_razon_social" value="'.esc_attr(get_option('lr_razon_social')).'" class="regular-text">'; }
function lr_html_email() { echo '<input type="email" name="lr_email_destino" value="'.esc_attr(get_option('lr_email_destino')).'" class="regular-text">'; }

function lr_generar_interfaz_ajustes() {
    echo '<div class="wrap"><h1>Configuración 📝</h1><form method="post" action="options.php">';
    settings_fields('lr_grupo_ajustes'); do_settings_sections('lr_ajustes_plugin'); submit_button();
    echo '</form></div>';
}

/**
 * --- 3. TABLA DE RECLAMACIONES ---
 */
function lr_mostrar_tabla_reclamaciones() {
    global $wpdb;
    $tabla = $wpdb->prefix . 'reclamaciones';

    if ( isset($_POST['lr_enviar_respuesta']) ) {
        $id = intval($_POST['reclamo_id']);
        $respuesta_admin = sanitize_textarea_field($_POST['lr_respuesta_texto']);
        $email_cliente = sanitize_email($_POST['lr_email_cliente']);
        $correlativo = sanitize_text_field($_POST['lr_correlativo']);

        $wpdb->update($tabla, array('estado' => 'Atendido'), array('id' => $id));

        $asunto = "Respuesta a su Reclamación: $correlativo";
        $mensaje = "Estimado cliente,\n\nSe ha dado respuesta a su reclamo con código $correlativo:\n\n";
        $mensaje .= "RESPUESTA:\n$respuesta_admin\n\nGracias por su comunicación.";
        
        wp_mail($email_cliente, $asunto, $mensaje);
        echo '<div class="updated"><p>¡Reclamo atendido con éxito! ✅</p></div>';
    }

    if ( isset($_GET['id_reclamo']) ) {
        $id = intval($_GET['id_reclamo']);
        $r = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tabla WHERE id = %d", $id) );
        if ($r) {
            echo '<div class="wrap"><h1>Detalle: ' . $r->correlativo . '</h1>';
            echo '<a href="?page=lr_ver_reclamaciones" class="button">⬅ Volver</a><br><br>';
            echo '<div style="background:white; padding:20px; border:1px solid #ccc; max-width:700px;">';
            $color_estado = ($r->estado == 'Pendiente') ? 'red' : 'green';
            echo "<strong>Estado:</strong> <span style='color:$color_estado; font-weight:bold;'>$r->estado</span><hr>";
            echo "<h3>1. Consumidor</h3><strong>Nombre:</strong> $r->nombre_completo<br><strong>DNI:</strong> $r->dni_ce<br>";
            echo "<h3>2. Bien</h3><strong>Tipo:</strong> $r->bien_tipo<br><strong>Monto:</strong> S/ $r->bien_monto<br>";
            echo "<h3>3. Detalle</h3><strong>Tipo:</strong> $r->tipo_incidencia<br><p>$r->detalle</p>";
            if ($r->estado == 'Pendiente') {
                echo '<hr><form method="post" action=""><input type="hidden" name="reclamo_id" value="'.$r->id.'"><input type="hidden" name="lr_email_cliente" value="'.$r->email_cliente.'"><input type="hidden" name="lr_correlativo" value="'.$r->correlativo.'"><textarea name="lr_respuesta_texto" style="width:100%; height:120px;" placeholder="Respuesta..." required></textarea><br><br><input type="submit" name="lr_enviar_respuesta" class="button button-primary" value="Enviar Respuesta ✅"></form>';
            }
            echo '</div></div>';
            return; 
        }
    }

    $resultados = $wpdb->get_results( "SELECT * FROM $tabla ORDER BY fecha DESC" );
    echo '<div class="wrap"><h1>Reclamaciones 📂</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>Fecha</th><th>Código</th><th>Cliente</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
    if ($resultados) {
        foreach ($resultados as $f) {
            $url_detalle = admin_url('admin.php?page=lr_ver_reclamaciones&id_reclamo=' . $f->id);
            $color_txt = ($f->estado == 'Pendiente') ? 'red' : 'green';
            echo "<tr><td>$f->fecha</td><td><strong>$f->correlativo</strong></td><td>$f->nombre_completo</td><td style='color:$color_txt; font-weight:bold;'>$f->estado</td><td><a href='$url_detalle' class='button button-primary'>Ver Detalles 🔍</a></td></tr>";
        }
    } else { echo '<tr><td colspan="5">No hay reclamos.</td></tr>'; }
    echo '</tbody></table></div>';
}

/**
 * --- 4. FORMULARIO (VISUALIZACIÓN) ---
 */
add_shortcode( 'libro_reclamaciones', 'lr_render_formulario_oficial' );
function lr_render_formulario_oficial() {
    ob_start();
    $ruc = get_option('lr_ruc_empresa');
    $razon = get_option('lr_razon_social');
    ?>
    <div class="lr-form-wrapper">
        <div class="lr-form-header">
            <h2 class="lr-form-title">HOJA DE RECLAMACIÓN</h2>
            <p class="lr-form-subtitle"><strong>Empresa:</strong> <?php echo esc_html($razon); ?> | <strong>RUC:</strong> <?php echo esc_html($ruc); ?></p>
        </div>

        <?php 
        // MOSTRAR MENSAJE DE ÉXITO SI EXISTE EN LA URL
        if ( isset( $_GET['enviado'] ) && $_GET['enviado'] === 'exito' ) : 
            $codigo = sanitize_text_field($_GET['nro']);
        ?>
            <div style='background: #dff0d8; color: #3c763d; padding: 15px; margin-bottom: 20px; text-align: center; border: 1px solid #d6e9c6;'>
                <strong>¡Enviado con éxito!</strong><br>Su código es: <strong><?php echo $codigo; ?></strong>.
            </div>
        <?php endif; ?>

        <form class="lr-reclamaciones-form" action="" method="post">
            <fieldset class="lr-form-fieldset">
                <legend class="lr-form-legend">1. Identificación del Consumidor</legend>
                <input class="lr-input-field" type="text" name="lr_nombre" placeholder="Nombres y Apellidos" required>
                <input class="lr-input-field lr-input-half lr-dni-width" type="text" name="lr_dni" placeholder="DNI / CE" required>
                <input class="lr-input-field lr-input-half lr-tel-width" type="text" name="lr_telefono" placeholder="Teléfono" required>
                <input class="lr-input-field" type="email" name="lr_email_cliente" placeholder="Correo electrónico" required>
                <input class="lr-input-field" type="text" name="lr_domicilio" placeholder="Dirección / Domicilio" required>
                <input class="lr-input-field" type="text" name="lr_representante" placeholder="Representante (Si es menor de edad)">
            </fieldset>

            <fieldset class="lr-form-fieldset">
                <legend class="lr-form-legend">2. Identificación del Bien Contratado</legend>
                <label class="lr-label-radio"><input type="radio" name="lr_bien_tipo" value="Producto" checked> Producto</label>
                <label class="lr-label-radio"><input type="radio" name="lr_bien_tipo" value="Servicio"> Servicio</label>
                <br><br>
                <input class="lr-input-field" type="text" name="lr_bien_monto" placeholder="Monto Reclamado (S/.)">
                <textarea class="lr-textarea-field lr-h-60" name="lr_bien_descripcion" placeholder="Descripción..."></textarea>
            </fieldset>

            <fieldset class="lr-form-fieldset">
                <legend class="lr-form-legend">3. Detalle de la Reclamación</legend>
                <label class="lr-label-radio"><input type="radio" name="lr_incidencia" value="Reclamo" checked> Reclamo</label>
                <label class="lr-label-radio"><input type="radio" name="lr_incidencia" value="Queja"> Queja</label>
                <br><br>
                <textarea class="lr-textarea-field lr-h-100" name="lr_detalle" placeholder="Detalle lo ocurrido..." required></textarea>
                <textarea class="lr-textarea-field lr-h-60" name="lr_pedido" placeholder="Pedido..." required></textarea>
            </fieldset>

            <p class="lr-legal-notice">* Respuesta en un plazo no mayor a 15 días hábiles.</p>
            
            <input type="text" name="lr_segundo_apellido" class="lr-campo-oculto" tabindex="-1" autocomplete="off" style="display:none !important;">
            
            <input class="lr-submit-btn" type="submit" name="lr_submit_oficial" value="ENVIAR HOJA DE RECLAMACIÓN">
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * --- 5. PROCESAMIENTO (LÓGICA ANTES DE CARGAR LA PÁGINA) ---
 */
add_action( 'template_redirect', 'lr_procesar_envio_datos_oficial' );
function lr_procesar_envio_datos_oficial() {
    if ( ! isset( $_POST['lr_submit_oficial'] ) ) return;

    // VALIDACIÓN HONEYPOT
    if ( ! empty( $_POST['lr_segundo_apellido'] ) ) {
        wp_die( 'Actividad sospechosa detectada. 🤖' );
    }

    global $wpdb;
    $tabla = $wpdb->prefix . 'reclamaciones';
    $correlativo = 'RE-' . date('Ymd') . '-' . rand(100, 999);
    
    $datos = array(
        'correlativo'      => $correlativo,
        'nombre_completo'  => sanitize_text_field($_POST['lr_nombre']),
        'dni_ce'           => sanitize_text_field($_POST['lr_dni']),
        'domicilio'        => sanitize_text_field($_POST['lr_domicilio']),
        'telefono'         => sanitize_text_field($_POST['lr_telefono']),
        'email_cliente'    => sanitize_email($_POST['lr_email_cliente']),
        'representante'    => sanitize_text_field($_POST['lr_representante']),
        'bien_tipo'        => sanitize_text_field($_POST['lr_bien_tipo']),
        'bien_monto'       => sanitize_text_field($_POST['lr_bien_monto']),
        'bien_descripcion' => sanitize_textarea_field($_POST['lr_bien_descripcion']),
        'tipo_incidencia'  => sanitize_text_field($_POST['lr_incidencia']),
        'detalle'          => sanitize_textarea_field($_POST['lr_detalle']),
        'pedido'           => sanitize_textarea_field($_POST['lr_pedido']),
        'estado'           => 'Pendiente'
    );

    $wpdb->insert($tabla, $datos);

    // Enviar correos
    $cuerpo = "Nuevo reclamo recibido: $correlativo\nCliente: " . $datos['nombre_completo'];
    wp_mail(get_option('lr_email_destino'), "NUEVO RECLAMO: $correlativo", $cuerpo);
    wp_mail($datos['email_cliente'], "Copia de su Reclamación: $correlativo", "Hemos recibido su reclamo.\n\n" . $cuerpo);

    // Redirección limpia
    $url_actual = strtok($_SERVER["REQUEST_URI"], '?'); 
    $url_exito = add_query_arg(array('enviado' => 'exito', 'nro' => $correlativo), home_url($url_actual));
    
    wp_redirect($url_exito);
    exit;
}