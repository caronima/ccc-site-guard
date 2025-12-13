<?php

/**
 * Plugin Name: CCC Site Guard
 * Description: Consolidated plugin for daily update report emails and security hardening (author redirect / REST user lock / version hiding / Basic Auth).
 * Version: 0.3.2
 * Author: Caronima Inc.
 * Text Domain: ccc-site-guard
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CCC_SG_OPT_KEY',  'ccc_sg_settings');
define('CCC_SG_CRON_HOOK', 'ccc_sg_daily_event');

define('CCC_SG_MENU_SLUG', 'ccc-site-guard');
define('CCC_SG_CAP', 'manage_options');
define('CCC_SG_TEXTDOMAIN', 'ccc-site-guard');

/**
 * Plugin header values (Plugin Name / Version / etc.) are readable at runtime.
 * If you rename the plugin in the header comment, UI labels and Realm will follow.
 */
function ccc_sg_plugin_header()
{
	static $data = null;
	if ($data !== null) return $data;

	$fields = array(
		'name'        => 'Plugin Name',
		'plugin_name' => 'Plugin Name',
		'version'     => 'Version',
		'description' => 'Description',
		'author'      => 'Author',
	);
	$data = get_file_data(__FILE__, $fields, 'plugin');
	return $data;
}

function ccc_sg_plugin_name()
{
	$h = ccc_sg_plugin_header();
	$nm = isset($h['name']) && $h['name'] !== '' ? $h['name'] : (isset($h['plugin_name']) ? $h['plugin_name'] : 'CCC Site Guard');
	return $nm !== '' ? $nm : 'CCC Site Guard';
}

function ccc_sg_header_safe($value)
{
	$value = (string)$value;
	// Prevent header injection
	return str_replace(array("\r", "\n"), '', $value);
}

/**
 * Load translations.
 * - Default strings in code are English.
 * - Japanese (and other) translations can be provided via /languages or translate.wordpress.org.
 */
add_action('plugins_loaded', 'ccc_sg_load_textdomain');
function ccc_sg_load_textdomain()
{
	load_plugin_textdomain(
		CCC_SG_TEXTDOMAIN,
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
}

/*--------------------------------------------------------------
 * 設定値取得
 *--------------------------------------------------------------*/
function ccc_sg_get_settings()
{
	$defaults = array(
		// 日次レポート
		'enable_daily_report' => 1,
		'daily_report_time'   => '09:00', // site timezone
		'notify_emails'       => '',
		'send_even_if_empty'  => 0,

		// セキュリティ
		'enable_author_redirect' => 0,
		'author_slug'            => 'my-posts',
		'author_query_key'       => 'member',
		'enable_rest_user_lock'  => 1,
		'enable_version_hiding'  => 1,

		// Basic Auth
		'enable_basic_auth'   => 0,
		'basic_auth_scope'    => 'admin', // admin | site
		'basic_auth_user'     => '',
		'basic_auth_passhash' => '',

		// クリーンアップ
		'cleanup_on_deactivate' => 0,
		'cleanup_on_uninstall'  => 0,
	);

	$opt = get_option(CCC_SG_OPT_KEY, array());
	return array_merge($defaults, is_array($opt) ? $opt : array());
}

/*--------------------------------------------------------------
 * 管理画面メニュー
 *--------------------------------------------------------------*/
add_action('admin_menu', 'ccc_sg_add_settings_page');
function ccc_sg_add_settings_page()
{
	add_menu_page(
		ccc_sg_plugin_name(),
		ccc_sg_plugin_name(),
		CCC_SG_CAP,
		CCC_SG_MENU_SLUG,
		'ccc_sg_render_settings_page',
		'dashicons-shield',
		81
	);

	// Visible submenu pointing to the same page
	add_submenu_page(
		CCC_SG_MENU_SLUG,
		ccc_sg_plugin_name(),
		__('Settings', CCC_SG_TEXTDOMAIN),
		CCC_SG_CAP,
		CCC_SG_MENU_SLUG,
		'ccc_sg_render_settings_page'
	);
}

/*--------------------------------------------------------------
 * 設定ページ用の軽いJS/CSS（A案：変更時だけパス入力を展開）
 *--------------------------------------------------------------*/
add_action('admin_enqueue_scripts', 'ccc_sg_admin_assets');
function ccc_sg_admin_assets($hook)
{
	if ($hook !== 'toplevel_page_' . CCC_SG_MENU_SLUG) return;

	$js = <<<'JS'
document.addEventListener('DOMContentLoaded', () => {
  // Password UI
  const btn = document.getElementById('ccc-sg-pass-change-btn');
  const box = document.getElementById('ccc-sg-pass-change-box');
  const cancel = document.getElementById('ccc-sg-pass-change-cancel');
  const clear = document.getElementById('ccc-sg-pass-clear');

  function setPassBoxVisible(v){
    if(!box) return;
    box.style.display = v ? 'block' : 'none';
    if(v){
      const p1 = document.getElementById('ccc-sg-pass1');
      if(p1) p1.focus();
    } else {
      const p1 = document.getElementById('ccc-sg-pass1');
      const p2 = document.getElementById('ccc-sg-pass2');
      if(p1) p1.value = '';
      if(p2) p2.value = '';
    }
  }

  if(btn){
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      setPassBoxVisible(true);
      if(clear) clear.checked = false;
    });
  }
  if(cancel){
    cancel.addEventListener('click', (e) => {
      e.preventDefault();
      setPassBoxVisible(false);
    });
  }
  if(clear){
    clear.addEventListener('change', () => {
      if(clear.checked) setPassBoxVisible(false);
    });
  }

  // Dependency UI (hide dependent settings until Enable is ON)
  const dailyEnable = document.getElementById('ccc-sg-enable-daily-report');
  const dailyDeps = document.querySelectorAll('.ccc-sg-dep-daily');
  const dailyNote = document.getElementById('ccc-sg-daily-disabled-note');

  const basicEnable = document.getElementById('ccc-sg-enable-basic-auth');
  const basicDeps = document.querySelectorAll('.ccc-sg-dep-basic');
  const basicNote = document.getElementById('ccc-sg-basic-disabled-note');

  function setRowsVisible(nodes, visible){
    nodes.forEach((el) => {
      el.style.display = visible ? '' : 'none';
    });
  }

  function syncDaily(){
    const on = !!(dailyEnable && dailyEnable.checked);
    setRowsVisible(dailyDeps, on);
    if(dailyNote) dailyNote.style.display = on ? 'none' : 'block';
  }

  function syncBasic(){
    const on = !!(basicEnable && basicEnable.checked);
    setRowsVisible(basicDeps, on);
    if(basicNote) basicNote.style.display = on ? 'none' : 'block';

    // If Basic Auth is disabled, also close the password box UI to avoid confusion.
    if(!on){
      setPassBoxVisible(false);
      if(clear) clear.checked = false;
    }
  }

  if(dailyEnable){
    dailyEnable.addEventListener('change', syncDaily);
    syncDaily();
  }

  if(basicEnable){
    basicEnable.addEventListener('change', syncBasic);
    syncBasic();
  }
});
JS;

	// Ensure jQuery is available on this admin page
	wp_enqueue_script('jquery-core');
	wp_add_inline_script('jquery-core', $js, 'after');

	$css = <<<'CSS'
