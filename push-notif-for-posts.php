<?php
/*
Plugin Name:  Push Notifications For Posts
Plugin URI:   https://github.com/ealih/wp-push-notifications-for-posts
Description:  Push Notifications For Posts (pn4p)
Version:      1
Author:       esed.alih@gmail.com
Author URI:   https://esed.io
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  pn4p
Domain Path:  /languages
*/

if (!defined('ABSPATH')) exit;

define('PN4P_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('TAB_SETTINGS', 'settings');
define('TAB_REGISTRATIONS', 'registrations');
define('TAB_LOG', 'log');
define('PN4P_TABLE', 'pn4p');
define('PN4P_OPTIONS', 'pn4p_options');

global $pn4p_db_version;
$pn4p_db_version = '1.0';

register_activation_hook( __FILE__, 'pn4p_install');
function pn4p_install(){
	error_log("pn4p_install");

	global $wpdb;
	$table_name = $wpdb->prefix . PN4P_TABLE;

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		token varchar(255) NOT NULL,
		platform varchar(10) NOT NULL,
		device varchar(20) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

	add_option( "pn4p_db_version", "1.0" );
	//update_option("pn4p_db_version", $pn4p_db_version);

	$command = 'chmod +x '.PN4P_PLUGIN_PATH.'push-notif-sender';
	exec($command);
}

add_action('plugins_loaded', 'pn4p_update_db_check');
function pn4p_update_db_check() {
    global $pn4p_db_version;
    if ( get_site_option( 'pn4p_db_version' ) != $pn4p_db_version ) {
        //pn4p_install();
    }
}

register_deactivation_hook( __FILE__, 'pn4p_deactivation');
function pn4p_deactivation(){
	error_log("pn4p_deactivation");
}

register_uninstall_hook(__FILE__, 'pn4p_uninstall');
function pn4p_uninstall(){
	error_log("pn4p_uninstall");

	global $wpdb;
	$table_name = $wpdb->prefix . "pn4p";
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
    delete_option("my_plugin_db_version");
}

add_action('post_submitbox_misc_actions', 'pn4p_createCustomField');
function pn4p_createCustomField() {
	error_log("pn4p_createCustomField");
    $post_id = get_the_ID();
  
    if (get_post_type($post_id) != 'post') {
        return;
    }
  
    $value = get_post_meta($post_id, '_pn4p_push', true);
    wp_nonce_field('pn4p_nonce_'.$post_id, 'pn4p_nonce');
    ?>

    <div class="misc-pub-section misc-pub-section-last">
        <label><input type="checkbox" value="1" <?php checked($value, true, true); ?> name="_pn4p_push" /><?php _e('Send Push Notifications'); ?></label>
    </div>
    <?php
}

add_action('save_post', 'pn4p_saveCustomField');
function pn4p_saveCustomField($post_id){

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    error_log("pn4p_saveCustomField");
    
    if (!isset($_POST['pn4p_nonce']) 
    	|| !wp_verify_nonce($_POST['pn4p_nonce'], 'pn4p_nonce_'.$post_id)) {
        return;
    }
    
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['_pn4p_push'])) {

		error_log("_pn4p_push".$_POST['_pn4p_push']);

        update_post_meta($post_id, '_pn4p_push', $_POST['_pn4p_push']);

		global $wpdb;
		$options = get_option(PN4P_OPTIONS);

		$slug = get_post_field('post_name', get_post());
		$title = get_the_title($post_id);
		$summary = get_the_excerpt($post_id);
		$photo = wp_get_attachment_url(get_post_thumbnail_id());

		$command = PN4P_PLUGIN_PATH.'/push-notif-sender -db='.$wpdb->dbname.' -table='.$wpdb->prefix."pn4p".' -db-user='.$wpdb->dbuser.' -db-password="'.$wpdb->dbpassword.'" -post-id='.$post_id.' -post-slug="'.$slug.'" -post-title="'.$title.'" -post-summary="'.$summary.'" -post-photo="'.$photo.'" -plugin-path="'.PN4P_PLUGIN_PATH.'" -fcm-key="'.$options['fcm_key'].'" > /dev/null 2>&1 &';

		error_log("command:".$command);
			
		$out = shell_exec($command);

		error_log("external library output:".$out);

    } else {
        delete_post_meta($post_id, '_pn4p_push');
    }
}

function pn4p_save_token($data) {

	$token = $data['token'];
	$platform = $data['platform'];
	$device = $data['device'];

	error_log("pn4p_save_token token=$token platform=$platform device=$device");

	global $wpdb;
	$table_name = $wpdb->prefix . PN4P_TABLE;
	$wpdb->replace( 
		$table_name, 
		array( 
			'time' => current_time('mysql'), 
			'token' => $token, 
			'platform' => $platform, 
			'device' => $device, 
		) 
	);

	$response = array(
		'success' => true,
		'message' => 'Token registration successful');

	return rest_ensure_response($response);
}

