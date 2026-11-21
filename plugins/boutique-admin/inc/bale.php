<?php
/**
 * Order alerts in Bale (بله) and Telegram, free: a bot on each sends the owner a message for
 * every new online order and every uploaded receipt. Bale's servers are in Iran, so its alerts
 * keep working during international cutoffs; Telegram is the backup.
 *
 * Setup («اعلان سفارش» page), per messenger: create a bot with @BotFather, paste its token,
 * send /start to the bot from the owner's account, press «اتصال». The bot can only message people who
 * started a chat with it (a phone number alone isn't enough).
 *
 * Messages are sent after the customer's page has been delivered (fastcgi_finish_request), so
 * checkout never waits on Bale.
 */
defined( 'ABSPATH' ) || exit;

/** Messenger channels: Bale (in Iran, works during cutoffs) and Telegram. Same bot API. */
function boutique_notify_channels() {
	return [
		'bale' => [ 'name' => 'بله', 'api' => 'https://tapi.bale.ai/bot', 'opt' => 'boutique_bale' ],
		'tg'   => [ 'name' => 'تلگرام', 'api' => 'https://api.telegram.org/bot', 'opt' => 'boutique_tg' ],
	];
}

function boutique_bale_call( $method, array $args = [], $timeout = 10, $ch = 'bale' ) {
	$c = boutique_notify_channels()[ $ch ];
	// Automated tests: fake the messenger (see boutique_bale_send()).
	if ( get_option( 'boutique_bale_capture' ) ) {
		$fails = (int) get_option( 'boutique_bale_fake_fail', 0 );   // tests: next N sends fail
		if ( 'sendMessage' === $method && $fails > 0 ) {
			update_option( 'boutique_bale_fake_fail', $fails - 1, false );
			return new WP_Error( 'http_request_failed', 'cURL error 7: Connection refused (test)' );
		}
		if ( 'getUpdates' === $method ) { return (array) get_option( 'boutique_bale_fake_updates', [] ); }
		if ( 'getMe' === $method ) { return [ 'username' => 'test_bot', 'first_name' => 'test' ]; }
		$box   = (array) get_option( 'boutique_bale_outbox', [] );
		$box[] = ( $args['chat_id'] ?? '' ) . ': ' . ( $args['text'] ?? '' );
		update_option( 'boutique_bale_outbox', $box, false );
		return [ 'message_id' => 1 ];
	}
	$token = trim( (string) get_option( $c['opt'] . '_token' ) );
	if ( ! $token ) { return new WP_Error( 'no_token', 'توکن ربات وارد نشده.' ); }
	$r = wp_remote_post( $c['api'] . $token . '/' . $method, [ 'timeout' => $timeout, 'body' => $args ] );
	if ( is_wp_error( $r ) ) { return $r; }
	$body = json_decode( wp_remote_retrieve_body( $r ), true );
	return ( is_array( $body ) && ! empty( $body['ok'] ) ) ? $body['result'] : new WP_Error( 'api', $body['description'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $r ) ) );
}

/** Retry schedule: inline waits (seconds), then the queue keeps doubling the gap until GIVEUP. */
const BOUTIQUE_NOTIFY_INLINE  = [ 2, 4, 8, 16 ];
const BOUTIQUE_NOTIFY_GIVEUP  = 30 * MINUTE_IN_SECONDS;

function boutique_notify_sleep( $s ) {
	if ( ! get_option( 'boutique_bale_capture' ) ) { sleep( $s ); }   // tests don't wait
}

/** One message to one chat; true or WP_Error. */
function boutique_notify_one( $ch, $chat_id, $text ) {
	return boutique_bale_call( 'sendMessage', [ 'chat_id' => $chat_id, 'text' => $text ], 10, $ch );
}

/**
 * Send a text to every connected chat on every channel (one failing doesn't stop the other).
 * $retry: on failure try again after 2, 4, 8, 16 s, then hand it to the background queue.
 */
