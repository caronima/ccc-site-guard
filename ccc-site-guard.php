<?php

/**
 * Plugin Name: CCC Site Guard
 * Description: Consolidated plugin for daily update report emails and security hardening (author redirect / REST user lock / version hiding / Basic Auth).
 * Version: 1.0.0
 * Author: Caronima Inc.
 * Author URI: https://caronima.com
 * Requires PHP: 7.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ccc-site-guard
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CCCSIG_OPT_KEY',  'cccsig_settings');
define('CCCSIG_CRON_HOOK', 'cccsig_daily_event');

define('CCCSIG_MENU_SLUG', 'ccc-site-guard');
define('CCCSIG_CAP', 'manage_options');

/**
 * Plugin header values (Plugin Name / Version / etc.) are readable at runtime.
 * If you rename the plugin in the header comment, UI labels and Realm will follow.
 */
function cccsig_plugin_header()
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

function cccsig_plugin_name()
{
	$h = cccsig_plugin_header();
	$nm = isset($h['name']) && $h['name'] !== '' ? $h['name'] : (isset($h['plugin_name']) ? $h['plugin_name'] : 'CCC Site Guard');
	return $nm !== '' ? $nm : 'CCC Site Guard';
}

function cccsig_header_safe($value)
{
	$value = (string)$value;
	// Prevent header injection
	return str_replace(array("\r", "\n"), '', $value);
}

function cccsig_sanitize_raw_password($value)
{
	// Passwords should not be normalized (it may change intended credentials),
	// but we must strip control characters to avoid header/transport issues.
	$value = (string) $value;
	return str_replace(array("\0", "\r", "\n"), '', $value);
}

function cccsig_sanitize_auth_header($value)
{
	// Authorization header may include base64 characters (+/=/etc.).
	// Strip control characters and non-printable bytes.
	$value = (string) $value;
	$value = str_replace(array("\0", "\r", "\n"), '', $value);
	$value = preg_replace('/[^\x20-\x7E]/', '', $value);
	return trim($value);
}

/**
 * Load translations.
 * Note: For plugins hosted on WordPress.org, translations are loaded automatically
 * since WordPress 4.6. The load_plugin_textdomain() call is kept for self-hosted
 * or development scenarios only.
 */
// Translations are loaded automatically by WordPress.org for hosted plugins.

/*--------------------------------------------------------------
 * 設定値取得
 *--------------------------------------------------------------*/
function cccsig_get_settings()
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

	$opt = get_option(CCCSIG_OPT_KEY, array());
	return array_merge($defaults, is_array($opt) ? $opt : array());
}

/*--------------------------------------------------------------
 * 管理画面メニュー
 *--------------------------------------------------------------*/
add_action('admin_menu', 'cccsig_add_settings_page');
function cccsig_add_settings_page()
{
	add_menu_page(
		cccsig_plugin_name(),
		cccsig_plugin_name(),
		CCCSIG_CAP,
		CCCSIG_MENU_SLUG,
		'cccsig_render_settings_page',
		'dashicons-shield',
		81
	);

	// Visible submenu pointing to the same page
	add_submenu_page(
		CCCSIG_MENU_SLUG,
		cccsig_plugin_name(),
		__('Settings', 'ccc-site-guard'),
		CCCSIG_CAP,
		CCCSIG_MENU_SLUG,
		'cccsig_render_settings_page'
	);
}

/*--------------------------------------------------------------
 * 設定ページ用の軽いJS/CSS（A案：変更時だけパス入力を展開）
 *--------------------------------------------------------------*/