add_action('rest_api_init', 'register_pn4p_endpoint');
function register_pn4p_endpoint(){
	error_log("register_pn4p_endpoint");

	register_rest_route( 'pn4p/v1', '/token', array(
	    'methods' => 'POST',
	    'callback' => 'pn4p_save_token',
	    'args' => array(
			'token' => array(
				'validate_callback' => function($param, $request, $key) {
					return strlen($param) > 0 && strlen($param) < 254;
		        }
			),
			'platform' => array(
		        'validate_callback' => function($param, $request, $key) {
					return $param === "ios" || $param === "android";
		        }
	    	),
			'device' => array(
		        'validate_callback' => function($param, $request, $key) {
		        	return strlen($param) > 0 && strlen($param) < 20;
		        }
		    ),
	    ),
  	));
}

add_action( 'admin_init', 'pn4p_settings_init' );
function pn4p_settings_init() {

	register_setting('pn4p', PN4P_OPTIONS);

	add_settings_section(
	'pn4p_section',
	__('', 'pn4p'),
	'pn4p_section_cb',
	'pn4p'
 	);
 
 add_settings_field(
	 'pn4p_field_fcm_key',
	 __('FCM Server Key', 'pn4p' ),
	 'pn4p_field_fcm_key_cb',
	 'pn4p',
	 'pn4p_section',
	 [
		 'label_for' => 'pn4p_field_fcm_key',
		 'class' => 'pn4p_row',
		'setting_key' => 'fcm_key',
	 ]
	 );

 add_settings_field(
	 'pn4p_field_apn_key',
	 __('APN Server Key', 'pn4p' ),
	 'pn4p_field_apn_key_cb',
	 'pn4p',
	 'pn4p_section',
	 [
		 'label_for' => 'pn4p_field_apn_key',
		 'class' => 'pn4p_row',
		'setting_key' => 'apn_key',
	 ]
	 );
}
 
add_action('admin_init', 'pn4p_settings_init');
 
function pn4p_section_cb($args) {
	
}
  
function pn4p_field_fcm_key_cb($args) {

	$options = get_option(PN4P_OPTIONS);
	?>

	<input 
	type="text"
	size="100"
	name="pn4p_options[<?php echo esc_attr( $args['setting_key'] ); ?>]"
	value="<?php echo esc_attr($options[ $args['setting_key'] ] ); ?>"
	/>

	<p class="description">
	<?php _e('FCM Server Key For Android App.'); ?> <a href="https://firebase.google.com/docs/cloud-messaging/android/client"><?php _e('Details here'); ?></a>
	</p>
	<?php
}

function pn4p_field_apn_key_cb( $args ) {

	$options = get_option(PN4P_OPTIONS);
	?>

	<input 
	type="text"
	size="100"
	name="pn4p_options[<?php echo esc_attr( $args['setting_key'] ); ?>]"
	value="<?php echo esc_attr($options[ $args['setting_key'] ] ); ?>"
	/>

	<p class="description">
	<?php _e('FCM Server Key For iOS App.'); ?> <a href="https://firebase.google.com/docs/cloud-messaging/ios/client"><?php _e('Details here'); ?></a>
	</p>
	<?php
}
 
add_action('admin_menu', 'pn4p_options_page');
function pn4p_options_page() {
	add_menu_page(
		'Push Notifications',
		'Push Notifications',
		'manage_options',
		'pn4p',
		'pn4p_options_page_html'
	);
}

function pn4p_options_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $pn4p_active_tab;
	$pn4p_active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
	?>

	<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<h2 class="nav-tab-wrapper">
	<?php
		do_action('pn4p_tabs');
	?>
	</h2>
	<?php
	do_action('pn4p_tabs_content');
	?>
	</div>
	<?php
}
 
// Tabs
add_action('pn4p_tabs', 'pn4p_settigs_tab', 1);
function pn4p_settigs_tab(){
	global $pn4p_active_tab; ?>
	<a class="nav-tab <?php echo $pn4p_active_tab == TAB_SETTINGS || '' ? 'nav-tab-active' : ''; ?>" href="<?php echo admin_url('admin.php?page=pn4p&tab='.TAB_SETTINGS); ?>"><?php _e('Settings'); ?> </a>
	<?php
}

add_action( 'pn4p_tabs', 'pn4p_registrations_tab', 2 );
function pn4p_registrations_tab(){
	global $pn4p_active_tab; ?>
	<a class="nav-tab <?php echo $pn4p_active_tab == TAB_REGISTRATIONS ? 'nav-tab-active' : ''; ?>" href="<?php echo admin_url('admin.php?page=pn4p&tab='.TAB_REGISTRATIONS); ?>"><?php _e('Registered Tokens'); ?> </a>
	<?php
}