function boutique_bale_send( $text, $only = null, $retry = false ) {
	$capture = get_option( 'boutique_bale_capture' );
	$result  = new WP_Error( 'no_chat', 'هنوز به هیچ حسابی وصل نشده.' );
	$sent    = false;
	foreach ( boutique_notify_channels() as $ch => $c ) {
		if ( $only && $only !== $ch ) { continue; }
		$targets = array_keys( array_filter( (array) get_option( $c['opt'] . '_chats', [] ) ) );
		if ( $capture && ! $targets && 'bale' === $ch ) { $targets = [ 'test' ]; }   // tests: one pretend chat
		foreach ( $targets as $chat_id ) {
			$r = boutique_notify_one( $ch, $chat_id, $text );
			if ( $retry ) {
				foreach ( BOUTIQUE_NOTIFY_INLINE as $wait ) {
					if ( ! is_wp_error( $r ) ) { break; }
					boutique_notify_sleep( $wait );
					$r = boutique_notify_one( $ch, $chat_id, $text );
				}
				if ( is_wp_error( $r ) ) { boutique_notify_queue_add( $ch, $chat_id, $text ); }
			}
			if ( is_wp_error( $r ) ) { $result = new WP_Error( 'api', $c['name'] . ': ' . $r->get_error_message() ); }
			else { $sent = true; }
		}
	}
	return $sent ? true : $result;   // (in tests, boutique_bale_call() records each delivered message)
}

/* ---- Background retry queue (wp-cron, every 5 minutes) ---- */
function boutique_notify_queue_add( $ch, $chat_id, $text ) {
	$q   = (array) get_option( 'boutique_notify_queue', [] );
	$now = time();
	$q[] = [ 'ch' => $ch, 'chat' => (string) $chat_id, 'text' => $text, 'first' => $now - array_sum( BOUTIQUE_NOTIFY_INLINE ), 'wait' => 32, 'next' => $now + 32 ];
	update_option( 'boutique_notify_queue', $q, false );
}

function boutique_notify_queue_run() {
	$q    = (array) get_option( 'boutique_notify_queue', [] );
	$keep = [];
	$now  = time();
	foreach ( $q as $m ) {
		if ( $m['next'] > $now ) { $keep[] = $m; continue; }
		$r = boutique_notify_one( $m['ch'], $m['chat'], $m['text'] );
		if ( ! is_wp_error( $r ) ) { continue; }                              // delivered
		if ( $now - $m['first'] >= BOUTIQUE_NOTIFY_GIVEUP ) {                // gave up
			$log   = (array) get_option( 'boutique_notify_dropped', [] );
			$log[] = [ 'ch' => $m['ch'], 'at' => $now, 'text' => mb_substr( $m['text'], 0, 120 ) ];
			update_option( 'boutique_notify_dropped', array_slice( $log, -20 ), false );
			continue;
		}
		$m['wait'] *= 2;
		$m['next']  = $now + $m['wait'];
		$keep[]     = $m;
	}
	update_option( 'boutique_notify_queue', $keep, false );
}
add_action( 'boutique_notify_retry', 'boutique_notify_queue_run' );
add_filter( 'cron_schedules', function ( $s ) {
	$s['boutique_5min'] = [ 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'هر ۵ دقیقه' ];
	return $s;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'boutique_notify_retry' ) ) {
		wp_schedule_event( time() + 60, 'boutique_5min', 'boutique_notify_retry' );
	}
} );

/** Queue a message to go out after the visitor's page is done. */
function boutique_bale_later( $text ) {
	static $queue = null;
	if ( null === $queue ) {
		$queue = [];
		register_shutdown_function( function () use ( &$queue ) {
			if ( ! $queue ) { return; }
			if ( function_exists( 'fastcgi_finish_request' ) ) { fastcgi_finish_request(); }
			elseif ( function_exists( 'litespeed_finish_request' ) ) { litespeed_finish_request(); }
			ignore_user_abort( true );
			@set_time_limit( 120 );                                      // room for the 2+4+8+16 s retries
			foreach ( $queue as $t ) { boutique_bale_send( $t, null, true ); }
		} );
	}
	$queue[] = $text;
}