add_action('admin_enqueue_scripts', 'cccsig_admin_assets');
function cccsig_admin_assets($hook)
{
	if ($hook !== 'toplevel_page_' . CCCSIG_MENU_SLUG) return;

	$js = 'document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("ccc-sg-pass-change-btn");
  const box = document.getElementById("ccc-sg-pass-change-box");
  const cancel = document.getElementById("ccc-sg-pass-change-cancel");
  const clear = document.getElementById("ccc-sg-pass-clear");

  function setPassBoxVisible(v){
    if(!box) return;
    box.style.display = v ? "block" : "none";
    if(v){
      const p1 = document.getElementById("ccc-sg-pass1");
      if(p1) p1.focus();
    } else {
      const p1 = document.getElementById("ccc-sg-pass1");
      const p2 = document.getElementById("ccc-sg-pass2");
      if(p1) p1.value = "";
      if(p2) p2.value = "";
    }
  }

  if(btn){
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      setPassBoxVisible(true);
      if(clear) clear.checked = false;
    });
  }
  if(cancel){
    cancel.addEventListener("click", (e) => {
      e.preventDefault();
      setPassBoxVisible(false);
    });
  }
  if(clear){
    clear.addEventListener("change", () => {
      if(clear.checked) setPassBoxVisible(false);
    });
  }

  const dailyEnable = document.getElementById("ccc-sg-enable-daily-report");
  const dailyDeps = document.querySelectorAll(".ccc-sg-dep-daily");
  const dailyNote = document.getElementById("ccc-sg-daily-disabled-note");

  const basicEnable = document.getElementById("ccc-sg-enable-basic-auth");
  const basicDeps = document.querySelectorAll(".ccc-sg-dep-basic");
  const basicNote = document.getElementById("ccc-sg-basic-disabled-note");

  function setRowsVisible(nodes, visible){
    nodes.forEach((el) => {
      el.style.display = visible ? "" : "none";
    });
  }

  function syncDaily(){
    const on = !!(dailyEnable && dailyEnable.checked);
    setRowsVisible(dailyDeps, on);
    if(dailyNote) dailyNote.style.display = on ? "none" : "block";
  }

  function syncBasic(){
    const on = !!(basicEnable && basicEnable.checked);
    setRowsVisible(basicDeps, on);
    if(basicNote) basicNote.style.display = on ? "none" : "block";

    if(!on){
      setPassBoxVisible(false);
      if(clear) clear.checked = false;
    }
  }

  if(dailyEnable){
    dailyEnable.addEventListener("change", syncDaily);
    syncDaily();
  }

  if(basicEnable){
    basicEnable.addEventListener("change", syncBasic);
    syncBasic();
  }
});';

	// Ensure jQuery is available on this admin page
	wp_enqueue_script('jquery-core');
	wp_add_inline_script('jquery-core', $js, 'after');

	$css = '.ccc-sg-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;line-height:1.8}'
		. '.ccc-sg-badge.ok{background:#e7f7ee;color:#0b6b2e;border:1px solid #bfe8cf}'
		. '.ccc-sg-badge.ng{background:#fff1f0;color:#a4000f;border:1px solid #ffd0cc}'
		. '.ccc-sg-subnote{color:#646970;font-size:12px;margin-top:6px}'
		. '.ccc-sg-warning{color:#b32d2e;font-weight:600}'
		. '#ccc-sg-pass-change-box{margin-top:10px;padding:12px;border:1px solid #dcdcde;background:#fff;border-radius:8px;max-width:520px}';

	// Use a dedicated handle so inline CSS is reliably printed
	$h = cccsig_plugin_header();
	$ver = isset($h['version']) && $h['version'] !== '' ? $h['version'] : '0.0.0';
	wp_register_style('ccc-sg-admin', false, array(), $ver);
	wp_enqueue_style('ccc-sg-admin');
	wp_add_inline_style('ccc-sg-admin', $css);
}

/*--------------------------------------------------------------
 * 設定画面
 *--------------------------------------------------------------*/