.ccc-sg-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;line-height:1.8}
.ccc-sg-badge.ok{background:#e7f7ee;color:#0b6b2e;border:1px solid #bfe8cf}
.ccc-sg-badge.ng{background:#fff1f0;color:#a4000f;border:1px solid #ffd0cc}
.ccc-sg-subnote{color:#646970;font-size:12px;margin-top:6px}
.ccc-sg-warning{color:#b32d2e;font-weight:600}
#ccc-sg-pass-change-box{margin-top:10px;padding:12px;border:1px solid #dcdcde;background:#fff;border-radius:8px;max-width:520px}
CSS;

	// Use a dedicated handle so inline CSS is reliably printed
	$h = ccc_sg_plugin_header();
	$ver = isset($h['version']) && $h['version'] !== '' ? $h['version'] : '0.0.0';
	wp_register_style('ccc-sg-admin', false, array(), $ver);
	wp_enqueue_style('ccc-sg-admin');
	wp_add_inline_style('ccc-sg-admin', $css);
}

/*--------------------------------------------------------------
 * 設定画面
 *--------------------------------------------------------------*/
function ccc_sg_render_settings_page()
{
	if (!current_user_can(CCC_SG_CAP)) return;

	$notice = array('type' => '', 'msg' => '');

	if (isset($_POST['ccc_sg_save'])) {
		check_admin_referer('ccc_sg_save_action');

		$cur = ccc_sg_get_settings();

		$save = array(
			// 日次レポート
			'enable_daily_report' => isset($_POST['enable_daily_report']) ? 1 : 0,
			'daily_report_time'   => isset($_POST['daily_report_time']) ? ccc_sg_sanitize_hhmm($_POST['daily_report_time']) : $cur['daily_report_time'],
			'notify_emails'       => isset($_POST['notify_emails']) ? sanitize_text_field($_POST['notify_emails']) : '',
			'send_even_if_empty'  => isset($_POST['send_even_if_empty']) ? 1 : 0,

			// セキュリティ
			'enable_author_redirect' => isset($_POST['enable_author_redirect']) ? 1 : 0,
			'author_slug'            => isset($_POST['author_slug']) ? sanitize_title($_POST['author_slug']) : 'my-posts',
			'author_query_key'       => isset($_POST['author_query_key']) ? sanitize_key($_POST['author_query_key']) : 'member',
			'enable_rest_user_lock'  => isset($_POST['enable_rest_user_lock']) ? 1 : 0,
			'enable_version_hiding'  => isset($_POST['enable_version_hiding']) ? 1 : 0,

			// Basic Auth
			'enable_basic_auth'   => isset($_POST['enable_basic_auth']) ? 1 : 0,
			'basic_auth_scope'    => (isset($_POST['basic_auth_scope']) && $_POST['basic_auth_scope'] === 'site') ? 'site' : 'admin',
			'basic_auth_user'     => isset($_POST['basic_auth_user']) ? sanitize_user($_POST['basic_auth_user'], true) : '',
			'basic_auth_passhash' => $cur['basic_auth_passhash'], // keep by default

			// クリーンアップ
			'cleanup_on_deactivate' => isset($_POST['cleanup_on_deactivate']) ? 1 : 0,
			'cleanup_on_uninstall'  => isset($_POST['cleanup_on_uninstall']) ? 1 : 0,
		);

		// パスワードクリア
		$do_clear = isset($_POST['basic_auth_clear']) ? 1 : 0;
		if ($do_clear) {
			$save['basic_auth_passhash'] = '';
		}

		// パスワード変更（A案：展開時だけ入力される）
		$p1 = isset($_POST['basic_auth_password1']) ? (string)$_POST['basic_auth_password1'] : '';
		$p2 = isset($_POST['basic_auth_password2']) ? (string)$_POST['basic_auth_password2'] : '';

		if (!$do_clear && ($p1 !== '' || $p2 !== '')) {
			if ($p1 === '' || $p2 === '') {
				$notice = array('type' => 'error', 'msg' => __('Please fill in both Basic Auth password fields.', CCC_SG_TEXTDOMAIN));
			} elseif (!hash_equals($p1, $p2)) {
				$notice = array('type' => 'error', 'msg' => __('Basic Auth passwords do not match.', CCC_SG_TEXTDOMAIN));
			} else {
				$save['basic_auth_passhash'] = wp_hash_password($p1);
				$notice = array('type' => 'updated', 'msg' => __('Settings saved (Basic Auth password updated).', CCC_SG_TEXTDOMAIN));
			}
		}

		update_option(CCC_SG_OPT_KEY, $save);

		// 旧オプション互換（必要なら）
		update_option('ccc_authors', array(
			'slug'  => $save['author_slug'],
			'query' => $save['author_query_key'],
		));

		// Cron 再設定
		ccc_sg_reschedule_daily_event();

		if (empty($notice['msg'])) {
			$notice = array('type' => 'updated', 'msg' => __('Settings saved.', CCC_SG_TEXTDOMAIN));
		}
	}

	$s = ccc_sg_get_settings();

	if (!empty($notice['msg'])) {
		$cls = ($notice['type'] === 'error') ? 'notice notice-error' : 'notice notice-success';
		echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html($notice['msg']) . '</p></div>';
	}

	$has_pass = !empty($s['basic_auth_passhash']);
	$has_user = !empty($s['basic_auth_user']);
	$basic_ready = ($has_pass && $has_user);
	$daily_enabled = !empty($s['enable_daily_report']);
	$basic_enabled = !empty($s['enable_basic_auth']);
	$daily_row_style = $daily_enabled ? '' : ' style="display:none;"';
	$basic_row_style = $basic_enabled ? '' : ' style="display:none;"';

	// Summaries shown when a section is disabled (avoid leaking sensitive values).
	$daily_recipients = ccc_sg_parse_emails($s['notify_emails']);
	$daily_recipient_count = is_array($daily_recipients) ? count($daily_recipients) : 0;
	$daily_recipients_label = ($daily_recipient_count > 0)
		? sprintf(
			_n('%d recipient configured', '%d recipients configured', $daily_recipient_count, CCC_SG_TEXTDOMAIN),
			$daily_recipient_count
		)
		: __('Not set', CCC_SG_TEXTDOMAIN);
	$daily_even_label = !empty($s['send_even_if_empty']) ? __('On', CCC_SG_TEXTDOMAIN) : __('Off', CCC_SG_TEXTDOMAIN);
	$daily_disabled_summary = sprintf(
		__('Current saved settings: time %1$s, recipients %2$s, send even if empty %3$s.', CCC_SG_TEXTDOMAIN),
		esc_html($s['daily_report_time']),
		esc_html($daily_recipients_label),
		esc_html($daily_even_label)
	);

	$basic_scope_label = (($s['basic_auth_scope'] ?? 'admin') === 'site')
		? __('Entire site', CCC_SG_TEXTDOMAIN)
		: __('Admin area', CCC_SG_TEXTDOMAIN);
	$basic_user_label = $has_user ? __('Configured', CCC_SG_TEXTDOMAIN) : __('Not set', CCC_SG_TEXTDOMAIN);
	$basic_pass_label = $has_pass ? __('Configured', CCC_SG_TEXTDOMAIN) : __('Not set', CCC_SG_TEXTDOMAIN);
	$basic_disabled_summary = sprintf(
		__('Current saved settings: scope %1$s, username %2$s, password %3$s.', CCC_SG_TEXTDOMAIN),
		esc_html($basic_scope_label),
		esc_html($basic_user_label),
		esc_html($basic_pass_label)
	);
	$basic_disabled_summary_note = __('(Passwords are never displayed.)', CCC_SG_TEXTDOMAIN);
?>
	<div class="wrap">
		<h1><?php echo esc_html(ccc_sg_plugin_name()); ?></h1>

		<form method="post">
			<?php wp_nonce_field('ccc_sg_save_action'); ?>

			<h2><?php echo esc_html__('1. Daily update report', CCC_SG_TEXTDOMAIN); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('Enable', CCC_SG_TEXTDOMAIN); ?></th>
					<td><label>
							<input id="ccc-sg-enable-daily-report" type="checkbox" name="enable_daily_report" value="1" <?php checked($s['enable_daily_report'], 1); ?>>
							<?php echo esc_html__('Send a daily summary of available updates.', CCC_SG_TEXTDOMAIN); ?>
						</label>
						<div id="ccc-sg-daily-disabled-note" class="ccc-sg-subnote" style="display:<?php echo $daily_enabled ? 'none' : 'block'; ?>;">
							<?php echo esc_html__('Turn on Enable to configure the schedule and notification settings below.', CCC_SG_TEXTDOMAIN); ?>
							<br>
							<?php echo esc_html($daily_disabled_summary); ?>
						</div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-daily" <?php echo $daily_row_style; ?>>
					<th scope="row"><?php echo esc_html__('Send time (site timezone)', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<input type="time" name="daily_report_time" value="<?php echo esc_attr($s['daily_report_time']); ?>">
						<div class="ccc-sg-subnote"><?php echo esc_html__('Due to WP-Cron, the delivery time may be delayed by a few minutes depending on site traffic.', CCC_SG_TEXTDOMAIN); ?></div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-daily" <?php echo $daily_row_style; ?>>
					<th scope="row"><?php echo esc_html__('Notification email(s)', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<input type="text" class="regular-text" name="notify_emails"
							value="<?php echo esc_attr($s['notify_emails']); ?>"
							placeholder="wp-report@your-company.jp, second@mail.com">
						<p class="description"><?php echo esc_html__('You can specify multiple addresses separated by commas.', CCC_SG_TEXTDOMAIN); ?></p>
					</td>
				</tr>

				<tr class="ccc-sg-dep-daily" <?php echo $daily_row_style; ?>>
					<th scope="row"><?php echo esc_html__('Send even when there are no updates', CCC_SG_TEXTDOMAIN); ?></th>
					<td><label>
							<input type="checkbox" name="send_even_if_empty" value="1" <?php checked($s['send_even_if_empty'], 1); ?>>
							<?php echo esc_html__('Send a report even when there are no updates.', CCC_SG_TEXTDOMAIN); ?>
						</label></td>
				</tr>
			</table>

			<hr>

			<h2><?php echo esc_html__('2. Security', CCC_SG_TEXTDOMAIN); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('REST API user endpoint lock', CCC_SG_TEXTDOMAIN); ?></th>
					<td><label>
							<input type="checkbox" name="enable_rest_user_lock" value="1" <?php checked($s['enable_rest_user_lock'], 1); ?>>
							<?php echo esc_html__('Disable user-related endpoints such as', CCC_SG_TEXTDOMAIN); ?> <code>/wp/v2/users</code>
						</label></td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__('Hide version information', CCC_SG_TEXTDOMAIN); ?></th>
					<td><label>
							<input type="checkbox" name="enable_version_hiding" value="1" <?php checked($s['enable_version_hiding'], 1); ?>>
							<code>the_generator</code> <?php echo esc_html__('Reduce information exposure (e.g., suppress the_generator).', CCC_SG_TEXTDOMAIN); ?>
						</label></td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__('Author archive redirect', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_author_redirect" value="1" <?php checked($s['enable_author_redirect'], 1); ?>>
							<?php echo esc_html__('(Optional / advanced) Redirect author pages to avoid user_login enumeration.', CCC_SG_TEXTDOMAIN); ?>
						</label>
						<p>
							<label><?php echo esc_html__('Slug:', CCC_SG_TEXTDOMAIN); ?>
								<input type="text" name="author_slug" value="<?php echo esc_attr($s['author_slug']); ?>" class="regular-text">
							</label><br>
							<label><?php echo esc_html__('Query key:', CCC_SG_TEXTDOMAIN); ?>
								<input type="text" name="author_query_key" value="<?php echo esc_attr($s['author_query_key']); ?>" class="regular-text">
							</label>
						</p>
					</td>
				</tr>
			</table>

			<hr>

			<h2><?php echo esc_html__('3. Basic Auth', CCC_SG_TEXTDOMAIN); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('Enable', CCC_SG_TEXTDOMAIN); ?></th>
					<td><label>
							<input id="ccc-sg-enable-basic-auth" type="checkbox" name="enable_basic_auth" value="1" <?php checked($s['enable_basic_auth'], 1); ?>>
							<?php echo esc_html__('Enable Basic Auth', CCC_SG_TEXTDOMAIN); ?>
						</label>
						<div class="ccc-sg-subnote">
							<?php echo esc_html__('Status:', CCC_SG_TEXTDOMAIN); ?>
							<?php if ($basic_ready): ?>
								<span class="ccc-sg-badge ok"><?php echo esc_html__('Configured', CCC_SG_TEXTDOMAIN); ?></span>
							<?php else: ?>
								<span class="ccc-sg-badge ng"><?php echo esc_html__('Not configured', CCC_SG_TEXTDOMAIN); ?></span>
							<?php endif; ?>
							<?php if (!empty($s['enable_basic_auth']) && !$basic_ready): ?>
								<span class="ccc-sg-subnote"><?php echo esc_html__('Even if enabled, it will not work until both username and password are set.', CCC_SG_TEXTDOMAIN); ?></span>
							<?php endif; ?>
						</div>
						<div id="ccc-sg-basic-disabled-note" class="ccc-sg-subnote" style="display:<?php echo $basic_enabled ? 'none' : 'block'; ?>;">
							<?php echo esc_html__('Turn on Enable to configure scope, username and password.', CCC_SG_TEXTDOMAIN); ?>
							<br>
							<?php echo esc_html($basic_disabled_summary); ?>
							<span style="margin-left:6px;"><?php echo esc_html($basic_disabled_summary_note); ?></span>
						</div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-basic" <?php echo $basic_row_style; ?>>
					<th scope="row"><?php echo esc_html__('Scope', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<label><input type="radio" name="basic_auth_scope" value="admin" <?php checked($s['basic_auth_scope'], 'admin'); ?>> <?php echo esc_html__('Admin area', CCC_SG_TEXTDOMAIN); ?></label><br>
						<label><input type="radio" name="basic_auth_scope" value="site" <?php checked($s['basic_auth_scope'], 'site');  ?>> <?php echo esc_html__('Entire site', CCC_SG_TEXTDOMAIN); ?></label>
						<div class="ccc-sg-subnote"><?php echo esc_html__('Protecting the entire site may affect integrations (APIs, images, forms, etc.).', CCC_SG_TEXTDOMAIN); ?></div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-basic" <?php echo $basic_row_style; ?>>
					<th scope="row"><?php echo esc_html__('Username', CCC_SG_TEXTDOMAIN); ?></th>
					<td><input type="text" class="regular-text" name="basic_auth_user" value="<?php echo esc_attr($s['basic_auth_user']); ?>"></td>
				</tr>

				<tr class="ccc-sg-dep-basic" <?php echo $basic_row_style; ?>>
					<th scope="row"><?php echo esc_html__('Password', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<?php if ($has_pass): ?>
							<span class="ccc-sg-badge ok"><?php echo esc_html__('Configured', CCC_SG_TEXTDOMAIN); ?></span>
						<?php else: ?>
							<span class="ccc-sg-badge ng"><?php echo esc_html__('Not configured', CCC_SG_TEXTDOMAIN); ?></span>
						<?php endif; ?>

						<?php
						// Button/intro text should differ between first-time setup and already-configured state.
						$pass_btn_label = $has_pass
							? __('Change password…', CCC_SG_TEXTDOMAIN)
							: __('Set password…', CCC_SG_TEXTDOMAIN);

						$pass_box_intro = $has_pass
							? __('Enter a new password.', CCC_SG_TEXTDOMAIN)
							: __('Set a password.', CCC_SG_TEXTDOMAIN);
						?>

						<p style="margin-top:10px;">
							<button class="button" id="ccc-sg-pass-change-btn"><?php echo esc_html($pass_btn_label); ?></button>

							<?php if ($has_pass): ?>
								<label style="margin-left:10px;">
									<input type="checkbox" id="ccc-sg-pass-clear" name="basic_auth_clear" value="1">
									<?php echo esc_html__('Clear password', CCC_SG_TEXTDOMAIN); ?>
								</label>
							<?php endif; ?>
						</p>

						<div id="ccc-sg-pass-change-box" style="display:none;">
							<p style="margin-top:0;"><?php echo esc_html($pass_box_intro); ?></p>
							<p>
								<label><?php echo esc_html__('New password', CCC_SG_TEXTDOMAIN); ?><br>
									<input id="ccc-sg-pass1" type="password" class="regular-text" name="basic_auth_password1" autocomplete="new-password">
								</label>
							</p>
							<p>
								<label><?php echo esc_html__('Confirm (again)', CCC_SG_TEXTDOMAIN); ?><br>
									<input id="ccc-sg-pass2" type="password" class="regular-text" name="basic_auth_password2" autocomplete="new-password">
								</label>
							</p>
							<p style="margin-bottom:0;">
								<button class="button" id="ccc-sg-pass-change-cancel"><?php echo esc_html__('Cancel', CCC_SG_TEXTDOMAIN); ?></button>
							</p>
						</div>

						<div class="ccc-sg-subnote"><?php echo esc_html__('Passwords are stored as a hash (never as plain text).', CCC_SG_TEXTDOMAIN); ?></div>
					</td>
				</tr>


			</table>
			<hr>

			<h2><?php echo esc_html__('4. Cleanup', CCC_SG_TEXTDOMAIN); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('On deactivation', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<label>
							<input type="checkbox" name="cleanup_on_deactivate" value="1" <?php checked($s['cleanup_on_deactivate'], 1); ?>>
							<?php echo esc_html__('Delete settings and related data when the plugin is deactivated.', CCC_SG_TEXTDOMAIN); ?>
						</label>
						<div class="ccc-sg-warning"><?php echo esc_html__('WARNING: This cannot be undone. Keep this OFF for normal use.', CCC_SG_TEXTDOMAIN); ?></div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('On deletion', CCC_SG_TEXTDOMAIN); ?></th>
					<td>
						<label>
							<input type="checkbox" name="cleanup_on_uninstall" value="1" <?php checked($s['cleanup_on_uninstall'], 1); ?>>
							<?php echo esc_html__('Delete settings and related data when the plugin is deleted.', CCC_SG_TEXTDOMAIN); ?>
						</label>
						<div class="ccc-sg-subnote"><?php echo esc_html__('Recommended: Turn ON only if you want a complete wipe on deletion. If enabled, deleting the plugin will permanently remove all settings and related data with no leftovers.', CCC_SG_TEXTDOMAIN); ?></div>
						<div class="ccc-sg-warning"><?php echo esc_html__('WARNING: If this is ON when you delete the plugin, it cannot be restored (unless you have a backup).', CCC_SG_TEXTDOMAIN); ?></div>
					</td>
				</tr>
			</table>

			<?php submit_button(__('Save settings', CCC_SG_TEXTDOMAIN), 'primary', 'ccc_sg_save'); ?>
		</form>
	</div>
<?php
}

function ccc_sg_sanitize_hhmm($v)
{
	$v = trim((string)$v);
	return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '09:00';
}

/*--------------------------------------------------------------
 * Cron
 *--------------------------------------------------------------*/
register_activation_hook(__FILE__, 'ccc_sg_activate');
function ccc_sg_activate()
{
	ccc_sg_reschedule_daily_event();
}

register_deactivation_hook(__FILE__, 'ccc_sg_deactivate');
function ccc_sg_deactivate()
{
	// Always remove scheduled cron on deactivation
	wp_clear_scheduled_hook(CCC_SG_CRON_HOOK);

	// Optional cleanup on deactivation
	$s = get_option(CCC_SG_OPT_KEY, array());
	if (is_array($s) && !empty($s['cleanup_on_deactivate'])) {
		ccc_sg_cleanup_all();
	}
}

register_uninstall_hook(__FILE__, 'ccc_sg_uninstall');
function ccc_sg_uninstall()
{
	// Only clean up if the user opted in
	$s = get_option(CCC_SG_OPT_KEY, array());
	if (is_array($s) && !empty($s['cleanup_on_uninstall'])) {
		ccc_sg_cleanup_all();
	}
}

/**
 * クリーンアップ（設定/互換オプション/スケジュール/生成物）
 */
function ccc_sg_cleanup_all()
{
	// 予定されたCronを除去
	wp_clear_scheduled_hook(CCC_SG_CRON_HOOK);

	// 設定オプション
	delete_option(CCC_SG_OPT_KEY);

	// 旧互換オプション（このプラグインが管理している範囲）
	delete_option('ccc_authors');

	// 生成ファイルがある場合（将来拡張用）: uploads/ccc-site-guard を削除
	ccc_sg_delete_uploads_dir('ccc-site-guard');
}

function ccc_sg_delete_uploads_dir($subdir)
{
	$uploads = wp_upload_dir();
	$base = isset($uploads['basedir']) ? $uploads['basedir'] : '';
	if ($base === '') return;

	$base = rtrim($base, '/');
	$target = $base . '/' . ltrim((string)$subdir, '/');

	// Safety: only allow deletion inside uploads basedir
	$real_base = realpath($base);
	$real_target = realpath($target);
	if (!$real_base || !$real_target) return;
	if (strpos($real_target, $real_base) !== 0) return;
	if (!is_dir($real_target)) return;

	ccc_sg_rrmdir($real_target);
}

function ccc_sg_rrmdir($dir)
{
	$items = @scandir($dir);
	if (!is_array($items)) return;
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') continue;
		$path = $dir . '/' . $item;
		if (is_dir($path)) {
			ccc_sg_rrmdir($path);
		} else {
			@unlink($path);
		}
	}
	@rmdir($dir);
}

add_action('plugins_loaded', 'ccc_sg_ensure_daily_event_scheduled');
function ccc_sg_ensure_daily_event_scheduled()
{
	$s = ccc_sg_get_settings();
	if (empty($s['enable_daily_report'])) return;

	// If the schedule is missing (e.g. after migration/cache flush), recreate it.
	if (wp_next_scheduled(CCC_SG_CRON_HOOK)) return;

	$next = ccc_sg_calc_next_run_timestamp($s['daily_report_time']);
	wp_schedule_event($next, 'daily', CCC_SG_CRON_HOOK);
}

function ccc_sg_reschedule_daily_event()
{
	$s = ccc_sg_get_settings();
	// This is called on activation / settings-save to apply a new schedule.
	wp_clear_scheduled_hook(CCC_SG_CRON_HOOK);

	if (empty($s['enable_daily_report'])) return;

	$next = ccc_sg_calc_next_run_timestamp($s['daily_report_time']);
	if (!wp_next_scheduled(CCC_SG_CRON_HOOK)) {
		wp_schedule_event($next, 'daily', CCC_SG_CRON_HOOK);
	}
}

function ccc_sg_calc_next_run_timestamp($hhmm)
{
	$tz  = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(get_option('timezone_string') ?: 'UTC');
	$now = new DateTime('now', $tz);

	list($h, $m) = array_map('intval', explode(':', $hhmm));
	$run = new DateTime('now', $tz);
	$run->setTime($h, $m, 0);
	if ($run <= $now) $run->modify('+1 day');

	return $run->getTimestamp();
}

add_action(CCC_SG_CRON_HOOK, 'ccc_sg_run_daily_report');

/*--------------------------------------------------------------
 * 日次レポート（available updatesベース）
 *--------------------------------------------------------------*/
function ccc_sg_run_daily_report()
{
	$s = ccc_sg_get_settings();
	if (empty($s['enable_daily_report'])) return;

	$emails = ccc_sg_parse_emails($s['notify_emails']);
	if (empty($emails)) return;

	if (function_exists('wp_update_plugins')) wp_update_plugins();
	if (function_exists('wp_update_themes'))  wp_update_themes();
	if (function_exists('wp_version_check'))  wp_version_check();

	$plugins = get_site_transient('update_plugins');
	$themes  = get_site_transient('update_themes');
	$core    = get_site_transient('update_core');

	$report = "";

	if (!empty($plugins->response)) {
		if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all = function_exists('get_plugins') ? get_plugins() : array();

		$report .= __("Plugins needing update:\n", CCC_SG_TEXTDOMAIN);
		foreach ($plugins->response as $plugin_file => $info) {
			$name = isset($all[$plugin_file]['Name']) ? $all[$plugin_file]['Name'] : ($info->slug ?? $plugin_file);
			$cur  = $info->old_version ?? '';
			$new  = $info->new_version ?? '';
			$report .= "- {$name}: {$cur} → {$new}\n";
		}
		$report .= "\n";
	}

	if (!empty($themes->response)) {
		$report .= __("Themes needing update:\n", CCC_SG_TEXTDOMAIN);
		foreach ($themes->response as $slug => $info) {
			$theme = wp_get_theme($slug);
			$name  = ($theme && $theme->exists()) ? $theme->get('Name') : $slug;
			$cur   = $info['old_version'] ?? '';
			$new   = $info['new_version'] ?? '';
			$report .= "- {$name}: {$cur} → {$new}\n";
		}
		$report .= "\n";
	}

	if (!empty($core->updates) && is_array($core->updates)) {
		foreach ($core->updates as $u) {
			if (($u->response ?? '') === 'upgrade') {
				$cur = get_bloginfo('version');
				$new = $u->version ?? '';
				$report .= sprintf(__("WordPress core update available: %1\$s → %2\$s\n\n", CCC_SG_TEXTDOMAIN), $cur, $new);
				break;
			}
		}
	}

	if (trim($report) === '') {
		if (empty($s['send_even_if_empty'])) return;
		$report = __("No updates are available today.\n", CCC_SG_TEXTDOMAIN);
	}

	$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$subject = sprintf(__("[%1\$s] %2\$s - Daily Update Report", CCC_SG_TEXTDOMAIN), ccc_sg_plugin_name(), $site_name);
	$body    = __("Daily WordPress Update Report (Available Updates)\n", CCC_SG_TEXTDOMAIN) . home_url() . "\n\n" . $report;

	wp_mail($emails, $subject, $body);
}

function ccc_sg_parse_emails($raw)
{
	$list = array();
	foreach (explode(',', (string)$raw) as $email) {
		$email = trim($email);
		if (is_email($email)) $list[] = $email;
	}
	return array_values(array_unique($list));
}

/*--------------------------------------------------------------
 * セキュリティ（Basic Auth優先）
 *--------------------------------------------------------------*/
add_action('init', 'ccc_sg_boot_security', 0);
function ccc_sg_boot_security()
{
	$s = ccc_sg_get_settings();

	if (!empty($s['enable_basic_auth'])) {
		$scope = $s['basic_auth_scope'] ?? 'admin';
		if ($scope === 'admin') {
			// 1) Login screen: always protected (works even when login URL is changed by another plugin).
			add_action('login_init', 'ccc_sg_basic_auth_on_login', 0);
			// 2) Admin area: protected only AFTER the user is logged in.
			add_action('admin_init', 'ccc_sg_basic_auth_on_admin', 0);
		} else {
			// Entire site (use carefully).
			// We are already running on init (priority 0), so run immediately to ensure it applies on this request.
			ccc_sg_basic_auth_on_site();
		}
	}

	if (!empty($s['enable_author_redirect'])) {
		add_action('template_redirect', 'ccc_sg_custom_author_redirect', 0);
	}

	if (!empty($s['enable_rest_user_lock'])) {
		add_filter('rest_endpoints', 'ccc_sg_custom_rest_endpoints', 10, 1);
	}

	if (!empty($s['enable_version_hiding'])) {
		add_filter('the_generator', '__return_empty_string');
		remove_action('wp_head', 'rsd_link');
		remove_action('wp_head', 'wlwmanifest_link');
		remove_action('wp_head', 'wp_shortlink_wp_head', 10);
	}
}

/* Basic Auth */
function ccc_sg_basic_auth_on_login()
{
	$s = ccc_sg_get_settings();
	if (empty($s['enable_basic_auth'])) return;
	if (($s['basic_auth_scope'] ?? 'admin') !== 'admin') return;
	ccc_sg_require_basic_auth($s);
}

function ccc_sg_basic_auth_on_admin()
{
	$s = ccc_sg_get_settings();
	if (empty($s['enable_basic_auth'])) return;
	if (($s['basic_auth_scope'] ?? 'admin') !== 'admin') return;

	// Protect wp-admin only AFTER login.
	if (!is_user_logged_in()) return;

	// Exempt endpoints commonly used for async/background actions.
	if (ccc_sg_is_exempt_basic_auth_admin_endpoint()) return;

	ccc_sg_require_basic_auth($s);
}

function ccc_sg_basic_auth_on_site()
{
	$s = ccc_sg_get_settings();
	if (empty($s['enable_basic_auth'])) return;
	if (($s['basic_auth_scope'] ?? 'admin') !== 'site') return;

	// Never challenge cron.
	if (ccc_sg_is_wp_cron()) return;

	ccc_sg_require_basic_auth($s);
}

function ccc_sg_require_basic_auth($s)
{
	// Never challenge internal processes
	if (defined('DOING_CRON') && DOING_CRON) return;
	if (defined('WP_CLI') && WP_CLI) return;

	$user = (string)($s['basic_auth_user'] ?? '');
	$hash = (string)($s['basic_auth_passhash'] ?? '');
	if ($user === '' || $hash === '') return; // If not configured, do nothing.

	list($in_user, $in_pass) = ccc_sg_get_basic_auth_credentials();
	if ($in_user === null || $in_pass === null) {
		ccc_sg_basic_auth_challenge($s);
	}

	if (!hash_equals($user, (string)$in_user) || !wp_check_password((string)$in_pass, $hash)) {
		ccc_sg_basic_auth_challenge($s);
	}
}

function ccc_sg_basic_auth_challenge($s)
{
	$realm = ccc_sg_plugin_name();
	if (!headers_sent()) {
		header('WWW-Authenticate: Basic realm="' . ccc_sg_header_safe($realm) . '"');
		header('HTTP/1.0 401 Unauthorized');
	}
	echo esc_html__('Authorization required.', CCC_SG_TEXTDOMAIN);
	exit;
}

function ccc_sg_get_basic_auth_credentials()
{
	if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
		return array($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
	}
	$hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
	if ($hdr && stripos($hdr, 'basic ') === 0) {
		$decoded = base64_decode(substr($hdr, 6), true);
		if ($decoded && strpos($decoded, ':') !== false) {
			list($u, $p) = explode(':', $decoded, 2);
			return array($u, $p);
		}
	}
	return array(null, null);
}


function ccc_sg_is_wp_cron()
{
	$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
	return (strpos($uri, 'wp-cron.php') !== false);
}


function ccc_sg_request_basename()
{
	$uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
	$path = parse_url($uri, PHP_URL_PATH);
	if (!$path) return '';
	return basename($path);
}

function ccc_sg_is_exempt_basic_auth_admin_endpoint()
{
	// Exempt endpoints commonly used for async/background actions.
	$script = ccc_sg_request_basename();
	return in_array($script, array('admin-ajax.php', 'admin-post.php', 'async-upload.php'), true);
}

/* Author redirect */
function ccc_sg_custom_author_redirect()
{
	if (!is_author()) return;

	$s = ccc_sg_get_settings();
	$slug = trim((string)$s['author_slug'], '/');
	$key  = (string)$s['author_query_key'];

	if ($slug === '') $slug = 'my-posts';
	if ($key  === '') $key  = 'member';

	$user_id = absint(get_query_var('author'));
	$dest = '/' . $slug . '/?' . rawurlencode($key) . '=' . $user_id;

	wp_safe_redirect(home_url($dest));
	exit;
}

/* REST endpoints lock */
function ccc_sg_custom_rest_endpoints($endpoints)
{
	if (isset($endpoints['/wp/v2/users'])) unset($endpoints['/wp/v2/users']);
	if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
	if (isset($endpoints['/wp/v2/users/me'])) unset($endpoints['/wp/v2/users/me']);
	return $endpoints;
}
