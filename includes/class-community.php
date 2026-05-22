<?php
namespace TEC_Addons;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Community {

	// Standard-Quelle (wird automatisch angelegt, falls nicht vorhanden)
	const DEFAULT_SOURCE_NAME = 'Kulturstiftung Seevetal';

	/* =======================
	 * Bootstrap
	 * ======================= */
	public static function init(){
		add_action('init', [__CLASS__, 'register_taxonomies'], 5);

		add_action('admin_menu', [__CLASS__, 'menu']);
		add_action('admin_init', [__CLASS__, 'settings']);

		add_action('admin_post_tec_addons_comm_save',         [__CLASS__, 'admin_save_mapping']);
		add_action('admin_post_tec_addons_comm_delete',       [__CLASS__, 'admin_delete_mapping']);
		add_action('admin_post_tec_addons_comm_add_source',   [__CLASS__, 'admin_add_source']);
		add_action('admin_post_tec_addons_comm_sync_sources', [__CLASS__, 'admin_sync_sources']);

		add_shortcode('tec_addons_submit_event', [__CLASS__, 'shortcode_form']);
		add_action('admin_post_tec_addons_submit_event', [__CLASS__, 'handle_submit']);

		add_action('template_redirect', [__CLASS__, 'maybe_render_edit_form']);
		add_filter('login_redirect', [__CLASS__, 'force_login_redirect'], 9999, 3);

		add_action('admin_post_tec_comm_quick_publish', [__CLASS__, 'quick_publish']);

		self::maybe_seed_templates();
	}

	public static function register_taxonomies(){
		if ( ! taxonomy_exists('event_source') ){
			register_taxonomy('event_source', ['tribe_events'], [
				'label' => __('Quellen','tec-add-ons'),
				'public' => true,
				'show_ui' => true,
				'show_admin_column' => true,
				'hierarchical' => false,
				'rewrite' => false,
				'show_in_rest' => true,
			]);
		}
		// Default-Quelle sicherstellen
		self::ensure_default_source_exists();
	}

	private static function ensure_default_source_exists(){
		if ( ! taxonomy_exists('event_source') ) return;
		$term = term_exists(self::DEFAULT_SOURCE_NAME, 'event_source');
		if ( ! $term ){
			wp_insert_term(self::DEFAULT_SOURCE_NAME, 'event_source');
		}
	}