function cccsig_render_settings_page()
{
	if (!current_user_can(CCCSIG_CAP)) return;

	$notice = array('type' => '', 'msg' => '');

	if (isset($_POST['cccsig_save'])) {
		check_admin_referer('cccsig_save_action');

		$cur = cccsig_get_settings();

		$save = array(
			// 日次レポート
			'enable_daily_report' => isset($_POST['enable_daily_report']) ? 1 : 0,
			'daily_report_time'   => isset($_POST['daily_report_time']) ? cccsig_sanitize_hhmm(sanitize_text_field(wp_unslash($_POST['daily_report_time']))) : $cur['daily_report_time'],
			'notify_emails'       => isset($_POST['notify_emails']) ? sanitize_text_field(wp_unslash($_POST['notify_emails'])) : '',
			'send_even_if_empty'  => isset($_POST['send_even_if_empty']) ? 1 : 0,

			// セキュリティ
			'enable_author_redirect' => isset($_POST['enable_author_redirect']) ? 1 : 0,
			'author_slug'            => isset($_POST['author_slug']) ? sanitize_title(wp_unslash($_POST['author_slug'])) : 'my-posts',
			'author_query_key'       => isset($_POST['author_query_key']) ? sanitize_key(wp_unslash($_POST['author_query_key'])) : 'member',
			'enable_rest_user_lock'  => isset($_POST['enable_rest_user_lock']) ? 1 : 0,
			'enable_version_hiding'  => isset($_POST['enable_version_hiding']) ? 1 : 0,

			// Basic Auth
			'enable_basic_auth'   => isset($_POST['enable_basic_auth']) ? 1 : 0,
			'basic_auth_scope'    => (isset($_POST['basic_auth_scope']) && $_POST['basic_auth_scope'] === 'site') ? 'site' : 'admin',
			'basic_auth_user'     => isset($_POST['basic_auth_user']) ? sanitize_user(wp_unslash($_POST['basic_auth_user']), true) : '',
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

		// Passwords must not be sanitized/normalized; any transformation can break intended credentials.
		$p1 = isset($_POST['basic_auth_password1']) ? cccsig_sanitize_raw_password((string) wp_unslash($_POST['basic_auth_password1'])) : '';
		$p2 = isset($_POST['basic_auth_password2']) ? cccsig_sanitize_raw_password((string) wp_unslash($_POST['basic_auth_password2'])) : '';

		if (!$do_clear && ($p1 !== '' || $p2 !== '')) {
			if ($p1 === '' || $p2 === '') {
				$notice = array('type' => 'error', 'msg' => __('Please fill in both Basic Auth password fields.', 'ccc-site-guard'));
			} elseif (!hash_equals($p1, $p2)) {
				$notice = array('type' => 'error', 'msg' => __('Basic Auth passwords do not match.', 'ccc-site-guard'));
			} else {
				$save['basic_auth_passhash'] = wp_hash_password($p1);
				$notice = array('type' => 'updated', 'msg' => __('Settings saved (Basic Auth password updated).', 'ccc-site-guard'));
			}
		}

		update_option(CCCSIG_OPT_KEY, $save);

		// Cron 再設定
		cccsig_reschedule_daily_event();

		if (empty($notice['msg'])) {
			$notice = array('type' => 'updated', 'msg' => __('Settings saved.', 'ccc-site-guard'));
		}
	}

	$s = cccsig_get_settings();

	if (!empty($notice['msg'])) {
		$cls = ($notice['type'] === 'error') ? 'notice notice-error' : 'notice notice-success';
		echo '<div class="' . esc_attr($cls) . '"><p>' . esc_html($notice['msg']) . '</p></div>';
	}

	$has_pass = !empty($s['basic_auth_passhash']);
	$has_user = !empty($s['basic_auth_user']);
	$basic_ready = ($has_pass && $has_user);
	$daily_enabled = !empty($s['enable_daily_report']);
	$basic_enabled = !empty($s['enable_basic_auth']);
	$daily_row_style = $daily_enabled ? '' : 'display:none;';
	$basic_row_style = $basic_enabled ? '' : 'display:none;';

	// Summaries shown when a section is disabled (avoid leaking sensitive values).
	$daily_recipients = cccsig_parse_emails($s['notify_emails']);
	$daily_recipient_count = is_array($daily_recipients) ? count($daily_recipients) : 0;
	$daily_recipients_label = ($daily_recipient_count > 0)
		? sprintf(
			/* translators: %d is the number of email recipients configured */
			_n('%d recipient configured', '%d recipients configured', $daily_recipient_count, 'ccc-site-guard'),
			$daily_recipient_count
		)
		: __('Not set', 'ccc-site-guard');
	$daily_even_label = !empty($s['send_even_if_empty']) ? __('On', 'ccc-site-guard') : __('Off', 'ccc-site-guard');
	/* translators: %1$s is time (HH:MM), %2$s is recipient count/status, %3$s is on/off status */
	$daily_disabled_summary_format = __('Current saved settings: time %1$s, recipients %2$s, send even if empty %3$s.', 'ccc-site-guard');
	$daily_disabled_summary = sprintf(
		$daily_disabled_summary_format,
		esc_html($s['daily_report_time']),
		esc_html($daily_recipients_label),
		esc_html($daily_even_label)
	);

	$basic_scope_label = (($s['basic_auth_scope'] ?? 'admin') === 'site')
		? __('Entire site', 'ccc-site-guard')
		: __('Admin area', 'ccc-site-guard');
	$basic_user_label = $has_user ? __('Configured', 'ccc-site-guard') : __('Not set', 'ccc-site-guard');
	$basic_pass_label = $has_pass ? __('Configured', 'ccc-site-guard') : __('Not set', 'ccc-site-guard');
	/* translators: %1$s is scope (admin/site), %2$s is username status, %3$s is password status */
	$basic_disabled_summary_format = __('Current saved settings: scope %1$s, username %2$s, password %3$s.', 'ccc-site-guard');
	$basic_disabled_summary = sprintf(
		$basic_disabled_summary_format,
		esc_html($basic_scope_label),
		esc_html($basic_user_label),
		esc_html($basic_pass_label)
	);
	$basic_disabled_summary_note = __('(Passwords are never displayed.)', 'ccc-site-guard');
?>
	<div class="wrap">
		<h1><?php echo esc_html(cccsig_plugin_name()); ?></h1>

		<form method="post">
			<?php wp_nonce_field('cccsig_save_action'); ?>

			<h2><?php echo esc_html__('1. Daily update report', 'ccc-site-guard'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('Enable', 'ccc-site-guard'); ?></th>
					<td><label>
							<input id="ccc-sg-enable-daily-report" type="checkbox" name="enable_daily_report" value="1" <?php checked($s['enable_daily_report'], 1); ?>>
							<?php echo esc_html__('Send a daily summary of available updates.', 'ccc-site-guard'); ?>
						</label>
						<div id="ccc-sg-daily-disabled-note" class="ccc-sg-subnote" style="display:<?php echo $daily_enabled ? 'none' : 'block'; ?>;">
							<?php echo esc_html__('Turn on Enable to configure the schedule and notification settings below. Note: delivery timing depends on site requests (WP-Cron).', 'ccc-site-guard'); ?>
							<br>
							<?php echo esc_html($daily_disabled_summary); ?>
						</div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-daily" style="<?php echo esc_attr($daily_row_style); ?>">
					<th scope="row"><?php echo esc_html__('Send time (site timezone)', 'ccc-site-guard'); ?></th>
					<td>
						<input type="time" name="daily_report_time" value="<?php echo esc_attr($s['daily_report_time']); ?>">
						<div class="ccc-sg-subnote">
							<?php echo esc_html__('This report uses WP-Cron (pseudo-cron). It is triggered by site requests, not by the server clock.', 'ccc-site-guard'); ?><br>
							<?php echo esc_html__('It will be sent on the first site request *after* the scheduled time. On low-traffic sites, delivery can be delayed.', 'ccc-site-guard'); ?>
						</div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-daily" style="<?php echo esc_attr($daily_row_style); ?>">
					<th scope="row"><?php echo esc_html__('Notification email(s)', 'ccc-site-guard'); ?></th>
					<td>
						<input type="text" class="regular-text" name="notify_emails"
							value="<?php echo esc_attr($s['notify_emails']); ?>"
							placeholder="wp-report@your-company.jp, second@mail.com">
						<p class="description"><?php echo esc_html__('You can specify multiple addresses separated by commas.', 'ccc-site-guard'); ?></p>
					</td>
				</tr>

				<tr class="ccc-sg-dep-daily" style="<?php echo esc_attr($daily_row_style); ?>">
					<th scope="row"><?php echo esc_html__('Send even when there are no updates', 'ccc-site-guard'); ?></th>
					<td><label>
							<input type="checkbox" name="send_even_if_empty" value="1" <?php checked($s['send_even_if_empty'], 1); ?>>
							<?php echo esc_html__('Send a report even when there are no updates.', 'ccc-site-guard'); ?>
						</label></td>
				</tr>
			</table>

			<hr>

			<h2><?php echo esc_html__('2. Security', 'ccc-site-guard'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('REST API user endpoint lock', 'ccc-site-guard'); ?></th>
					<td><label>
							<input type="checkbox" name="enable_rest_user_lock" value="1" <?php checked($s['enable_rest_user_lock'], 1); ?>>
							<?php echo esc_html__('Disable user-related endpoints such as', 'ccc-site-guard'); ?> <code>/wp/v2/users</code>
						</label></td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__('Hide version information', 'ccc-site-guard'); ?></th>
					<td><label>
							<input type="checkbox" name="enable_version_hiding" value="1" <?php checked($s['enable_version_hiding'], 1); ?>>
							<code>the_generator</code> <?php echo esc_html__('Reduce information exposure (e.g., suppress the_generator).', 'ccc-site-guard'); ?>
						</label></td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__('Author archive redirect', 'ccc-site-guard'); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_author_redirect" value="1" <?php checked($s['enable_author_redirect'], 1); ?>>
							<?php echo esc_html__('(Optional / advanced) Redirect author pages to avoid user_login enumeration.', 'ccc-site-guard'); ?>
						</label>
						<p>
							<label><?php echo esc_html__('Slug:', 'ccc-site-guard'); ?>
								<input type="text" name="author_slug" value="<?php echo esc_attr($s['author_slug']); ?>" class="regular-text">
							</label><br>
							<label><?php echo esc_html__('Query key:', 'ccc-site-guard'); ?>
								<input type="text" name="author_query_key" value="<?php echo esc_attr($s['author_query_key']); ?>" class="regular-text">
							</label>
						</p>
					</td>
				</tr>
			</table>

			<hr>

			<h2><?php echo esc_html__('3. Basic Auth', 'ccc-site-guard'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('Enable', 'ccc-site-guard'); ?></th>
					<td><label>
							<input id="ccc-sg-enable-basic-auth" type="checkbox" name="enable_basic_auth" value="1" <?php checked($s['enable_basic_auth'], 1); ?>>
							<?php echo esc_html__('Enable Basic Auth', 'ccc-site-guard'); ?>
						</label>
						<div class="ccc-sg-subnote">
							<?php echo esc_html__('Status:', 'ccc-site-guard'); ?>
							<?php if ($basic_ready): ?>
								<span class="ccc-sg-badge ok"><?php echo esc_html__('Configured', 'ccc-site-guard'); ?></span>
							<?php else: ?>
								<span class="ccc-sg-badge ng"><?php echo esc_html__('Not configured', 'ccc-site-guard'); ?></span>
							<?php endif; ?>
							<?php if (!empty($s['enable_basic_auth']) && !$basic_ready): ?>
								<span class="ccc-sg-subnote"><?php echo esc_html__('Even if enabled, it will not work until both username and password are set.', 'ccc-site-guard'); ?></span>
							<?php endif; ?>
						</div>
						<div id="ccc-sg-basic-disabled-note" class="ccc-sg-subnote" style="display:<?php echo $basic_enabled ? 'none' : 'block'; ?>;">
							<?php echo esc_html__('Turn on Enable to configure scope, username and password.', 'ccc-site-guard'); ?>
							<br>
							<?php echo esc_html($basic_disabled_summary); ?>
							<span style="margin-left:6px;"><?php echo esc_html($basic_disabled_summary_note); ?></span>
						</div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-basic" style="<?php echo esc_attr($basic_row_style); ?>">
					<th scope="row"><?php echo esc_html__('Scope', 'ccc-site-guard'); ?></th>
					<td>
						<label><input type="radio" name="basic_auth_scope" value="admin" <?php checked($s['basic_auth_scope'], 'admin'); ?>> <?php echo esc_html__('Admin area', 'ccc-site-guard'); ?></label><br>
						<label><input type="radio" name="basic_auth_scope" value="site" <?php checked($s['basic_auth_scope'], 'site');  ?>> <?php echo esc_html__('Entire site', 'ccc-site-guard'); ?></label>
						<div class="ccc-sg-subnote"><?php echo esc_html__('Protecting the entire site may affect integrations (APIs, images, forms, etc.).', 'ccc-site-guard'); ?></div>
					</td>
				</tr>

				<tr class="ccc-sg-dep-basic" style="<?php echo esc_attr($basic_row_style); ?>">
					<th scope="row"><?php echo esc_html__('Username', 'ccc-site-guard'); ?></th>
					<td><input type="text" class="regular-text" name="basic_auth_user" value="<?php echo esc_attr($s['basic_auth_user']); ?>"></td>
				</tr>

				<tr class="ccc-sg-dep-basic" style="<?php echo esc_attr($basic_row_style); ?>">
					<th scope="row"><?php echo esc_html__('Password', 'ccc-site-guard'); ?></th>
					<td>
						<?php if ($has_pass): ?>
							<span class="ccc-sg-badge ok"><?php echo esc_html__('Configured', 'ccc-site-guard'); ?></span>
						<?php else: ?>
							<span class="ccc-sg-badge ng"><?php echo esc_html__('Not configured', 'ccc-site-guard'); ?></span>
						<?php endif; ?>

						<?php
						// Button/intro text should differ between first-time setup and already-configured state.
						$pass_btn_label = $has_pass
							? __('Change password…', 'ccc-site-guard')
							: __('Set password…', 'ccc-site-guard');

						$pass_box_intro = $has_pass
							? __('Enter a new password.', 'ccc-site-guard')
							: __('Set a password.', 'ccc-site-guard');
						?>

						<p style="margin-top:10px;">
							<button class="button" id="ccc-sg-pass-change-btn"><?php echo esc_html($pass_btn_label); ?></button>

							<?php if ($has_pass): ?>
								<label style="margin-left:10px;">
									<input type="checkbox" id="ccc-sg-pass-clear" name="basic_auth_clear" value="1">
									<?php echo esc_html__('Clear password', 'ccc-site-guard'); ?>
								</label>
							<?php endif; ?>
						</p>

						<div id="ccc-sg-pass-change-box" style="display:none;">
							<p style="margin-top:0;"><?php echo esc_html($pass_box_intro); ?></p>
							<p>
								<label><?php echo esc_html__('New password', 'ccc-site-guard'); ?><br>
									<input id="ccc-sg-pass1" type="password" class="regular-text" name="basic_auth_password1" autocomplete="new-password">
								</label>
							</p>
							<p>
								<label><?php echo esc_html__('Confirm (again)', 'ccc-site-guard'); ?><br>
									<input id="ccc-sg-pass2" type="password" class="regular-text" name="basic_auth_password2" autocomplete="new-password">
								</label>
							</p>
							<p style="margin-bottom:0;">
								<button class="button" id="ccc-sg-pass-change-cancel"><?php echo esc_html__('Cancel', 'ccc-site-guard'); ?></button>
							</p>
						</div>

						<div class="ccc-sg-subnote"><?php echo esc_html__('Passwords are stored as a hash (never as plain text).', 'ccc-site-guard'); ?></div>
					</td>
				</tr>


			</table>
			<hr>

			<h2><?php echo esc_html__('4. Cleanup', 'ccc-site-guard'); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__('On deactivation', 'ccc-site-guard'); ?></th>
					<td>
						<label>
							<input type="checkbox" name="cleanup_on_deactivate" value="1" <?php checked($s['cleanup_on_deactivate'], 1); ?>>
							<?php echo esc_html__('Delete settings and related data when the plugin is deactivated.', 'ccc-site-guard'); ?>
						</label>
						<div class="ccc-sg-warning"><?php echo esc_html__('WARNING: This cannot be undone. Keep this OFF for normal use.', 'ccc-site-guard'); ?></div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('On deletion', 'ccc-site-guard'); ?></th>
					<td>
						<label>
							<input type="checkbox" name="cleanup_on_uninstall" value="1" <?php checked($s['cleanup_on_uninstall'], 1); ?>>
							<?php echo esc_html__('Delete settings and related data when the plugin is deleted.', 'ccc-site-guard'); ?>
						</label>
						<div class="ccc-sg-subnote"><?php echo esc_html__('Recommended: Turn ON only if you want a complete wipe on deletion. If enabled, deleting the plugin will permanently remove all settings and related data with no leftovers.', 'ccc-site-guard'); ?></div>
						<div class="ccc-sg-warning"><?php echo esc_html__('WARNING: If this is ON when you delete the plugin, it cannot be restored (unless you have a backup).', 'ccc-site-guard'); ?></div>
					</td>
				</tr>
			</table>

			<?php submit_button(__('Save settings', 'ccc-site-guard'), 'primary', 'cccsig_save'); ?>
		</form>
	</div>
<?php
}

function cccsig_sanitize_hhmm($v)
{
	$v = trim((string)$v);
	return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '09:00';
}

/*--------------------------------------------------------------
 * Cron
 *--------------------------------------------------------------*/
register_activation_hook(__FILE__, 'cccsig_activate');
function cccsig_activate()
{
	cccsig_reschedule_daily_event();
}

register_deactivation_hook(__FILE__, 'cccsig_deactivate');
function cccsig_deactivate()
{
	// Always remove scheduled cron on deactivation
	wp_clear_scheduled_hook(CCCSIG_CRON_HOOK);

	// Optional cleanup on deactivation
	$s = get_option(CCCSIG_OPT_KEY, array());
	if (is_array($s) && !empty($s['cleanup_on_deactivate'])) {
		cccsig_cleanup_all();
	}
}

register_uninstall_hook(__FILE__, 'cccsig_uninstall');
function cccsig_uninstall()
{
	// Only clean up if the user opted in
	$s = get_option(CCCSIG_OPT_KEY, array());
	if (is_array($s) && !empty($s['cleanup_on_uninstall'])) {
		cccsig_cleanup_all();
	}
}

/**
 * クリーンアップ（設定/スケジュール/生成物）
 */
function cccsig_cleanup_all()
{
	// 予定されたCronを除去
	wp_clear_scheduled_hook(CCCSIG_CRON_HOOK);

	// 設定オプション
	delete_option(CCCSIG_OPT_KEY);

	// 生成ファイルがある場合（将来拡張用）: uploads/ccc-site-guard を削除
	cccsig_delete_uploads_dir('ccc-site-guard');
}

function cccsig_delete_uploads_dir($subdir)
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

	cccsig_rrmdir($real_target);
}

function cccsig_rrmdir($dir)
{
	global $wp_filesystem;

	// Initialize WP_Filesystem if not already done
	if (empty($wp_filesystem)) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	$items = @scandir($dir);
	if (!is_array($items)) return;
	foreach ($items as $item) {
		if ($item === '.' || $item === '..') continue;
		$path = $dir . '/' . $item;
		if (is_dir($path)) {
			cccsig_rrmdir($path);
		} else {
			wp_delete_file($path);
		}
	}

	if ($wp_filesystem && $wp_filesystem->is_dir($dir)) {
		$wp_filesystem->rmdir($dir);
	}
}

add_action('plugins_loaded', 'cccsig_ensure_daily_event_scheduled');
function cccsig_ensure_daily_event_scheduled()
{
	$s = cccsig_get_settings();
	if (empty($s['enable_daily_report'])) return;

	// If the schedule is missing (e.g. after migration/cache flush), recreate it.
	if (wp_next_scheduled(CCCSIG_CRON_HOOK)) return;

	$next = cccsig_calc_next_run_timestamp($s['daily_report_time']);
	wp_schedule_event($next, 'daily', CCCSIG_CRON_HOOK);
}

function cccsig_reschedule_daily_event()
{
	$s = cccsig_get_settings();
	// This is called on activation / settings-save to apply a new schedule.
	wp_clear_scheduled_hook(CCCSIG_CRON_HOOK);

	if (empty($s['enable_daily_report'])) return;

	$next = cccsig_calc_next_run_timestamp($s['daily_report_time']);
	if (!wp_next_scheduled(CCCSIG_CRON_HOOK)) {
		wp_schedule_event($next, 'daily', CCCSIG_CRON_HOOK);
	}
}

function cccsig_calc_next_run_timestamp($hhmm)
{
	$tz  = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(get_option('timezone_string') ?: 'UTC');
	$now = new DateTime('now', $tz);

	list($h, $m) = array_map('intval', explode(':', $hhmm));
	$run = new DateTime('now', $tz);
	$run->setTime($h, $m, 0);
	if ($run <= $now) $run->modify('+1 day');

	return $run->getTimestamp();
}

add_action(CCCSIG_CRON_HOOK, 'cccsig_run_daily_report');

/*--------------------------------------------------------------
 * 日次レポート（available updatesベース）
 *--------------------------------------------------------------*/
function cccsig_run_daily_report()
{
	$s = cccsig_get_settings();
	if (empty($s['enable_daily_report'])) return;

	$emails = cccsig_parse_emails($s['notify_emails']);
	if (empty($emails)) return;

	if (function_exists('wp_update_plugins')) wp_update_plugins();
	if (function_exists('wp_update_themes'))  wp_update_themes();
	if (function_exists('wp_version_check'))  wp_version_check();

	$plugins = get_site_transient('update_plugins');
	$themes  = get_site_transient('update_themes');
	$core    = get_site_transient('update_core');
	// Transients can be `false` in some cases; normalize to avoid warnings.
	$plugins_checked = (is_object($plugins) && isset($plugins->checked) && is_array($plugins->checked)) ? $plugins->checked : array();
	$themes_checked  = (is_object($themes) && isset($themes->checked) && is_array($themes->checked)) ? $themes->checked : array();

	$report = "";

	if (is_object($plugins) && !empty($plugins->response)) {
		if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all = function_exists('get_plugins') ? get_plugins() : array();

		$report .= __("Plugins needing update:\n", 'ccc-site-guard');
		foreach ($plugins->response as $plugin_file => $info) {
			$name = isset($all[$plugin_file]['Name']) ? $all[$plugin_file]['Name'] : ($info->slug ?? $plugin_file);

			// Current (installed) version
			$cur = '';
			if (isset($all[$plugin_file]['Version']) && $all[$plugin_file]['Version'] !== '') {
				$cur = (string) $all[$plugin_file]['Version'];
			} elseif (isset($plugins_checked[$plugin_file]) && $plugins_checked[$plugin_file] !== '') {
				$cur = (string) $plugins_checked[$plugin_file];
			} elseif (isset($info->old_version) && $info->old_version !== '') {
				$cur = (string) $info->old_version;
			}

			// New (available) version
			$new = (isset($info->new_version) && $info->new_version !== '') ? (string) $info->new_version : '';

			if ($cur === '') $cur = __('unknown', 'ccc-site-guard');
			if ($new === '') $new = __('unknown', 'ccc-site-guard');

			$report .= "- {$name}: {$cur} → {$new}\n";
		}
		$report .= "\n";
	}

	if (is_object($themes) && !empty($themes->response)) {
		$report .= __("Themes needing update:\n", 'ccc-site-guard');
		foreach ($themes->response as $slug => $info) {
			$theme = wp_get_theme($slug);
			$name  = ($theme && $theme->exists()) ? $theme->get('Name') : $slug;

			// Current (installed) version
			$cur = '';
			if ($theme && $theme->exists() && $theme->get('Version')) {
				$cur = (string) $theme->get('Version');
			} elseif (isset($themes_checked[$slug]) && $themes_checked[$slug] !== '') {
				$cur = (string) $themes_checked[$slug];
			} elseif (isset($info['old_version']) && $info['old_version'] !== '') {
				$cur = (string) $info['old_version'];
			}

			// New (available) version
			$new = (isset($info['new_version']) && $info['new_version'] !== '') ? (string) $info['new_version'] : '';

			if ($cur === '') $cur = __('unknown', 'ccc-site-guard');
			if ($new === '') $new = __('unknown', 'ccc-site-guard');

			$report .= "- {$name}: {$cur} → {$new}\n";
		}
		$report .= "\n";
	}

	if (is_object($core) && !empty($core->updates) && is_array($core->updates)) {
		foreach ($core->updates as $u) {
			if (($u->response ?? '') === 'upgrade') {
				$cur = get_bloginfo('version');
				$new = $u->version ?? '';
				/* translators: %1$s is current WordPress version, %2$s is new version available */
				$report .= sprintf(__("WordPress core update available: %1\$s → %2\$s\n\n", 'ccc-site-guard'), $cur, $new);
				break;
			}
		}
	}

	if (trim($report) === '') {
		if (empty($s['send_even_if_empty'])) return;
		$report = __("No updates are available today.\n", 'ccc-site-guard');
	}

	$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	/* translators: %1$s is plugin name, %2$s is site name */
	$subject = sprintf(__("[%1\$s] %2\$s - Daily Update Report", 'ccc-site-guard'), cccsig_plugin_name(), $site_name);
	$body    = __("Daily WordPress Update Report (Available Updates)\n", 'ccc-site-guard') . home_url() . "\n\n" . $report;

	wp_mail($emails, $subject, $body);
}

function cccsig_parse_emails($raw)
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
// Register Basic Auth hooks early.
// - `plugins_loaded` runs before `init`, and before `login_init` / `admin_init` are fired.
// - Callbacks themselves check plugin settings/scope, so registering them is safe.
add_action('plugins_loaded', 'cccsig_register_basic_auth_hooks', 0);
function cccsig_register_basic_auth_hooks()
{
	if (!has_action('login_init', 'cccsig_basic_auth_on_login')) {
		add_action('login_init', 'cccsig_basic_auth_on_login', 0);
	}
	if (!has_action('admin_init', 'cccsig_basic_auth_on_admin')) {
		add_action('admin_init', 'cccsig_basic_auth_on_admin', 0);
	}
	if (!has_action('init', 'cccsig_basic_auth_on_site')) {
		add_action('init', 'cccsig_basic_auth_on_site', 0);
	}
}

add_action('init', 'cccsig_boot_security', 0);
function cccsig_boot_security()
{
	$s = cccsig_get_settings();

	if (!empty($s['enable_author_redirect'])) {
		add_action('template_redirect', 'cccsig_custom_author_redirect', 0);
	}

	if (!empty($s['enable_rest_user_lock'])) {
		add_filter('rest_endpoints', 'cccsig_custom_rest_endpoints', 10, 1);
	}

	if (!empty($s['enable_version_hiding'])) {
		add_filter('the_generator', '__return_empty_string');
		remove_action('wp_head', 'rsd_link');
		remove_action('wp_head', 'wlwmanifest_link');
		remove_action('wp_head', 'wp_shortlink_wp_head', 10);
	}
}

/* Basic Auth */
function cccsig_basic_auth_on_login()
{
	$s = cccsig_get_settings();
	if (empty($s['enable_basic_auth'])) return;
	if (($s['basic_auth_scope'] ?? 'admin') !== 'admin') return;
	cccsig_require_basic_auth($s);
}

function cccsig_basic_auth_on_admin()
{
	$s = cccsig_get_settings();
	if (empty($s['enable_basic_auth'])) return;
	if (($s['basic_auth_scope'] ?? 'admin') !== 'admin') return;

	// Protect wp-admin only AFTER login.
	if (!is_user_logged_in()) return;

	// Exempt endpoints commonly used for async/background actions.
	if (cccsig_is_exempt_basic_auth_admin_endpoint()) return;

	cccsig_require_basic_auth($s);
}

function cccsig_basic_auth_on_site()
{
	$s = cccsig_get_settings();
	if (empty($s['enable_basic_auth'])) return;
	if (($s['basic_auth_scope'] ?? 'admin') !== 'site') return;

	// Never challenge cron.
	if (cccsig_is_wp_cron()) return;

	cccsig_require_basic_auth($s);
}

function cccsig_require_basic_auth($s)
{
	// Never challenge internal processes
	if (defined('DOING_CRON') && DOING_CRON) return;
	if (defined('WP_CLI') && WP_CLI) return;

	$user = (string)($s['basic_auth_user'] ?? '');
	$hash = (string)($s['basic_auth_passhash'] ?? '');
	if ($user === '' || $hash === '') return; // If not configured, do nothing.

	list($in_user, $in_pass) = cccsig_get_basic_auth_credentials();
	if ($in_user === null || $in_pass === null) {
		cccsig_basic_auth_challenge($s);
	}

	if (!hash_equals($user, (string)$in_user) || !wp_check_password((string)$in_pass, $hash)) {
		cccsig_basic_auth_challenge($s);
	}
}

function cccsig_basic_auth_challenge($s)
{
	$realm = cccsig_plugin_name();
	if (!headers_sent()) {
		header('WWW-Authenticate: Basic realm="' . cccsig_header_safe($realm) . '"');
		header('HTTP/1.0 401 Unauthorized');
	}
	echo esc_html__('Authorization required.', 'ccc-site-guard');
	exit;
}

function cccsig_get_basic_auth_credentials()
{
	if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
		$user = sanitize_text_field(wp_unslash($_SERVER['PHP_AUTH_USER']));
		// Do not sanitize/normalize passwords; it can change the credential.
		$pass = cccsig_sanitize_raw_password((string) wp_unslash($_SERVER['PHP_AUTH_PW']));
		return array($user, $pass);
	}

	$hdr = null;
	if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
		$hdr = cccsig_sanitize_auth_header((string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
	} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
		$hdr = cccsig_sanitize_auth_header((string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
	}

	if (is_string($hdr) && $hdr !== '' && stripos($hdr, 'basic ') === 0) {
		$decoded = base64_decode(substr($hdr, 6), true);
		if ($decoded && strpos($decoded, ':') !== false) {
			list($u, $p) = explode(':', $decoded, 2);
			return array($u, $p);
		}
	}
	return array(null, null);
}


function cccsig_is_wp_cron()
{
	$uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
	return (strpos($uri, 'wp-cron.php') !== false);
}


function cccsig_request_basename()
{
	$uri  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
	$path = wp_parse_url($uri, PHP_URL_PATH);
	if (!$path) return '';
	return basename($path);
}

function cccsig_is_exempt_basic_auth_admin_endpoint()
{
	// Exempt endpoints commonly used for async/background actions.
	$script = cccsig_request_basename();
	return in_array($script, array('admin-ajax.php', 'admin-post.php', 'async-upload.php'), true);
}

/* Author redirect */
function cccsig_custom_author_redirect()
{
	if (!is_author()) return;

	$s = cccsig_get_settings();
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
function cccsig_custom_rest_endpoints($endpoints)
{
	if (isset($endpoints['/wp/v2/users'])) unset($endpoints['/wp/v2/users']);
	if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
	if (isset($endpoints['/wp/v2/users/me'])) unset($endpoints['/wp/v2/users/me']);
	return $endpoints;
}
