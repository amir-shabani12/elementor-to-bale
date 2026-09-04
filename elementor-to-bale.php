<?php
/**
 * Plugin Name: اتصال فرم Elementor به ربات بله
 * Description: بعد از ارسال فرم Elementor Pro (اکشن Webhook)، اطلاعات فرم را به ربات بله (Bale) ارسال می‌کند. یک بخش تست دو‌طرفه هم دارد تا مطمئن شوید توکن و ارتباط ربات درست است.
 * Version: 1.1.0
 * Author: amirshabani
 * Text Domain: elementor-to-bale
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // جلوگیری از دسترسی مستقیم
}

define( 'ETB_OPTION_KEY', 'etb_settings' );
define( 'ETB_LOG_OPTION_KEY', 'etb_submission_log' );
define( 'ETB_LOG_MAX_ITEMS', 50 );

/**
 * ---------------------------------------------------------------
 * 1) فعال‌سازی پلاگین: ساخت کلید مخفی وبهوک در صورت نبودن
 * ---------------------------------------------------------------
 */
register_activation_hook( __FILE__, function () {
	$settings = get_option( ETB_OPTION_KEY, array() );
	if ( empty( $settings['webhook_key'] ) ) {
		$settings['webhook_key'] = wp_generate_password( 32, false, false );
		update_option( ETB_OPTION_KEY, $settings );
	}
} );

/**
 * ---------------------------------------------------------------
 * 2) منوی تنظیمات در پیشخوان
 * ---------------------------------------------------------------
 */
