<?php
/**
 * «سؤالات متداول» ([boutique_faq] on the /faq/ page). The same list feeds the FAQPage markup
 * for search engines, so the page and the markup can't drift apart. Edit the answers here.
 */
defined( 'ABSPATH' ) || exit;

function boutique_faq_items() {
	$track   = esc_url( home_url( '/track/' ) );
	$tg      = esc_url( 'https://t.me/' . BOUTIQUE_ORDER_TG );
	return [
		[ 'لباس‌های اسپرت واقعاً اورجینال هستند؟',
			esc_html( boutique_shop( 'faq_brands' ) ) ],
		[ 'چطور سایز مناسبم را انتخاب کنم؟',
			'در صفحه هر لباس بخش «راهنمای سایز» را ببینید. کنار بعضی سایزها نوشته‌ایم مناسب چه سایزی است. اگر مطمئن نیستید، قبل از خرید در <a href="' . $tg . '">تلگرام</a> بپرسید.' ],
		[ 'چرا قیمت بعضی لباس‌های شب نوشته نشده؟',
			'قیمت لباس‌های شب و پیراهن‌های ترک را با «استعلام قیمت» اعلام می‌کنیم. در صفحه همان لباس دکمه «پیام در تلگرام» یا «تماس» را بزنید تا قیمت و امکان پرو را بگوییم.' ],
		[ 'به کدام شهرها ارسال دارید و چقدر طول می‌کشد؟',
			'به سراسر ایران با پست پیشتاز یا تیپاکس، و داخل شهر ' . esc_html( BOUTIQUE_COURIER_CITY ) . ' با اسنپ پیک (تحویل فوری، پس‌کرایه). سفارش‌های اسنپ پیک معمولاً همان روزِ تأیید پرداخت، ظرف چند ساعت می‌رسند؛ پست و تیپاکس حداکثر ۲ روز کاری بعد از تأیید ارسال می‌شوند و ۲ تا ۵ روز کاری در راه‌اند.' ],
		[ 'هزینه ارسال چقدر است؟',
			'هزینه پست و تیپاکس را در صفحه تسویه حساب، بعد از انتخاب استان و شهر، می‌بینید. اسنپ پیک پس‌کرایه است: کرایه را هنگام تحویل خودتان به پیک می‌دهید.'
			. ( function_exists( 'boutique_free_ship_min' ) && boutique_free_ship_min() ? ' برای خرید بالای ' . boutique_money_short( boutique_free_ship_min() ) . '، ارسال با هر روشی (حتی اسنپ پیک) رایگان است.' : '' ) ],
		[ 'تحویل حضوری چطور است؟',
			'در تسویه حساب «تحویل حضوری از فروشگاه» را انتخاب کنید. لطفاً قبل از آمدن با <a href="tel:' . esc_attr( BOUTIQUE_PHONE ) . '">' . boutique_fa( BOUTIQUE_PHONE ) . '</a> تماس بگیرید تا سفارشتان آماده باشد؛ وضعیت را در <a href="' . $track . '">پیگیری سفارش</a> هم می‌بینید. آدرس: ' . esc_html( BOUTIQUE_ADDRESS ) . '، ' . esc_html( BOUTIQUE_HOURS ) . '.' ],
		[ 'می‌توانم قبل از خرید پرو کنم؟',
			'بله، در ساعات کاری به فروشگاه بیایید. برای لباس شب و عروس بهتر است قبلش در تلگرام یا تلفنی هماهنگ کنید تا سایز شما آماده باشد.' ],
		[ 'چطور پرداخت کنم؟',
			'کارت به کارت: بعد از ثبت سفارش شماره کارت را می‌بینید و رسید را همان‌جا آپلود می‌کنید یا در تلگرام / واتساپ / بله می‌فرستید. سفارش بعد از تأیید واریز ارسال می‌شود؛ اگر رسید تا ۲۴ ساعت نرسد، سفارش خودکار لغو می‌شود. پرداخت آنلاین با درگاه بانکی به‌زودی اضافه می‌شود.' ],
		[ 'سفارشم کجاست؟',
			'در <a href="' . $track . '">پیگیری سفارش</a> شماره سفارش و موبایلتان را وارد کنید تا وضعیت و کد رهگیری را ببینید.' ],
		[ 'سایز مورد نظرم تمام شده؛ دوباره می‌آید؟',
			'در <a href="' . $tg . '">تلگرام</a> پیام بدهید؛ اگر دوباره برسد یا مدل مشابه داشته باشیم خبرتان می‌کنیم.' ],
	];
}

add_shortcode( 'boutique_faq', function () {
	$out = '<div class="b-faq">';
	foreach ( boutique_faq_items() as [ $q, $a ] ) {
		$out .= '<details class="b-faq__item"><summary>' . esc_html( $q ) . '</summary><div class="b-faq__a">' . wp_kses_post( wpautop( $a ) ) . '</div></details>';
	}
	// Opening/closing slides the answer (height + fade, ease-out); instant for reduced motion.
	$out .= "<script>(function(){var reduce=matchMedia('(prefers-reduced-motion: reduce)').matches;document.querySelectorAll('.b-faq__item').forEach(function(d){var s=d.querySelector('summary'),a=d.querySelector('.b-faq__a'),anim;s.addEventListener('click',function(e){if(reduce||!a.animate)return;e.preventDefault();if(anim)anim.cancel();if(!d.open){d.open=true;var h=a.scrollHeight;anim=a.animate([{height:'0px',opacity:0},{height:h+'px',opacity:1}],{duration:320,easing:'cubic-bezier(0.23,1,0.32,1)'});}else{var h2=a.offsetHeight;anim=a.animate([{height:h2+'px',opacity:1},{height:'0px',opacity:0}],{duration:220,easing:'cubic-bezier(0.23,1,0.32,1)'});anim.onfinish=function(){d.open=false;anim=null;};}});});})();</script>";
	return $out . '</div>';
} );

add_action( 'wp_head', function () {
	if ( ! is_page( 'faq' ) ) { return; }
	boutique_jsonld( [
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map( fn( $i ) => [
			'@type'          => 'Question',
			'name'           => $i[0],
			'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $i[1] ) ],
		], boutique_faq_items() ),
	] );
}, 4 );