/* ------------------------------------------------------------ Messages */
function boutique_bale_order_text( WC_Order $o ) {
	$lines   = [ '🛍 سفارش جدید #' . boutique_fa_digits( $o->get_order_number() ), '' ];
	$lines[] = 'مشتری: ' . trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) . ' — ' . boutique_fa_digits( $o->get_billing_phone() );
	foreach ( $o->get_items() as $item ) {
		$p       = $item->get_product();
		$v       = $p ? boutique_variation_label( $p ) : '';
		$lines[] = '• ' . ( $p && $p->get_parent_id() ? wc_get_product( $p->get_parent_id() )->get_name() : $item->get_name() )
			. ( $v ? " ({$v})" : '' ) . ( $item->get_quantity() > 1 ? ' × ' . boutique_fa_digits( $item->get_quantity() ) : '' );
	}
	$lines[] = 'مبلغ: ' . boutique_money( $o->get_total() );
	$lines[] = 'ارسال: ' . ( $o->get_shipping_method() ?: '—' ) . ' · پرداخت: ' . $o->get_payment_method_title();
	if ( ! boutique_order_is_pickup( $o ) ) {
		$addr = trim( wp_strip_all_tags( str_replace( [ '<br/>', '<br>' ], '، ', $o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address() ) ) );
		if ( $addr ) { $lines[] = 'آدرس: ' . $addr; }
	}
	if ( function_exists( 'boutique_order_location' ) && ( $loc = boutique_order_location( $o ) ) ) {
		$lines[] = 'لوکیشن: ' . boutique_map_links( $loc[0], $loc[1] )['neshan'];
	}
	if ( 'bacs' === $o->get_payment_method() ) { $lines[] = 'منتظر رسید کارت به کارت است.'; }
	$lines[] = '';
	$lines[] = 'امروز: ' . admin_url( 'admin.php?page=boutique-today' );
	return implode( "\n", $lines );
}

// New online order (checkout). In-store sales are recorded by the owner herself: no alert.
add_action( 'woocommerce_checkout_order_processed', function ( $order_id ) {
	$o = wc_get_order( $order_id );
	if ( $o && 'instore' !== $o->get_created_via() && ! $o->get_meta( '_boutique_bale_sent' ) ) {
		$o->update_meta_data( '_boutique_bale_sent', 1 );
		$o->save_meta_data();
		boutique_bale_later( boutique_bale_order_text( $o ) );
	}
}, 20 );

// A receipt was uploaded.
add_action( 'boutique_receipt_uploaded', function ( WC_Order $o ) {
	boutique_bale_later( '🧾 رسید کارت به کارت سفارش #' . boutique_fa_digits( $o->get_order_number() ) . ' آپلود شد ('
		. boutique_money( $o->get_total() ) . ').' . "\n" . 'بررسی و تأیید: ' . admin_url( 'admin.php?page=boutique-today' ) );
} );

/* ------------------------------------------------------------ Settings page */
add_action( 'admin_menu', function () {
	add_menu_page( 'اعلان سفارش', 'اعلان سفارش', 'manage_woocommerce', 'boutique-bale', 'boutique_bale_page', 'dashicons-bell', 8 );
}, 20 );