add_action( 'admin_menu', function () {
	add_options_page(
		'اتصال فرم به بله',
		'اتصال به بله',
		'manage_options',
		'etb-settings',
		'etb_render_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'etb_settings_group', ETB_OPTION_KEY, 'etb_sanitize_settings' );
} );

function etb_sanitize_settings( $input ) {
	$existing = get_option( ETB_OPTION_KEY, array() );

	$output                  = array();
	$output['bot_token']     = isset( $input['bot_token'] ) ? trim( sanitize_text_field( $input['bot_token'] ) ) : '';
	$output['chat_ids']      = isset( $input['chat_ids'] ) ? sanitize_textarea_field( $input['chat_ids'] ) : '';
	$output['message_intro'] = isset( $input['message_intro'] ) ? sanitize_text_field( $input['message_intro'] ) : '';

	// کلید وبهوک را حفظ کن (از طریق فرم تغییر نمی‌کند مگر دکمه "ساخت کلید جدید" زده شود)
	$output['webhook_key'] = isset( $existing['webhook_key'] ) && $existing['webhook_key']
		? $existing['webhook_key']
		: wp_generate_password( 32, false, false );

	if ( ! empty( $input['regenerate_key'] ) ) {
		$output['webhook_key'] = wp_generate_password( 32, false, false );
	}

	return $output;
}

function etb_get_chat_ids_array( $raw ) {
	$parts = preg_split( '/[,\n\r]+/', (string) $raw );
	$parts = array_map( 'trim', $parts );
	$parts = array_filter( $parts, function ( $v ) {
		return $v !== '';
	} );
	return array_values( $parts );
}

function etb_get_webhook_url() {
	$settings = get_option( ETB_OPTION_KEY, array() );
	$key      = isset( $settings['webhook_key'] ) ? $settings['webhook_key'] : '';
	return add_query_arg( 'key', $key, rest_url( 'bale-notifier/v1/webhook' ) );
}

function etb_get_incoming_webhook_url() {
	$settings = get_option( ETB_OPTION_KEY, array() );
	$key      = isset( $settings['webhook_key'] ) ? $settings['webhook_key'] : '';
	return add_query_arg( 'key', $key, rest_url( 'bale-notifier/v1/bot-incoming' ) );
}

function etb_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = get_option( ETB_OPTION_KEY, array() );
	$log      = get_option( ETB_LOG_OPTION_KEY, array() );

	$bot_token     = isset( $settings['bot_token'] ) ? $settings['bot_token'] : '';
	$chat_ids      = isset( $settings['chat_ids'] ) ? $settings['chat_ids'] : '';
	$message_intro = isset( $settings['message_intro'] ) ? $settings['message_intro'] : 'یک فرم جدید ارسال شد';
	$webhook_url   = etb_get_webhook_url();
	$incoming_url  = etb_get_incoming_webhook_url();

	// ارسال پیام تست (به Chat ID های تنظیم‌شده)
	$test_result = '';
	if ( isset( $_POST['etb_test_send'] ) && check_admin_referer( 'etb_test_send_action', 'etb_test_send_nonce' ) ) {
		$test_result = etb_send_to_bale( "🔔 این یک پیام تستی از پلاگین اتصال فرم به بله است.\nاگر این پیام را می‌بینید، اتصال درست کار می‌کند." );
	}

	// دکمه‌های بخش دیباگ / تست دو‌طرفه
	$debug_result = '';
	if ( isset( $_POST['etb_set_incoming_webhook'] ) && check_admin_referer( 'etb_debug_action', 'etb_debug_nonce' ) ) {
		$debug_result = etb_call_bale_api( 'setWebhook', array( 'url' => $incoming_url ) );
	} elseif ( isset( $_POST['etb_get_webhook_info'] ) && check_admin_referer( 'etb_debug_action', 'etb_debug_nonce' ) ) {
		$debug_result = etb_call_bale_api( 'getWebhookInfo', array() );
	} elseif ( isset( $_POST['etb_remove_webhook'] ) && check_admin_referer( 'etb_debug_action', 'etb_debug_nonce' ) ) {
		$debug_result = etb_call_bale_api( 'setWebhook', array( 'url' => '' ) );
	} elseif ( isset( $_POST['etb_get_me'] ) && check_admin_referer( 'etb_debug_action', 'etb_debug_nonce' ) ) {
		$debug_result = etb_call_bale_api( 'getMe', array() );
	}
	?>
	<div class="wrap">
		<h1>اتصال فرم Elementor به ربات بله</h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'etb_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="etb_bot_token">توکن ربات بله (Bot Token)</label></th>
					<td>
						<input type="text" id="etb_bot_token" name="<?php echo esc_attr( ETB_OPTION_KEY ); ?>[bot_token]"
							value="<?php echo esc_attr( $bot_token ); ?>" class="regular-text" placeholder="123456:ABC-DEF1234ghIkl-zyx" />
						<p class="description">توکنی که از @botfather در بله گرفته‌اید.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="etb_chat_ids">شناسه‌های چت (Chat ID)</label></th>
					<td>
						<textarea id="etb_chat_ids" name="<?php echo esc_attr( ETB_OPTION_KEY ); ?>[chat_ids]" rows="4" class="large-text"
							placeholder="هر Chat ID در یک خط یا با کاما جدا شود، مثلا:&#10;123456789&#10;987654321"><?php echo esc_textarea( $chat_ids ); ?></textarea>
						<p class="description">می‌توانید چند Chat ID وارد کنید (هر کدام در یک خط)؛ پیام به همه آن‌ها ارسال می‌شود.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="etb_message_intro">عنوان بالای پیام</label></th>
					<td>
						<input type="text" id="etb_message_intro" name="<?php echo esc_attr( ETB_OPTION_KEY ); ?>[message_intro]"
							value="<?php echo esc_attr( $message_intro ); ?>" class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">ساخت کلید وبهوک جدید</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( ETB_OPTION_KEY ); ?>[regenerate_key]" value="1" />
							کلید امنیتی وبهوک را دوباره بساز (اگر لینک قبلی لو رفته)
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'ذخیره تنظیمات' ); ?>
		</form>

		<hr />

		<h2>آدرس Webhook برای Elementor (ارسال داده فرم)</h2>
		<p>این آدرس را در فرم Elementor Pro، بخش <strong>Actions After Submit → Webhook</strong> قرار دهید:</p>
		<p>
			<input type="text" readonly onclick="this.select();" value="<?php echo esc_url( $webhook_url ); ?>"
				style="width:100%;max-width:700px;padding:6px;" />
		</p>

		<hr />

		<h2>🛠️ تست دو‌طرفه ارتباط با بله (Debug)</h2>
		<p>این بخش برای اطمینان از این‌که توکن و ربات درست کار می‌کنند، مستقل از فرم Elementor است. اول این تست‌ها را انجام بده؛ وقتی جواب گرفتی، مطمئن می‌شویم مشکل از سمت بله/توکن نیست.</p>

		<form method="post" style="margin-bottom:10px;">
			<?php wp_nonce_field( 'etb_debug_action', 'etb_debug_nonce' ); ?>
			<?php submit_button( '۱) بررسی صحت توکن (getMe)', 'secondary', 'etb_get_me', false ); ?>
			&nbsp;
			<?php submit_button( '۲) وضعیت فعلی Webhook (getWebhookInfo)', 'secondary', 'etb_get_webhook_info', false ); ?>
			&nbsp;
			<?php submit_button( '۳) تنظیم Webhook دریافتی برای تست /start', 'primary', 'etb_set_incoming_webhook', false ); ?>
			&nbsp;
			<?php submit_button( 'حذف Webhook دریافتی', 'delete', 'etb_remove_webhook', false ); ?>
		</form>

		<?php if ( $debug_result ) : ?>
			<p><strong>پاسخ بله:</strong></p>
			<pre style="background:#f6f7f7;padding:10px;white-space:pre-wrap;max-width:700px;"><?php echo esc_html( $debug_result ); ?></pre>
		<?php endif; ?>

		<p class="description">
			بعد از زدن دکمه «تنظیم Webhook دریافتی برای تست»، برو تو اپ بله به ربات پیام <code>/start</code> بده.
			اگر همه‌چیز درست باشد، باید فوراً یک پیام تستی («✅ اتصال ربات برقرار است...») از طرف ربات دریافت کنی.
			<br />
			⚠️ توجه: اگر همین توکن قبلاً برای ربات دیگری (مثلاً ربات فروشگاهی) هم استفاده می‌شود و آن ربات هم Webhook خودش را دارد،
			با زدن این دکمه Webhook قبلی آن بازنویسی می‌شود و آن ربات از کار می‌افتد تا دوباره Webhook درست خودش تنظیم شود.
			بعد از پایان تست، حتماً دکمه «حذف Webhook دریافتی» را بزن (چون ما برای ارسال فرم نیازی به Webhook دریافتی نداریم).
		</p>

		<hr />

		<h2>ارسال پیام تست به Chat ID های تنظیم‌شده</h2>
		<form method="post">
			<?php wp_nonce_field( 'etb_test_send_action', 'etb_test_send_nonce' ); ?>
			<?php submit_button( 'ارسال پیام تست به بله', 'secondary', 'etb_test_send', false ); ?>
		</form>
		<?php if ( $test_result ) : ?>
			<p><strong>نتیجه تست:</strong> <?php echo esc_html( is_string( $test_result ) ? $test_result : 'موفق' ); ?></p>
		<?php endif; ?>

		<hr />

		<h2>لاگ آخرین ارسال‌های فرم (<?php echo count( $log ); ?>)</h2>
		<?php if ( empty( $log ) ) : ?>
			<p>هنوز هیچ فرمی ارسال نشده است.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:160px;">تاریخ</th>
						<th>خلاصه پیام</th>
						<th style="width:100px;">وضعیت ارسال به بله</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_reverse( $log ) as $item ) : ?>
						<tr>
							<td><?php echo esc_html( $item['time'] ); ?></td>
							<td><pre style="white-space:pre-wrap;margin:0;"><?php echo esc_html( $item['message'] ); ?></pre></td>
							<td><?php echo $item['success'] ? '✅ موفق' : '❌ خطا: ' . esc_html( $item['error'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * ---------------------------------------------------------------
 * 3) REST endpoint هایی که سرور بله/Elementor به آن‌ها متصل می‌شوند
 * ---------------------------------------------------------------
 */
add_action( 'rest_api_init', function () {
	// وبهوک ارسالی از Elementor (داده فرم می‌آید اینجا)
	register_rest_route( 'bale-notifier/v1', '/webhook', array(
		'methods'             => 'POST',
		'callback'            => 'etb_handle_elementor_webhook',
		'permission_callback' => '__return_true',
	) );

	// وبهوک دریافتی از بله (برای تست /start - فقط جهت دیباگ)
	register_rest_route( 'bale-notifier/v1', '/bot-incoming', array(
		'methods'             => 'POST',
		'callback'            => 'etb_handle_bot_incoming',
		'permission_callback' => '__return_true',
	) );
} );

function etb_handle_elementor_webhook( WP_REST_Request $request ) {
	$settings     = get_option( ETB_OPTION_KEY, array() );
	$expected_key = isset( $settings['webhook_key'] ) ? $settings['webhook_key'] : '';
	$given_key    = $request->get_param( 'key' );

	if ( empty( $expected_key ) || $given_key !== $expected_key ) {
		return new WP_REST_Response( array( 'ok' => false, 'error' => 'کلید امنیتی نامعتبر است.' ), 403 );
	}

	$params = $request->get_params();
	unset( $params['key'] );
	unset( $params['rest_route'] );

	if ( empty( $params ) ) {
		$raw     = $request->get_body();
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$params = $decoded;
		}
	}

	$intro = isset( $settings['message_intro'] ) && $settings['message_intro']
		? $settings['message_intro']
		: 'یک فرم جدید ارسال شد';

	$lines   = array();
	$lines[] = '📩 ' . $intro;
	$lines[] = '🌐 سایت: ' . wp_parse_url( home_url(), PHP_URL_HOST );
	$lines[] = '🕒 زمان: ' . current_time( 'Y-m-d H:i:s' );
	$lines[] = str_repeat( '-', 20 );

	foreach ( $params as $field_name => $field_value ) {
		if ( is_array( $field_value ) ) {
			$field_value = implode( '، ', $field_value );
		}
		$lines[] = $field_name . ': ' . $field_value;
	}

	$message = implode( "\n", $lines );
	$result  = etb_send_to_bale( $message );

	$log   = get_option( ETB_LOG_OPTION_KEY, array() );
	$log[] = array(
		'time'    => current_time( 'Y-m-d H:i:s' ),
		'message' => $message,
		'success' => ( $result === true ),
		'error'   => ( $result === true ) ? '' : $result,
	);
	if ( count( $log ) > ETB_LOG_MAX_ITEMS ) {
		$log = array_slice( $log, -1 * ETB_LOG_MAX_ITEMS );
	}
	update_option( ETB_LOG_OPTION_KEY, $log );

	return new WP_REST_Response( array( 'ok' => ( $result === true ) ), ( $result === true ) ? 200 : 500 );
}

