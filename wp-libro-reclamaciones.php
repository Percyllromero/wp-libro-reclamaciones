<?php
/**
 * Plugin Name: WP Libro de Reclamaciones
 * Description: Formulario oficial según normativa INDECOPI con registro en DB y avisos legales.
 * Version:     1.1.4
 * Author:      Percy Ll. Romero
 * License:     GPL2
 */


// Cargar la librería
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Configurar el buscador de actualizaciones
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Percyllromero/wp-libro-reclamaciones/', 
    __FILE__,
    'wp-libro-reclamaciones'
);

// FORZAR LA RAMA MAIN (Esto soluciona el error 404 de 'master')
$myUpdateChecker->setBranch('main');


/**
 * Enlazar estilos CSS al plugin con prioridad alta
 */
function wplr_cargar_estilos() {
    wp_enqueue_style(
        'wplr-estilos-globales', 
        plugin_dir_url( __FILE__ ) . 'css/style.css', 
        array(), 
        '1.1.4' // Cambia el número de versión cada vez que modifiques el CSS
    );
}

// Agregamos el 20 al final para que cargue DESPUÉS del tema
add_action( 'wp_enqueue_scripts', 'wplr_cargar_estilos', 20 );



if ( ! defined( 'ABSPATH' ) ) exit;

// --- 1. ACTIVACIÓN: CREAR TABLA CON TODOS LOS CAMPOS OFICIALES ---
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