	public static function maybe_install_tables(){
		global $wpdb; $table = $wpdb->prefix.'tec_addons_submitters';
		$exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
		if ( $exists === $table ) return;
		require_once ABSPATH.'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL UNIQUE,
			source_term_id BIGINT UNSIGNED NULL,
			whitelist TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY source_idx (source_term_id)
		) {$charset};";
		dbDelta($sql);
	}

	/* =======================
	 * Admin UI
	 * ======================= */
	public static function menu(){
		add_submenu_page(
			'tec-add-ons',
			__('Community','tec-add-ons'),
			__('Community','tec-add-ons'),
			'manage_options',
			'tec-add-ons-community',
			[__CLASS__,'render_admin']
		);
	}

	public static function settings(){
		register_setting('tec_addons_comm','tec_addons_comm_tpl_submitter',['type'=>'string','default'=>'']);
		register_setting('tec_addons_comm','tec_addons_comm_tpl_admin',['type'=>'string','default'=>'']);
	}

	protected static function maybe_seed_templates(){
		if ( ! get_option('tec_addons_comm_tpl_submitter') ){
			update_option('tec_addons_comm_tpl_submitter',
				"Hallo {name},\n\nvielen Dank für Ihre Einreichung „{event_title}“. Wir prüfen die Veranstaltung und melden uns.\n\nBearbeiten (solange nicht freigegeben): {edit_link}\n\nBeste Grüße\n{site_name}"
			);
		}
		if ( ! get_option('tec_addons_comm_tpl_admin') ){
			update_option('tec_addons_comm_tpl_admin',
				"Neue Community-Einreichung:\n\nTitel: {event_title}\nDatum: {event_start}\nQuelle: {event_source}\nEinreicher: {submitter_name} <{submitter_email}>\n\nPrüfen/Freigeben (Editor): {admin_edit_link}\nDirekt freigeben: {approve_link}\n\nMögliche Duplikate:\n{duplicates}"
			);
		}
	}

	public static function render_admin(){
		if ( ! current_user_can('manage_options') ) return;

		$users   = get_users(['fields'=>['ID','display_name','user_email'],'orderby'=>'display_name','order'=>'ASC']);
		$sources = self::get_all_sources();
		$map     = self::get_all_mappings();
		$tpl_user  = get_option('tec_addons_comm_tpl_submitter');
		$tpl_admin = get_option('tec_addons_comm_tpl_admin');

		?>
		<div class="wrap">
			<h1><?php esc_html_e('Community – Nutzer & Quellen','tec-add-ons'); ?></h1>

			<h2><?php esc_html_e('Quellen verwalten (event_source)','tec-add-ons'); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:1em">
				<?php wp_nonce_field('tec_addons_comm_add_source','_tec_comm_src_nonce'); ?>
				<input type="hidden" name="action" value="tec_addons_comm_add_source">
				<input type="text" name="new_source_name" placeholder="<?php esc_attr_e('Neue Quelle (Name)','tec-add-ons'); ?>" required>
				<button class="button"><?php esc_html_e('Quelle hinzufügen','tec-add-ons'); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:2em">
				<?php wp_nonce_field('tec_addons_comm_sync_sources','_tec_comm_sync_nonce'); ?>
				<input type="hidden" name="action" value="tec_addons_comm_sync_sources">
				<button class="button"><?php esc_html_e('Aus Mailing-Quellen synchronisieren','tec-add-ons'); ?></button>
				<p class="description"><?php esc_html_e('Importiert fehlende Quellen aus „TEC add ons → Mailing → Zusätzliche Quellen“.','tec-add-ons'); ?></p>
			</form>

			<h2><?php esc_html_e('Zuordnung: WP-User ↔ Standard-Quelle','tec-add-ons'); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
				<?php wp_nonce_field('tec_addons_comm_save','_tec_comm_nonce'); ?>
				<input type="hidden" name="action" value="tec_addons_comm_save">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Benutzer','tec-add-ons'); ?></th>
						<td>
							<select name="user_id">
								<?php foreach($users as $u): ?>
									<option value="<?php echo intval($u->ID); ?>">
										<?php echo esc_html($u->display_name.' <'.$u->user_email.'>'); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Standard-Quelle (event_source)','tec-add-ons'); ?></th>
						<td>
							<select name="source_term_id">
								<option value=""><?php esc_html_e('— Keine —','tec-add-ons'); ?></option>
								<?php foreach($sources as $t): ?>
									<option value="<?php echo intval($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e('Vorauswahl im Formular; Nutzer kann Quelle ändern.','tec-add-ons'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Whitelist (Auto-Freigabe)','tec-add-ons'); ?></th>
						<td><label><input type="checkbox" name="whitelist" value="1"> <?php esc_html_e('Einreichungen werden automatisch veröffentlicht.','tec-add-ons'); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __('Zuordnung speichern','tec-add-ons') ); ?>
			</form>

			<h3><?php esc_html_e('Bestehende Zuordnungen','tec-add-ons'); ?></h3>
			<table class="widefat striped" style="margin-bottom:2em">
				<thead><tr><th>User</th><th>E-Mail</th><th>Quelle</th><th>Whitelist</th><th>Aktionen</th></tr></thead>
				<tbody>
					<?php if(empty($map)): ?>
						<tr><td colspan="5"><?php esc_html_e('Keine Einträge.','tec-add-ons'); ?></td></tr>
					<?php else: foreach($map as $row): ?>
						<tr>
							<td><?php echo esc_html($row['display_name']); ?></td>
							<td><?php echo esc_html($row['user_email']); ?></td>
							<td><?php echo $row['source_term'] ? esc_html($row['source_term']->name) : '<em>'.esc_html__('—','tec-add-ons').'</em>'; ?></td>
							<td><?php echo $row['whitelist'] ? '✓' : '–'; ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=tec_addons_comm_delete&user_id='.$row['user_id']), 'tec_addons_comm_delete_'.$row['user_id'] ) ); ?>" onclick="return confirm('<?php echo esc_js(__('Eintrag löschen?','tec-add-ons')); ?>');"><?php esc_html_e('Löschen','tec-add-ons'); ?></a>
							</td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<hr>
			<h2><?php esc_html_e('E-Mail-Vorlagen','tec-add-ons'); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields('tec_addons_comm'); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Bestätigung an Einreicher','tec-add-ons'); ?></th>
						<td>
							<textarea name="tec_addons_comm_tpl_submitter" class="large-text code" rows="8"><?php echo esc_textarea($tpl_user); ?></textarea>
							<p class="description"><?php esc_html_e('Platzhalter: {name}, {email}, {event_title}, {event_start}, {edit_link}, {site_name}','tec-add-ons'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Benachrichtigung an Admin','tec-add-ons'); ?></th>
						<td>
							<textarea name="tec_addons_comm_tpl_admin" class="large-text code" rows="10"><?php echo esc_textarea($tpl_admin); ?></textarea>
							<p class="description"><?php esc_html_e('Platzhalter: {event_title}, {event_start}, {event_source}, {submitter_name}, {submitter_email}, {admin_edit_link}, {approve_link}, {duplicates}','tec-add-ons'); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __('Vorlagen speichern','tec-add-ons') ); ?>
			</form>
		</div>
		<?php
	}

	/* ==== Admin Actions: Quellen hinzufügen / syncen ==== */
	public static function admin_add_source(){
		if ( ! current_user_can('manage_options') ) wp_die('no');
		check_admin_referer('tec_addons_comm_add_source','_tec_comm_src_nonce');
		$name = sanitize_text_field($_POST['new_source_name'] ?? '');
		if($name){
			self::ensure_default_source_exists();
			if ( ! term_exists($name,'event_source') ){
				wp_insert_term($name,'event_source');
			}
		}
		wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
	}
	public static function admin_sync_sources(){
		if ( ! current_user_can('manage_options') ) wp_die('no');
		check_admin_referer('tec_addons_comm_sync_sources','_tec_comm_sync_nonce');
		self::ensure_default_source_exists();
		$csv = get_option('tec_addons_sources_custom','');
		$list = array_filter(array_map('trim', explode(',',$csv)));
		foreach($list as $name){
			if ( $name && ! term_exists($name,'event_source') ){
				wp_insert_term($name,'event_source');
			}
		}
		wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
	}

	/* ==== Admin Mapping speichern/löschen ==== */
	public static function admin_save_mapping(){
		if ( ! current_user_can('manage_options') ) wp_die('no');
		check_admin_referer('tec_addons_comm_save','_tec_comm_nonce');
		self::ensure_default_source_exists();
		$user_id = absint($_POST['user_id'] ?? 0);
		$term_id = absint($_POST['source_term_id'] ?? 0);
		$whitelist = ! empty($_POST['whitelist']) ? 1 : 0;
		if ( ! $user_id ) wp_redirect( wp_get_referer() ?: admin_url('admin.php?page=tec-add-ons-community') );
		global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
		self::maybe_install_tables();
		$row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d",$user_id) );
		$data = [
			'user_id'=>$user_id,
			'source_term_id'=>$term_id ?: null,
			'whitelist'=>$whitelist,
			'updated_at'=>current_time('mysql'),
		];
		if ( $row ) { $wpdb->update($table,$data,['user_id'=>$user_id]); }
		else { $data['created_at']=current_time('mysql'); $wpdb->insert($table,$data); }
		wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
	}
	public static function admin_delete_mapping(){
		if ( ! current_user_can('manage_options') ) wp_die('no');
		$user_id = absint($_GET['user_id'] ?? 0);
		check_admin_referer('tec_addons_comm_delete_'.$user_id);
		if ( $user_id ){
			global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
			$wpdb->delete($table,['user_id'=>$user_id]);
		}
		wp_redirect( admin_url('admin.php?page=tec-add-ons-community') ); exit;
	}

	/* =======================
	 * Helpers
	 * ======================= */
	protected static function get_all_mappings(){
		global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
		self::maybe_install_tables();
		$out=[];
		$rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY updated_at DESC");
		if($rows){
			foreach($rows as $r){
				$u = get_user_by('id',$r->user_id);
				$term = $r->source_term_id ? get_term($r->source_term_id,'event_source') : null;
				if(!$u) continue;
				$out[]=[
					'user_id'=>$r->user_id,
					'display_name'=>$u->display_name,
					'user_email'=>$u->user_email,
					'source_term'=>$term && !is_wp_error($term) ? $term : null,
					'whitelist'=> (int)$r->whitelist,
				];
			}
		}
		return $out;
	}
	protected static function get_source_for_user($user_id){
		// Nutzerzuordnung oder Default-Quelle
		global $wpdb; $table=$wpdb->prefix.'tec_addons_submitters';
		$row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d",$user_id) );
		if($row){
			return [ $row->source_term_id ? get_term( (int)$row->source_term_id, 'event_source') : null, !!$row->whitelist ];
		}
		// Fallback: Default-Quelle
		self::ensure_default_source_exists();
		$term = get_term_by('name', self::DEFAULT_SOURCE_NAME, 'event_source');
		return [$term ?: null, false];
	}
	protected static function get_all_sources(){
		self::ensure_default_source_exists();
		$terms = get_terms(['taxonomy'=>'event_source','hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
		return is_array($terms) ? $terms : [];
	}
	protected static function get_all_categories(){
		$terms = get_terms(['taxonomy'=>'tribe_events_cat','hide_empty'=>false]);
		return is_array($terms) ? $terms : [];
	}
	protected static function get_all_venues(){
		$q = new \WP_Query(['post_type'=>'tribe_venue','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
		return $q->posts;
	}
	protected static function get_all_organizers(){
		$q = new \WP_Query(['post_type'=>'tribe_organizer','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','fields'=>'ids']);
		return $q->posts;
	}
	protected static function find_organizer_by_name($name){
		$post = get_page_by_title($name, OBJECT, 'tribe_organizer');
		return $post ? $post->ID : 0;
	}

	/* =======================
	 * Shortcode & Form
	 * ======================= */
	public static function shortcode_form($atts){
		$current_url = is_singular()
			? get_permalink()
			: ( home_url(add_query_arg([],$_SERVER['REQUEST_URI'] ?? '/')) );

		if ( ! is_user_logged_in() ){
			$login_url = wp_login_url( $current_url );
			return '<div class="tec-comm tec-comm--login-needed" style="padding:1rem;border:1px solid #eee;border-radius:8px">'.
				'<strong>'.esc_html__('Bitte einloggen.','tec-add-ons').'</strong> '.
				'<a href="'.esc_url($login_url).'">'.esc_html__('Zum Login »','tec-add-ons').'</a>'.
				'</div>';
		}

		$user = wp_get_current_user();
		list($default_source_term,) = self::get_source_for_user($user->ID);
		$sources = self::get_all_sources();
		$cats    = self::get_all_categories();
		$venues  = self::get_all_venues();
		$orgs    = self::get_all_organizers();

		$default_org = 0;
		if ( $default_source_term ) $default_org = self::find_organizer_by_name( $default_source_term->name );

		$ok_msg = '';
		if ( isset($_GET['tec-comm-ok']) && $_GET['tec-comm-ok']=='1' ){
			$ok_msg = '<div class="notice notice-success" style="padding:.6rem 1rem;margin-bottom:1rem;border-left:4px solid #46b450;background:#f6fff6">'.esc_html__('Danke! Ihre Veranstaltung wurde gespeichert und wird geprüft.','tec-add-ons').'</div>';
		}

		ob_start(); ?>
		<style>
		.tec-comm-form input[type=text],
		.tec-comm-form input[type=date],
		.tec-comm-form input[type=time],
		.tec-comm-form textarea,
		.tec-comm-form select { color:#000 !important; background:#fff !important; }
		.tec-comm-form label { color:#000; display:block; margin:.4rem 0; }
		.tec-comm-form .row { display:flex; gap:12px; flex-wrap:wrap; }
		.tec-comm-form .row > div { flex:1 1 220px; }
		.tec-comm-form .cats label { display:inline-flex; align-items:center; gap:6px; margin:0 12px 8px 0; cursor:pointer; }
		.tec-comm-form input[type=checkbox]{ appearance:auto !important; -webkit-appearance:checkbox !important; -moz-appearance:checkbox !important; opacity:1 !important; position:static !important; width:auto !important; height:auto !important; display:inline-block !important; }
		</style>

		<?php echo $ok_msg; ?>
		<form class="tec-comm-form" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post" enctype="multipart/form-data">
			<input type="hidden" name="action" value="tec_addons_submit_event">
			<?php wp_nonce_field('tec_addons_submit_event','_tec_comm_nonce'); ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $current_url ); ?>">

			<p><label><?php esc_html_e('Titel','tec-add-ons'); ?> *<br>
				<input type="text" name="event_title" required style="width:100%"></label></p>

			<p><label><input type="checkbox" name="all_day" value="1"> <?php esc_html_e('Ganztägig','tec-add-ons'); ?></label></p>

			<div class="row">
				<div><label><?php esc_html_e('Start (Datum)','tec-add-ons'); ?> *<br>
					<input type="date" name="start_date" required></label></div>
				<div><label><?php esc_html_e('Start (Uhrzeit)','tec-add-ons'); ?> *<br>
					<input type="time" name="start_time" required></label></div>
				<div><label><?php esc_html_e('Ende (Datum)','tec-add-ons'); ?><br>
					<input type="date" name="end_date"></label></div>
				<div><label><?php esc_html_e('Ende (Uhrzeit)','tec-add-ons'); ?><br>
					<input type="time" name="end_time"></label></div>
			</div>

			<hr>
			<h3><?php esc_html_e('Ort','tec-add-ons'); ?></h3>
			<div class="row">
				<div><label><?php esc_html_e('Bestehenden Ort wählen','tec-add-ons'); ?><br>
					<select name="existing_venue_id">
						<option value=""><?php esc_html_e('— Keiner —','tec-add-ons'); ?></option>
						<?php foreach($venues as $vid): ?>
							<option value="<?php echo intval($vid); ?>"><?php echo esc_html( get_the_title($vid) ); ?></option>
						<?php endforeach; ?>
					</select></label></div>
				<div><label><?php esc_html_e('Neuer Ort (Name)','tec-add-ons'); ?><br>
					<input type="text" name="new_venue_name"></label></div>
				<div><label><?php esc_html_e('Adresse (optional)','tec-add-ons'); ?><br>
					<input type="text" name="new_venue_address"></label></div>
			</div>

			<hr>
			<h3><?php esc_html_e('Veranstalter','tec-add-ons'); ?></h3>
			<div class="row">
				<div><label><?php esc_html_e('Bestehenden Veranstalter wählen','tec-add-ons'); ?><br>
					<select name="existing_org_id">
						<option value=""><?php esc_html_e('— Keiner —','tec-add-ons'); ?></option>
						<?php foreach($orgs as $oid): ?>
							<option value="<?php echo intval($oid); ?>" <?php selected( $default_org === $oid ); ?>><?php echo esc_html( get_the_title($oid) ); ?></option>
						<?php endforeach; ?>
					</select></label></div>
				<div><label><?php esc_html_e('Neuer Veranstalter (Name)','tec-add-ons'); ?><br>
					<input type="text" name="new_org_name" value="<?php echo esc_attr( $default_org ? '' : ( $default_source_term ? $default_source_term->name : '' ) ); ?>"></label></div>
			</div>

			<hr>
			<p><label><?php esc_html_e('Beschreibung','tec-add-ons'); ?> *<br>
				<textarea name="event_content" rows="8" style="width:100%" required></textarea></label></p>

			<p><strong><?php esc_html_e('Kategorien','tec-add-ons'); ?></strong></p>
			<div class="cats">
				<?php foreach($cats as $t): ?>
					<label><input type="checkbox" name="event_cats[]" value="<?php echo esc_attr($t->term_id); ?>"> <?php echo esc_html($t->name); ?></label>
				<?php endforeach; ?>
			</div>

			<p><label><?php esc_html_e('Quelle (event_source)','tec-add-ons'); ?><br>
				<select name="event_source">
					<?php foreach($sources as $s): ?>
						<option value="<?php echo esc_attr($s->term_id); ?>" <?php selected( $default_source_term && $default_source_term->term_id===$s->term_id ); ?>>
							<?php echo esc_html($s->name); ?>
						</option>
					<?php endforeach; ?>
				</select></label>
			</p>

			<p><label><?php esc_html_e('Bild (optional)','tec-add-ons'); ?><br>
				<input type="file" name="event_image" accept="image/*"></label></p>

			<p><button type="submit" class="button button-primary"><?php esc_html_e('Veranstaltung einreichen','tec-add-ons'); ?></button></p>
		</form>

		<script>
		(function(){
			function pad(n){ return (n<10?'0':'')+n; }
			function prefEnd(){
				var sd=document.querySelector('input[name=start_date]');
				var st=document.querySelector('input[name=start_time]');
				var ed=document.querySelector('input[name=end_date]');
				var et=document.querySelector('input[name=end_time]');
				if(!sd || !st || !ed || !et) return;
				if(!sd.value || !st.value) return;
				// nur vorbelegen, wenn Endfelder leer sind
				if(!ed.value){ ed.value = sd.value; }
				if(!et.value){
					var t=st.value.split(':'); if(t.length<2) return;
					var h=parseInt(t[0],10), m=parseInt(t[1],10);
					h=(h+1)%24;
					et.value = pad(h)+':'+pad(m);
				}
			}
			// beim Laden versuchen (falls Browser Autocomplete etc.)
			document.addEventListener('DOMContentLoaded', prefEnd);
			// auf Änderungen an Startfeldern reagieren (change + input)
			['change','input'].forEach(function(evt){
				document.addEventListener(evt, function(e){
					if(e.target && (e.target.name==='start_date' || e.target.name==='start_time')) prefEnd();
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	/* =======================
	 * Submit Handler
	 * ======================= */
	public static function handle_submit(){
		if ( ! is_user_logged_in() ) wp_die( __('Bitte einloggen.','tec-add-ons') );
		if ( ! isset($_POST['_tec_comm_nonce']) || ! wp_verify_nonce( $_POST['_tec_comm_nonce'], 'tec_addons_submit_event' ) ) wp_die('no');

		$redirect = !empty($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : home_url('/');

		$user = wp_get_current_user();
		list($default_source_term, $whitelist) = self::get_source_for_user($user->ID);

		$title    = sanitize_text_field($_POST['event_title'] ?? '');
		$all_day  = !empty($_POST['all_day']);
		$cats     = array_map('absint', $_POST['event_cats'] ?? []);
		$source_term_id = absint( $_POST['event_source'] ?? 0 );

		// Fallbacks für Quelle: User-Standard → Default-Quelle
		if (!$source_term_id && $default_source_term) $source_term_id = $default_source_term->term_id;
		if (!$source_term_id){
			self::ensure_default_source_exists();
			$def = get_term_by('name', self::DEFAULT_SOURCE_NAME, 'event_source');
			if ( $def && ! is_wp_error($def) ) $source_term_id = (int)$def->term_id;
		}

		$sd = sanitize_text_field($_POST['start_date'] ?? '');
		$st = sanitize_text_field($_POST['start_time'] ?? '');
		$ed = sanitize_text_field($_POST['end_date'] ?? '');
		$et = sanitize_text_field($_POST['end_time'] ?? '');
		if ( empty($sd) || empty($st) || empty($title) ) {
			wp_safe_redirect( add_query_arg('tec-comm-ok','0',$redirect) ); exit;
		}
		$start_ts = strtotime($sd.' '.$st);
		$start_mysql = date('Y-m-d H:i:00', $start_ts);

		// Serverseitiger Default: wenn Ende leer und nicht ganztägig → +1h
		if ( $all_day ){
			$end_mysql = $start_mysql;
		} else {
			if ( $ed || $et ){
				$end_mysql = date('Y-m-d H:i:00', strtotime(($ed?:$sd).' '.($et?:$st)));
			} else {
				$end_mysql = date('Y-m-d H:i:00', $start_ts + HOUR_IN_SECONDS);
			}
		}

		$content  = wp_kses_post($_POST['event_content'] ?? '');
		$status = $whitelist ? 'publish' : 'pending';

		$post_id = wp_insert_post([
			'post_type'   => 'tribe_events',
			'post_status' => $status,
			'post_title'  => $title,
			'post_content'=> $content,
			'post_author' => $user->ID,
		], true );
		if ( is_wp_error($post_id) ) wp_die( $post_id->get_error_message() );

		update_post_meta($post_id,'_EventStartDate',$start_mysql);
		update_post_meta($post_id,'_EventEndDate',$end_mysql);
		update_post_meta($post_id,'_EventAllDay',$all_day ? 'yes' : 'no');

		if (!empty($cats)) wp_set_post_terms($post_id, $cats, 'tribe_events_cat', false);
		if ($source_term_id && taxonomy_exists('event_source')) {
			wp_set_post_terms($post_id, [$source_term_id], 'event_source', false);
		}

		// Venue
		$existing_venue_id = absint($_POST['existing_venue_id'] ?? 0);
		$new_venue_name    = sanitize_text_field($_POST['new_venue_name'] ?? '');
		$new_venue_address = sanitize_text_field($_POST['new_venue_address'] ?? '');
		if ( $new_venue_name ){
			$venue_id = wp_insert_post([
				'post_type'=>'tribe_venue','post_status'=>'publish',
				'post_title'=>$new_venue_name,'post_content'=>''
			]);
			if ( ! is_wp_error($venue_id) ){
				if ($new_venue_address) update_post_meta($venue_id,'_VenueAddress',$new_venue_address);
				update_post_meta($post_id,'_EventVenueID', $venue_id);
			}
		} elseif ( $existing_venue_id ){
			update_post_meta($post_id,'_EventVenueID', $existing_venue_id);
		}

		// Organizer
		$existing_org_id = absint($_POST['existing_org_id'] ?? 0);
		$new_org_name    = sanitize_text_field($_POST['new_org_name'] ?? '');
		if ( $existing_org_id ){
			update_post_meta($post_id,'_EventOrganizerID', $existing_org_id);
		} elseif ( $new_org_name ){
			$org_id = wp_insert_post([
				'post_type'=>'tribe_organizer','post_status'=>'publish',
				'post_title'=>$new_org_name,'post_content'=>''
			]);
			if ( ! is_wp_error($org_id) ){
				update_post_meta($post_id,'_EventOrganizerID', $org_id);
			}
		} else {
			// Fallback Organizer = Default-Quelle (falls vorhanden)
			$def = get_term_by('name', self::DEFAULT_SOURCE_NAME, 'event_source');
			if ( $def ){
				$maybe_org = self::find_organizer_by_name( $def->name );
				if ($maybe_org) update_post_meta($post_id,'_EventOrganizerID', $maybe_org);
			}
		}

		// Bild
		if ( ! empty($_FILES['event_image']['name']) ){
			require_once ABSPATH.'wp-admin/includes/file.php';
			require_once ABSPATH.'wp-admin/includes/image.php';
			$move = wp_handle_upload($_FILES['event_image'], ['test_form'=>false]);
			if ( ! isset($move['error']) ){
				$att_id = wp_insert_attachment([
					'post_mime_type'=>$move['type'],
					'post_title'=>sanitize_file_name( $_FILES['event_image']['name'] ),
					'post_content'=>'',
					'post_status'=>'inherit'
				], $move['file'], $post_id);
				$attach_data = wp_generate_attachment_metadata($att_id, $move['file']);
				wp_update_attachment_metadata($att_id, $attach_data);
				set_post_thumbnail($post_id, $att_id);
			}
		}

		// Token Edit-Link (bis Publish)
		$token = wp_generate_password(20,false,false);
		update_post_meta($post_id, '_tec_comm_token', $token);
		update_post_meta($post_id, '_tec_comm_submitter_email', $user->user_email);

		$dups = self::find_duplicates($title, $start_mysql);

		self::send_submitter_mail($user, $post_id, $token, $start_mysql, $source_term_id);
		self::send_admin_mail($user, $post_id, $start_mysql, $source_term_id, $dups);

		wp_safe_redirect( add_query_arg('tec-comm-ok','1', $redirect) ); exit;
	}

	/* =======================
	 * Login Redirect Helper
	 * ======================= */
	public static function force_login_redirect($redirect_to, $requested, $user){
		if ( ! empty($_REQUEST['redirect_to']) ) {
			return esc_url_raw( $_REQUEST['redirect_to'] );
		}
		return $redirect_to;
	}

	/* =======================
	 * Dupes, Edit, Approve
	 * ======================= */
	protected static function find_duplicates($title,$start_mysql){
		$start = date('Y-m-d H:i:s', strtotime($start_mysql.' -24 hours'));
		$end   = date('Y-m-d H:i:s', strtotime($start_mysql.' +24 hours'));
		$q = new \WP_Query([
			'post_type'=>'tribe_events',
			'post_status'=>['publish','pending'],
			'posts_per_page'=>5,
			's' => $title,
			'meta_query'=>[
				'relation'=>'AND',
				[ 'key'=>'_EventStartDate','value'=>$start,'compare'=>'>=','type'=>'DATETIME' ],
				[ 'key'=>'_EventStartDate','value'=>$end,'compare'=>'<=','type'=>'DATETIME' ],
			]
		]);
		$out = [];
		if($q->have_posts()){
			foreach($q->posts as $p){
				$out[] = sprintf( "%s (%s) – %s",
					get_the_title($p->ID),
					get_post_status($p->ID),
					get_edit_post_link($p->ID,'raw') ?: get_permalink($p->ID)
				);
			}
		}
		return $out;
	}

	public static function maybe_render_edit_form(){
		if ( empty($_GET['tec-edit-event']) || empty($_GET['post']) ) return;
		$token = sanitize_text_field($_GET['tec-edit-event']);
		$post_id = absint($_GET['post']);
		$post = get_post($post_id);
		if ( ! $post || $post->post_type!=='tribe_events' ) return;

		$stored = get_post_meta($post_id,'_tec_comm_token',true);
		if ( ! $stored || ! hash_equals($stored, $token) ){
			wp_die( __('Ungültiger Bearbeitungslink.','tec-add-ons') );
		}
		if ( get_post_status($post_id)==='publish' ){
			wp_die( __('Dieses Ereignis wurde bereits freigegeben und kann nicht mehr im Frontend bearbeitet werden.','tec-add-ons') );
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['_tec_comm_nonce']) && wp_verify_nonce($_POST['_tec_comm_nonce'],'tec_addons_submit_event') ){
			$title = sanitize_text_field($_POST['event_title'] ?? '');
			$sd = sanitize_text_field($_POST['start_date'] ?? '');
			$st = sanitize_text_field($_POST['start_time'] ?? '');
			$ed = sanitize_text_field($_POST['end_date'] ?? '');
			$et = sanitize_text_field($_POST['end_time'] ?? '');
			$start_mysql = date('Y-m-d H:i:00', strtotime($sd.' '.$st));
			$end_mysql = ($ed || $et) ? date('Y-m-d H:i:00', strtotime(($ed?:$sd).' '.($et?:$st))) : date('Y-m-d H:i:00', strtotime($sd.' '.$st.' +1 hour'));

			wp_update_post(['ID'=>$post_id,'post_title'=>$title,'post_content'=>wp_kses_post($_POST['event_content'] ?? '')]);
			update_post_meta($post_id,'_EventStartDate',$start_mysql);
			update_post_meta($post_id,'_EventEndDate',$end_mysql);

			wp_safe_redirect( add_query_arg(['tec-comm-ok'=>'1'], get_permalink()) ); exit;
		}

		$start = get_post_meta($post_id,'_EventStartDate',true);
		$end   = get_post_meta($post_id,'_EventEndDate',true);

		get_header();
		echo '<main class="tec-comm-edit" style="max-width:900px;margin:2rem auto;padding:1rem">';
		echo '<h1>'.esc_html__('Veranstaltung bearbeiten','tec-add-ons').'</h1>';
		echo '<form method="post">';
		wp_nonce_field('tec_addons_submit_event','_tec_comm_nonce');
		echo '<p><label>'.esc_html__('Titel','tec-add-ons').' *<br><input name="event_title" type="text" value="'.esc_attr(get_the_title($post_id)).'" style="width:100%"></label></p>';
		echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
		echo '<p><label>'.esc_html__('Start (Datum)','tec-add-ons').' *<br><input type="date" name="start_date" value="'.esc_attr( date('Y-m-d', strtotime($start)) ).'"></label></p>';
		echo '<p><label>'.esc_html__('Start (Uhrzeit)','tec-add-ons').' *<br><input type="time" name="start_time" value="'.esc_attr( date('H:i', strtotime($start)) ).'"></label></p>';
		echo '<p><label>'.esc_html__('Ende (Datum)','tec-add-ons').'<br><input type="date" name="end_date" value="'.esc_attr( date('Y-m-d', strtotime($end)) ).'"></label></p>';
		echo '<p><label>'.esc_html__('Ende (Uhrzeit)','tec-add-ons').'<br><input type="time" name="end_time" value="'.esc_attr( date('H:i', strtotime($end)) ).'"></label></p>';
		echo '</div>';
		echo '<p><label>'.esc_html__('Beschreibung','tec-add-ons').' *<br><textarea name="event_content" rows="8" style="width:100%">'.esc_textarea(get_post_field('post_content',$post_id)).'</textarea></label></p>';
		echo '<p><button class="button button-primary" type="submit">'.esc_html__('Speichern','tec-add-ons').'</button></p>';
		echo '</form>';
		echo '</main>';
		get_footer();
		exit;
	}

	public static function quick_publish(){
		if ( ! current_user_can('edit_posts') ) wp_die('no');
		$post_id = absint($_GET['post'] ?? 0);
		check_admin_referer('tec_comm_quick_publish_'.$post_id);
		if ( ! $post_id || get_post_type($post_id)!=='tribe_events' ) wp_die('no');
		wp_update_post(['ID'=>$post_id,'post_status'=>'publish']);
		delete_post_meta($post_id,'_tec_comm_token');
		wp_safe_redirect( get_edit_post_link($post_id,'raw') ?: admin_url('edit.php?post_type=tribe_events') );
		exit;
	}

	/* =======================
	 * Mail
	 * ======================= */
	protected static function send_submitter_mail($user,$post_id,$token,$start_mysql,$source_term_id){
		$tpl = get_option('tec_addons_comm_tpl_submitter');
		$repl = [
			'{name}'         => $user->display_name ?: $user->user_email,
			'{email}'        => $user->user_email,
			'{event_title}'  => get_the_title($post_id),
			'{event_start}'  => mysql2date('d.m.Y H:i',$start_mysql),
			'{edit_link}'    => add_query_arg(['tec-edit-event'=>$token,'post'=>$post_id], home_url('/')),
			'{site_name}'    => get_bloginfo('name'),
		];
		$body = strtr($tpl, $repl);
		wp_mail( $user->user_email, sprintf('[%s] %s', get_bloginfo('name'), __('Einreichung erhalten','tec-add-ons')), $body );
	}

	protected static function send_admin_mail($user,$post_id,$start_mysql,$source_term_id,$duplicates){
		$tpl = get_option('tec_addons_comm_tpl_admin');
		$term = ($source_term_id && taxonomy_exists('event_source')) ? get_term($source_term_id,'event_source') : get_term_by('name', self::DEFAULT_SOURCE_NAME, 'event_source');
		$dups = $duplicates ? "- ".implode("\n- ", $duplicates) : __('keine erkannt','tec-add-ons');

		$admin_edit = get_edit_post_link($post_id,'raw');
		$approve = wp_nonce_url( admin_url('admin-post.php?action=tec_comm_quick_publish&post='.$post_id), 'tec_comm_quick_publish_'.$post_id );

		$repl = [
			'{event_title}'     => get_the_title($post_id),
			'{event_start}'     => mysql2date('d.m.Y H:i',$start_mysql),
			'{event_source}'    => ($term && !is_wp_error($term)) ? $term->name : '—',
			'{submitter_name}'  => $user->display_name ?: $user->user_email,
			'{submitter_email}' => $user->user_email,
			'{admin_edit_link}' => $admin_edit,
			'{approve_link}'    => $approve,
			'{duplicates}'      => $dups,
		];

		$body = strtr($tpl, $repl);
		if ( strpos($body, $approve) === false ){
			$body .= "\n\n".__('Direkt freigeben: ','tec-add-ons').$approve;
		}
		wp_mail( get_option('admin_email'), sprintf('[%s] %s', get_bloginfo('name'), __('Neue Community-Einreichung','tec-add-ons')), $body );
	}
}