/**
 * وبهوک دریافتی از بله - فقط برای تست ارتباط.
 * وقتی کاربر /start می‌زند، همان لحظه یک پیام تستی برایش پاسخ داده می‌شود.
 */
function etb_handle_bot_incoming( WP_REST_Request $request ) {
	$settings     = get_option( ETB_OPTION_KEY, array() );
	$expected_key = isset( $settings['webhook_key'] ) ? $settings['webhook_key'] : '';
	$given_key    = $request->get_param( 'key' );

	if ( empty( $expected_key ) || $given_key !== $expected_key ) {
		return new WP_REST_Response( array( 'ok' => false ), 403 );
	}

	$bot_token = isset( $settings['bot_token'] ) ? trim( $settings['bot_token'] ) : '';
	$update    = json_decode( $request->get_body(), true );

	if ( is_array( $update ) && isset( $update['message']['chat']['id'] ) ) {
		$chat_id = $update['message']['chat']['id'];
		$text    = trim( $update['message']['text'] ?? '' );

		$reply = ( $text === '/start' )
			? "✅ اتصال ربات به سایت برقرار است.\nChat ID شما: {$chat_id}"
			: "پیام شما دریافت شد.\nChat ID شما: {$chat_id}";

		etb_send_message_to_chat( $bot_token, $chat_id, $reply );
	}

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * ---------------------------------------------------------------
 * 4) توابع کمکی ارتباط با API بله
 * ---------------------------------------------------------------
 */