add_action( 'pn4p_tabs', 'pn4p_log_tab', 3 );
function pn4p_log_tab(){
	global $pn4p_active_tab; ?>
	<a class="nav-tab <?php echo $pn4p_active_tab == TAB_LOG ? 'nav-tab-active' : ''; ?>" href="<?php echo admin_url('admin.php?page=pn4p&tab='.TAB_LOG); ?>"><?php _e('Log'); ?> </a>
	<?php
}

// Tabs content
add_action('pn4p_tabs_content', 'pn4p_settings_tab_content');
function pn4p_settings_tab_content() {
	global $pn4p_active_tab;

	if ( '' || TAB_SETTINGS != $pn4p_active_tab ) {
		return;
	}
	
	if ( isset( $_GET['settings-updated'] ) ) {
		add_settings_error( 'pn4p_messages', 'pn4p_message', __( 'Settings Saved', 'pn4p' ), 'updated' );
	}

	settings_errors( 'pn4p_messages' );
	?>

	<form action="options.php" method="post">

	<?php

	settings_fields('pn4p');

	do_settings_sections( 'pn4p' );

	submit_button( 'Save Settings' );

	?>

	</form>
	</div>

	<?php
}

add_action( 'pn4p_tabs_content', 'pn4p_registrations_tab_content' );
function pn4p_registrations_tab_content() {

	global $pn4p_active_tab;
	if ( TAB_REGISTRATIONS != $pn4p_active_tab )
		return;

	global $wpdb;
	$table_name = $wpdb->prefix . PN4P_TABLE;

	$countAndroid = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE platform='android'");
	$countIos = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE platform='ios'");

	$totalPages = ceil( ($countAndroid + $countIos) / 100);

	if($totalPages == 0){
		$totalPages = 1;	
	}

	?>

	<p><strong><?php echo $countAndroid; ?></strong> Android devices, <strong><?php echo $countIos; ?></strong> iOS devices, <strong><?php echo $countIos + $countAndroid; ?></strong> total devices</p>

	<table class="widefat fixed" cellspacing="0">
		<thead>
		<tr>
			<td>Device</td>
			<td>Platform</td>
			<td>Updated</td>
		</tr>
		</thead>

	<?php 

	$page = isset( $_GET['tokens-page'] ) ? $_GET['tokens-page'] : 1;

	if($page <= 0) {
		$page = 1;	
	} elseif ($page > $totalPages) {
		$page = $totalPages;
	}

	$limit = 100;
	$offset = ($page - 1) * 100;

	$results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY time DESC LIMIT $offset, $limit");

	foreach ($results as $result) { 
		echo "<tr><td>$result->device</td><td>$result->platform</td><td>$result->time</td></tr>";
	} 

	$backUrl = admin_url('admin.php?page=pn4p&tab=registrations&tokens-page='.($page - 1));
	$nextUrl = admin_url('admin.php?page=pn4p&tab=registrations&tokens-page='.($page + 1));

	if($page > 1){
		$back = '<a href="'.$backUrl.'">Previous</a> ';
	} else {
		$back = "";
	}

	if($page < $totalPages){
		$next = ' <a href="'.$nextUrl.'">Next</a>';
	} else {
		$next = "";
	}

	?>
		
</table>

	<p> <?php echo $back; echo $page; ?> of <?php echo $totalPages; echo $next; ?></p>
	
	<?php
}

add_action('pn4p_tabs_content', 'pn4p_log_tab_content');
function pn4p_log_tab_content() {

	global $pn4p_active_tab;
	if (TAB_LOG != $pn4p_active_tab) {
		return;
	}

	?>
	
	<p id="log-content">Loading...</p>

	<?php
}

add_action('admin_footer', 'fetch_log_ajax' ); // Write our JS below here
function fetch_log_ajax() { 

	// Only for LOG tab
	global $pn4p_active_tab;
	if (TAB_LOG != $pn4p_active_tab) {
		return;
	}

	?>
	<script type="text/javascript" >

	var fetchLog = function() {

		var data = {
			'action': 'fetch_log_action'
		};

		// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
		jQuery.post(ajaxurl, data, function(response) {
			jQuery("#log-content").html(response);
			setTimeout(fetchLog, 2000);
		});
	};

	jQuery(document).ready(function($){
		fetchLog();
	});

	</script> 
	<?php
}

add_action('wp_ajax_fetch_log_action', 'fetch_log_action');
function fetch_log_action() {

	$logFile = PN4P_PLUGIN_PATH.'/push-notif-sender.log';

	if(file_exists($logFile)) {
		$log = file_get_contents($logFile);
		$log = str_replace("\n", "<br><br>", $log);
	} else {
		$log = "No log file found";
	}

    echo $log;

	wp_die(); // this is required to terminate immediately and return a proper response
}
 