add_action( 'admin_post_boutique_bale', function () {
	check_admin_referer( 'boutique_bale' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی ندارید.' ); }
	$do   = sanitize_key( $_POST['do'] ?? '' );
	$ch   = isset( boutique_notify_channels()[ $_POST['ch'] ?? '' ] ) ? sanitize_key( $_POST['ch'] ) : 'bale';
	$c    = boutique_notify_channels()[ $ch ];
	$msg  = '';
	if ( 'token' === $do ) {
		$new = trim( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );
		if ( $new && false === strpos( $new, '…' ) ) { update_option( $c['opt'] . '_token', $new, false ); }   // "…" = the masked old one
		$me  = boutique_bale_call( 'getMe', [], 10, $ch );
		$msg = is_wp_error( $me ) ? 'err:' . $c['name'] . ': توکن درست نیست یا در دسترس نیست (' . $me->get_error_message() . ')' : 'ok:' . $c['name'] . ': ربات «' . ( $me['first_name'] ?? '' ) . '» شناخته شد. حالا در ' . $c['name'] . ' به این ربات /start بفرستید و «اتصال» را بزنید.';
		if ( ! is_wp_error( $me ) ) { update_option( $c['opt'] . '_bot', '@' . ( $me['username'] ?? '' ), false ); }
	} elseif ( 'connect' === $do ) {
		// Only accounts that share the allowed phone number get alerts. First press: anyone who
		// sent /start is asked (with a button) to share their number. Second press, after they
		// share it: matching numbers are connected, others are refused.
		$allowed = array_filter( array_map( 'boutique_phone_key', (array) get_option( 'boutique_notify_phones', boutique_shop( 'notify_phones' ) ) ) );
		$updates = boutique_bale_call( 'getUpdates', [ 'limit' => 100 ], 10, $ch );
		$chats   = (array) get_option( $c['opt'] . '_chats', [] );
		$asked   = (array) get_option( $c['opt'] . '_asked', [] );
		$added = $refused = $waiting = 0;
		$verified = $seen = [];
		foreach ( is_wp_error( $updates ) ? [] : (array) $updates as $u ) {
			$m    = $u['message'] ?? [];
			$chat = $m['chat'] ?? null;
			if ( ! $chat || 'private' !== ( $chat['type'] ?? 'private' ) ) { continue; }
			$id          = (string) $chat['id'];
			$seen[ $id ] = trim( ( $chat['first_name'] ?? '' ) . ' ' . ( $chat['last_name'] ?? '' ) ) ?: ( $chat['username'] ?? $id );
			$contact     = $m['contact'] ?? null;
			// Own contact only (a forwarded contact of someone else doesn't count).
			if ( $contact && ( ! isset( $contact['user_id'] ) || (string) $contact['user_id'] === (string) ( $m['from']['id'] ?? '' ) ) ) {
				$verified[ $id ] = in_array( boutique_phone_key( $contact['phone_number'] ?? '' ), $allowed, true );
			}
		}
		foreach ( $seen as $id => $name ) {
			if ( isset( $chats[ $id ] ) ) { continue; }
			if ( isset( $verified[ $id ] ) ) {
				if ( $verified[ $id ] ) {
					$chats[ $id ] = $name; $added++;
					boutique_bale_call( 'sendMessage', [ 'chat_id' => $id, 'text' => '✅ وصل شدید. از این به بعد سفارش‌های ' . get_bloginfo( 'name' ) . ' اینجا خبر داده می‌شوند.', 'reply_markup' => wp_json_encode( [ 'remove_keyboard' => true ] ) ], 10, $ch );
				} else {
					$refused++;
					boutique_bale_call( 'sendMessage', [ 'chat_id' => $id, 'text' => 'این شماره اجازه دریافت اعلان‌ها را ندارد.', 'reply_markup' => wp_json_encode( [ 'remove_keyboard' => true ] ) ], 10, $ch );
				}
			} elseif ( empty( $asked[ $id ] ) ) {
				$asked[ $id ] = time(); $waiting++;
				boutique_bale_call( 'sendMessage', [ 'chat_id' => $id, 'text' => 'برای دریافت اعلان سفارش‌ها، دکمه پایین را بزنید تا شماره موبایلتان تأیید شود.', 'reply_markup' => wp_json_encode( [ 'keyboard' => [ [ [ 'text' => '📱 ارسال شماره من', 'request_contact' => true ] ] ], 'resize_keyboard' => true, 'one_time_keyboard' => true ] ) ], 10, $ch );
			} else {
				$waiting++;
			}
		}
		update_option( $c['opt'] . '_chats', $chats, false );
		update_option( $c['opt'] . '_asked', $asked, false );
		if ( is_wp_error( $updates ) ) {
			$msg = 'err:' . $c['name'] . ': ' . $updates->get_error_message();
		} elseif ( $added ) {
			$msg = 'ok:' . $c['name'] . ': وصل شد. از این به بعد سفارش‌ها خبر داده می‌شوند.';
		} elseif ( $waiting ) {
			$msg = 'ok:' . $c['name'] . ': در ربات دکمه «📱 ارسال شماره من» آمده. آن را بزنید، بعد دوباره «اتصال» را بزنید.';
		} elseif ( $refused ) {
			$msg = 'err:' . $c['name'] . ': شماره فرستاده‌شده جزو شماره‌های مجاز نیست.';
		} else {
			$msg = 'err:' . $c['name'] . ': پیامی پیدا نشد. اول به ربات /start بفرستید، بعد دوباره «اتصال» را بزنید.';
		}
	} elseif ( 'test' === $do ) {
		$r   = boutique_bale_send( '✅ پیام آزمایشی از سایت ' . get_bloginfo( 'name' ) . ': اعلان سفارش‌ها در ' . $c['name'] . ' درست کار می‌کند.', $ch );
		$msg = is_wp_error( $r ) ? 'err:' . $r->get_error_message() : 'ok:' . $c['name'] . ': پیام آزمایشی فرستاده شد.';
	} elseif ( 'phones' === $do ) {
		$list = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,،]+/u', boutique_latin_digits( wp_unslash( $_POST['phones'] ?? '' ) ) ) ) ) );
		update_option( 'boutique_notify_phones', $list ?: boutique_shop( 'notify_phones' ), false );
		$msg = 'ok:شماره‌های مجاز ذخیره شد.';
	} elseif ( 'remove' === $do ) {
		$chats = (array) get_option( $c['opt'] . '_chats', [] );
		unset( $chats[ sanitize_text_field( wp_unslash( $_POST['chat'] ?? '' ) ) ] );
		update_option( $c['opt'] . '_chats', $chats, false );
		$msg = 'ok:حذف شد.';
	}
	wp_safe_redirect( add_query_arg( [ 'page' => 'boutique-bale', 'msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
	exit;
} );

function boutique_bale_form( $do, $label, $class = 'b-btn', $extra = '', $ch = 'bale' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b-inline">';
	wp_nonce_field( 'boutique_bale' );
	echo '<input type="hidden" name="action" value="boutique_bale"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="ch" value="' . esc_attr( $ch ) . '">' . $extra;
	echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

function boutique_bale_page() {
	$msg = sanitize_text_field( wp_unslash( rawurldecode( $_GET['msg'] ?? '' ) ) );
	?>
	<div class="wrap b-wrap">
		<header class="b-head"><div>
			<h1 class="b-title">اعلان سفارش</h1>
			<p class="b-muted">برای هر سفارش آنلاین و هر رسید آپلودشده، در بله و تلگرام پیام می‌گیرید. هر دو رایگان است. بله در قطعی اینترنت بین‌الملل هم کار می‌کند.</p>
		</div></header>
		<?php if ( $msg ) : $ok = 0 === strpos( $msg, 'ok:' ); ?>
			<div class="<?php echo $ok ? 'b-done' : 'notice notice-error'; ?>" role="status"><p><strong><?php echo esc_html( substr( $msg, strpos( $msg, ':' ) + 1 ) ); ?></strong></p></div>
		<?php endif; ?>
		<section class="b-panel">
			<h2>شماره‌های مجاز</h2>
			<p class="b-hint">فقط حساب‌هایی که شماره موبایلشان یکی از این‌هاست اعلان می‌گیرند. موقع «اتصال»، ربات از هر کس شماره‌اش را می‌خواهد.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b-bale__token">
				<?php wp_nonce_field( 'boutique_bale' ); ?>
				<input type="hidden" name="action" value="boutique_bale"><input type="hidden" name="do" value="phones">
				<label class="b-field"><span class="screen-reader-text">شماره‌های مجاز</span>
					<input type="text" name="phones" dir="ltr" value="<?php echo esc_attr( implode( ', ', (array) get_option( 'boutique_notify_phones', boutique_shop( 'notify_phones' ) ) ) ); ?>"></label>
				<button type="submit" class="b-btn b-btn--ghost">ذخیره</button>
			</form>
		</section>
		<div class="b-cols">
		<?php foreach ( boutique_notify_channels() as $ch => $c ) :
			$token = (string) get_option( $c['opt'] . '_token' );
			$chats = (array) get_option( $c['opt'] . '_chats', [] );
			$bot   = (string) get_option( $c['opt'] . '_bot' ); ?>
			<section class="b-panel b-notify" data-ch="<?php echo esc_attr( $ch ); ?>">
				<h2><?php echo esc_html( $c['name'] ); ?> <?php echo $chats ? '<span class="b-chip">وصل</span>' : ''; ?></h2>
				<ol class="b-notify__steps">
					<li>در <?php echo esc_html( $c['name'] ); ?>، <strong>@BotFather</strong> را باز کنید، <code>/newbot</code> بفرستید، یک اسم و یک نام کاربری که به bot ختم شود بدهید و توکن را اینجا بگذارید:
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b-bale__token">
							<?php wp_nonce_field( 'boutique_bale' ); ?>
							<input type="hidden" name="action" value="boutique_bale"><input type="hidden" name="do" value="token"><input type="hidden" name="ch" value="<?php echo esc_attr( $ch ); ?>">
							<label class="b-field"><span class="screen-reader-text">توکن ربات <?php echo esc_html( $c['name'] ); ?></span>
								<input type="text" name="token" dir="ltr" autocomplete="off" value="<?php echo esc_attr( $token ? substr( $token, 0, 6 ) . '…' . substr( $token, -4 ) : '' ); ?>" placeholder="123456789:AbC…" onfocus="this.value=''"></label>
							<button type="submit" class="b-btn">ذخیره توکن</button>
						</form>
						<?php if ( $bot ) : ?><p>ربات: <bdi dir="ltr"><?php echo esc_html( $bot ); ?></bdi></p><?php endif; ?>
					</li>
					<li>با حسابی که می‌خواهید پیام بگیرد به ربات <code>/start</code> بفرستید و «اتصال» را بزنید. ربات دکمه «📱 ارسال شماره من» را نشان می‌دهد؛ آن را بزنید و دوباره «اتصال» را بزنید: <?php boutique_bale_form( 'connect', 'اتصال', 'b-btn', '', $ch ); ?></li>
					<li><?php boutique_bale_form( 'test', 'ارسال پیام آزمایشی', 'b-btn b-btn--ghost', '', $ch ); ?></li>
				</ol>
				<?php if ( $chats ) : ?>
					<ul class="b-bale__chats">
						<?php foreach ( $chats as $id => $name ) : ?>
							<li><span><?php echo esc_html( $name ); ?></span> <?php boutique_bale_form( 'remove', 'حذف', 'b-btn b-btn--quiet', '<input type="hidden" name="chat" value="' . esc_attr( $id ) . '">', $ch ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?><p class="b-empty">هنوز به حسابی وصل نیست.</p><?php endif; ?>
			</section>
		<?php endforeach; ?>
		</div>
		<?php do_action( 'boutique_notify_page_end' ); ?>
	</div>
	<?php
}