// فراخوانی عمومی هر متد از API بله (برای دکمه‌های دیباگ)
function etb_call_bale_api( $method, $params = array() ) {
	$settings  = get_option( ETB_OPTION_KEY, array() );
	$bot_token = isset( $settings['bot_token'] ) ? trim( $settings['bot_token'] ) : '';

	if ( empty( $bot_token ) ) {
		return 'توکن ربات تنظیم نشده است.';
	}

	$url = 'https://tapi.bale.ai/bot' . $bot_token . '/' . $method;

	$response = wp_remote_post( $url, array(
		'timeout' => 15,
		'body'    => $params,
	) );

	if ( is_wp_error( $response ) ) {
		return 'خطای اتصال: ' . $response->get_error_message();
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	return "HTTP {$code}\n" . $body;
}

// ارسال پیام به یک Chat ID مشخص
function etb_send_message_to_chat( $bot_token, $chat_id, $text ) {
	if ( empty( $bot_token ) ) {
		return 'توکن ربات تنظیم نشده است.';
	}

	$response = wp_remote_post( 'https://tapi.bale.ai/bot' . $bot_token . '/sendMessage', array(
		'timeout' => 15,
		'body'    => array(
			'chat_id' => $chat_id,
			'text'    => mb_substr( $text, 0, 4000 ),
		),
	) );

	if ( is_wp_error( $response ) ) {
		return $response->get_error_message();
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 || empty( $body['ok'] ) ) {
		return isset( $body['description'] ) ? $body['description'] : 'خطای نامشخص از سرور بله (HTTP ' . $code . ')';
	}

	return true;
}

// ارسال پیام به همه‌ی Chat ID های تنظیم‌شده (برای وبهوک فرم Elementor)
function etb_send_to_bale( $text ) {
	$settings  = get_option( ETB_OPTION_KEY, array() );
	$bot_token = isset( $settings['bot_token'] ) ? trim( $settings['bot_token'] ) : '';
	$chat_ids  = etb_get_chat_ids_array( isset( $settings['chat_ids'] ) ? $settings['chat_ids'] : '' );

	if ( empty( $bot_token ) ) {
		return 'توکن ربات تنظیم نشده است.';
	}
	if ( empty( $chat_ids ) ) {
		return 'هیچ Chat ID ای تنظیم نشده است.';
	}

	$errors = array();

	foreach ( $chat_ids as $chat_id ) {
		$result = etb_send_message_to_chat( $bot_token, $chat_id, $text );
		if ( $result !== true ) {
			$errors[] = $chat_id . ': ' . $result;
		}
	}

	if ( empty( $errors ) ) {
		return true;
	}

	return implode( ' | ', $errors );
}