// --- 2. PANEL ADMINISTRATIVO (IGUAL AL ANTERIOR) ---
add_action( 'admin_menu', 'lr_crear_menu_completo' );
function lr_crear_menu_completo() {
    add_menu_page('Libro de Reclamaciones', 'Reclamaciones 📝', 'manage_options', 'lr_ajustes_plugin', 'lr_generar_interfaz_ajustes', 'dashicons-clipboard', 60);
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

// --- 3. TABLA DE RECLAMACIONES (ACTUALIZADA) ---
function lr_mostrar_tabla_reclamaciones() {
    global $wpdb;
    $tabla = $wpdb->prefix . 'reclamaciones';

    // 1. LÓGICA PARA PROCESAR LA RESPUESTA (CAMBIAR A ATENDIDO)
    if ( isset($_POST['lr_enviar_respuesta']) ) {
        $id = intval($_POST['reclamo_id']);
        $respuesta_admin = sanitize_textarea_field($_POST['lr_respuesta_texto']);
        $email_cliente = sanitize_email($_POST['lr_email_cliente']);
        $correlativo = sanitize_text_field($_POST['lr_correlativo']);

        // Actualizamos el estado en la Base de Datos
        $wpdb->update($tabla, array('estado' => 'Atendido'), array('id' => $id));

        // Enviamos el correo de respuesta al cliente
        $asunto = "Respuesta a su Reclamación: $correlativo";
        $mensaje = "Estimado cliente,\n\nSe ha dado respuesta a su reclamo con código $correlativo:\n\n";
        $mensaje .= "RESPUESTA:\n$respuesta_admin\n\n";
        $mensaje .= "Gracias por su comunicación.";
        
        wp_mail($email_cliente, $asunto, $mensaje);

        echo '<div class="updated"><p>¡Reclamo marcado como Atendido y correo enviado con éxito! ✅</p></div>';
    }

    // 2. CASO A: VER DETALLE DE UN RECLAMO ESPECÍFICO
    if ( isset($_GET['id_reclamo']) ) {
        $id = intval($_GET['id_reclamo']);
        $r = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $tabla WHERE id = %d", $id) );

        if ($r) {
            echo '<div class="wrap"><h1>Detalle del Reclamo: ' . $r->correlativo . '</h1>';
            echo '<a href="?page=lr_ver_reclamaciones" class="button">⬅ Volver al listado</a><br><br>';
            echo '<div style="background:white; padding:20px; border:1px solid #ccc; max-width:700px;">';
            
            // Etiqueta de estado con color
            $color_estado = ($r->estado == 'Pendiente') ? 'red' : 'green';
            echo "<strong>Fecha:</strong> $r->fecha <br>";
            echo "<strong>Estado:</strong> <span style='color:$color_estado; font-weight:bold;'>$r->estado</span><hr>";
            
            echo "<h3>1. Identificación del Consumidor</h3>";
            echo "<strong>Nombre:</strong> $r->nombre_completo <br> <strong>DNI/CE:</strong> $r->dni_ce <br>";
            echo "<strong>Domicilio:</strong> $r->domicilio <br> <strong>Email:</strong> $r->email_cliente <br>";
            
            echo "<h3>2. Bien Contratado</h3>";
            echo "<strong>Tipo:</strong> $r->bien_tipo <br> <strong>Monto:</strong> S/ $r->bien_monto <br>";
            echo "<strong>Descripción:</strong> $r->bien_descripcion <br>";
            
            echo "<h3>3. Reclamación</h3>";
            echo "<strong>Tipo:</strong> $r->tipo_incidencia <br>";
            echo "<strong>Detalle:</strong><p>$r->detalle</p>";
            echo "<strong>Pedido:</strong><p>$r->pedido</p>";

            // FORMULARIO DE RESPUESTA: Solo aparece si el estado es 'Pendiente'
            if ($r->estado == 'Pendiente') {
                echo '<hr><h3>Enviar Respuesta al Cliente</h3>';
                echo '<form method="post" action="">';
                echo '<input type="hidden" name="reclamo_id" value="'.$r->id.'">';
                echo '<input type="hidden" name="lr_email_cliente" value="'.$r->email_cliente.'">';
                echo '<input type="hidden" name="lr_correlativo" value="'.$r->correlativo.'">';
                echo '<textarea name="lr_respuesta_texto" style="width:100%; height:120px;" placeholder="Escriba aquí la respuesta legal para el cliente..." required></textarea><br><br>';
                echo '<input type="submit" name="lr_enviar_respuesta" class="button button-primary" value="Enviar Respuesta y Marcar como Atendido ✅">';
                echo '</form>';
            } else {
                echo '<hr><p style="color:green; font-weight:bold;">Este reclamo ya ha sido atendido. ✅</p>';
            }

            echo '</div></div>';
            return; 
        }
    }

    // 3. CASO B: MOSTRAR EL LISTADO GENERAL
    $resultados = $wpdb->get_results( "SELECT * FROM $tabla ORDER BY fecha DESC" );
    echo '<div class="wrap"><h1>Reclamaciones Recibidas 📂</h1>';
    echo '<table class="wp-list-table widefat fixed striped"><thead><tr>
            <th>Fecha</th><th>Código</th><th>Cliente</th><th>Estado</th><th>Acciones</th>
          </tr></thead><tbody>';

    if ($resultados) {
        foreach ($resultados as $f) {
            $url_detalle = admin_url('admin.php?page=lr_ver_reclamaciones&id_reclamo=' . $f->id);
            $color_txt = ($f->estado == 'Pendiente') ? 'red' : 'green';
            
            echo "<tr>
                <td>$f->fecha</td>
                <td><strong>$f->correlativo</strong></td>
                <td>$f->nombre_completo</td>
                <td style='color:$color_txt; font-weight:bold;'>$f->estado</td>
                <td><a href='$url_detalle' class='button button-primary'>Ver Detalles 🔍</a></td>
            </tr>";
        }
    } else { echo '<tr><td colspan="5">No hay reclamos registrados.</td></tr>'; }
    echo '</tbody></table></div>';
}

// --- 4. EL FORMULARIO OFICIAL (CON SECCIONES) ---
add_shortcode( 'libro_reclamaciones', 'lr_render_formulario_oficial' );
function lr_render_formulario_oficial() {
    ob_start();
    $ruc = get_option('lr_ruc_empresa');
    $razon = get_option('lr_razon_social');
    ?>

    <div class="lr-form-wrapper">
        <div class="lr-form-header">
            <h2 class="lr-form-title">HOJA DE RECLAMACIÓN</h2>
            <p class="lr-form-subtitle">
                <strong>Empresa:</strong> <?php echo esc_html($razon); ?> | 
                <strong>RUC:</strong> <?php echo esc_html($ruc); ?>
            </p>
        </div>

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
                <textarea class="lr-textarea-field lr-h-60" name="lr_bien_descripcion" placeholder="Descripción del producto o servicio..."></textarea>
            </fieldset>

            <fieldset class="lr-form-fieldset">
                <legend class="lr-form-legend">3. Detalle de la Reclamación</legend>
                <label class="lr-label-radio"><input type="radio" name="lr_incidencia" value="Reclamo" checked> Reclamo</label>
                <label class="lr-label-radio"><input type="radio" name="lr_incidencia" value="Queja"> Queja</label>
                <br><br>
                <textarea class="lr-textarea-field lr-h-100" name="lr_detalle" placeholder="Detalle lo ocurrido..." required></textarea>
                <textarea class="lr-textarea-field lr-h-60" name="lr_pedido" placeholder="Pedido (¿Qué es lo que solicita?)" required></textarea>
            </fieldset>

            <p class="lr-legal-notice">* El proveedor deberá dar respuesta al reclamo en un plazo no mayor a quince (15) días hábiles.</p>
            <input class="lr-submit-btn" type="submit" name="lr_submit_oficial" value="ENVIAR HOJA DE RECLAMACIÓN">
        </form>
    </div>

    <?php
    lr_procesar_envio_oficial();
    return ob_get_clean();
}

// --- 5. PROCESAMIENTO CON TODOS LOS CAMPOS ---
function lr_procesar_envio_oficial() {
    // 1. Bloque de procesamiento (POST)
    if ( isset( $_POST['lr_submit_oficial'] ) ) {
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

        // Guardamos en la base de datos
        $wpdb->insert($tabla, $datos);

        // --- CONSTRUIMOS EL CUERPO DEL MENSAJE DETALLADO ---
        $cuerpo_comun = "DETALLES DEL RECLAMO:\n";
        $cuerpo_comun .= "----------------------------------\n";
        $cuerpo_comun .= "Código: " . $correlativo . "\n";
        $cuerpo_comun .= "Cliente: " . $datos['nombre_completo'] . " (Doc: " . $datos['dni_ce'] . ")\n";
        $cuerpo_comun .= "Email: " . $datos['email_cliente'] . " | Telf: " . $datos['telefono'] . "\n";
        $cuerpo_comun .= "Dirección: " . $datos['domicilio'] . "\n";
        $cuerpo_comun .= "Representante: " . ($datos['representante'] ?: 'N/A') . "\n\n";
        $cuerpo_comun .= "BIEN CONTRATADO:\n";
        $cuerpo_comun .= "Tipo: " . $datos['bien_tipo'] . " | Monto: S/ " . $datos['bien_monto'] . "\n";
        $cuerpo_comun .= "Descripción: " . $datos['bien_descripcion'] . "\n\n";
        $cuerpo_comun .= "DETALLE DE INCIDENCIA (" . $datos['tipo_incidencia'] . "):\n";
        $cuerpo_comun .= "Suceso: " . $datos['detalle'] . "\n";
        $cuerpo_comun .= "Pedido del cliente: " . $datos['pedido'] . "\n";
        $cuerpo_comun .= "----------------------------------";

        // Enviamos los correos
        wp_mail(get_option('lr_email_destino'), "NUEVO RECLAMO: $correlativo", $cuerpo_comun);
        wp_mail($datos['email_cliente'], "Copia de su Reclamación: $correlativo", "Hola, hemos recibido su mensaje.\n\n" . $cuerpo_comun);

        // REDIRECCIÓN: Limpiamos el envío para evitar duplicados al refrescar
        $url_exito = add_query_arg( array(
            'enviado' => 'exito',
            'nro' => $correlativo
        ), get_permalink() );

        wp_redirect( $url_exito );
        exit; 
    }

    // 2. Bloque de visualización (GET)
    if ( isset( $_GET['enviado'] ) && $_GET['enviado'] === 'exito' ) {
        $codigo = sanitize_text_field($_GET['nro']);
        echo "<div style='background: #dff0d8; color: #3c763d; padding: 15px; margin-top: 20px; text-align: center; border: 1px solid #d6e9c6;'>
                <strong>¡Enviado con éxito!</strong><br>Su código es: <strong>$codigo</strong>.
              </div>";
    }
}