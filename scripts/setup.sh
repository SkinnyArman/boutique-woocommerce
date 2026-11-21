#!/bin/sh
# One-shot local install. Run from the project root:
#   docker compose up -d
#   docker compose run --rm --entrypoint sh wpcli /scripts/setup.sh
set -e
export WP_CLI_CACHE_DIR=/tmp/wp-cli-cache

# Downloads from wordpress.org can fail on a shaky connection: try each up to 4 times.
retry() {
  n=1
  until "$@"; do
    [ "$n" -ge 4 ] && { echo "FAILED after $n tries: $*"; return 1; }
    echo "  ...retrying ($n) in 10s: $*"; n=$((n + 1)); sleep 10
  done
}

SITE_URL="${SITE_URL:-http://localhost:8080}"
SITE_TITLE="${SITE_TITLE:-Shop}"   # replaced by the name in config/shop.php once the plugin is on
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin_local_pw}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"

# WordPress refuses downloads from hosts that resolve to reserved IPs ("A valid URL was not
# provided"). VPNs/proxies in fake-IP mode (198.18.x.x) trip that, so for the wp commands in
# THIS script only, treat wordpress.org hosts as external. The site itself is not changed.
cat > /tmp/wpcli-allow-host.php <<'PHP'
<?php
WP_CLI::add_wp_hook( 'http_request_host_is_external', function ( $external, $host ) {
	return $external || (bool) preg_match( '/(^|\.)(wordpress\.org|w\.org)$/', $host );
}, 10, 2 );
PHP
printf 'require:\n  - /tmp/wpcli-allow-host.php\n' > /tmp/wp-cli-setup.yml
export WP_CLI_CONFIG_PATH=/tmp/wp-cli-setup.yml

echo "==> Waiting for database"
until wp db check >/dev/null 2>&1; do sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  echo "==> Installing WordPress"
  wp core install --url="$SITE_URL" --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" --skip-email
fi

echo "==> Updating WordPress core to latest"
retry wp core update
wp core update-db

echo "==> Persian language"
retry wp language core install fa_IR --activate
wp option update timezone_string "Asia/Tehran"
wp option update start_of_week 6

echo "==> Plugins"
retry wp plugin install woocommerce persian-woocommerce --activate
wp plugin delete akismet hello 2>/dev/null || true
wp language plugin install --all fa_IR 2>/dev/null || true

# Persian WooCommerce sends the first admin visitor to its own "about" page. Mark its
# first-run as done so the shop manager's first login lands on «امروز».
wp eval 'if ( defined( "PW_VERSION" ) && ! get_option( "persianwoocommerce_version" ) ) { update_option( "persianwoocommerce_version", PW_VERSION, false ); }'

echo "==> Shop details (config/shop.php)"
wp plugin activate boutique-admin
wp eval 'update_option( "blogname", boutique_shop( "name" ) ); update_option( "blogdescription", boutique_shop( "description" ) ); update_option( "woocommerce_default_country", "IR:" . boutique_shop( "state_code" ) ); echo boutique_shop_is_example() ? "  (using config/shop.example.php: copy it to config/shop.php and fill it in)\n" : "";'

echo "==> Theme"
retry wp theme install blocksy
wp theme activate blocksy-child
wp theme delete twentytwentythree twentytwentyfour 2>/dev/null || true

echo "==> WooCommerce settings"
wp option update woocommerce_allowed_countries "specific"
wp option update woocommerce_specific_allowed_countries '["IR"]' --format=json
wp option update woocommerce_ship_to_countries ""
wp option update woocommerce_currency "IRT"
wp option update woocommerce_currency_pos "right_space"
wp option update woocommerce_price_thousand_sep ","
wp option update woocommerce_price_decimal_sep "."
wp option update woocommerce_price_num_decimals 0
wp option update woocommerce_calc_taxes "no"
wp option update woocommerce_weight_unit "kg"
wp option update woocommerce_dimension_unit "cm"
wp option update woocommerce_manage_stock "yes"
wp option update woocommerce_notify_low_stock_amount 1
wp option update woocommerce_notify_no_stock_amount 0
wp option update woocommerce_hide_out_of_stock_items "no"
wp option update woocommerce_enable_guest_checkout "yes"
wp option update woocommerce_enable_signup_and_login_from_checkout "yes"
wp option update woocommerce_enable_myaccount_registration "yes"
wp option update woocommerce_enable_reviews "yes"
wp option update woocommerce_review_rating_verification_label "yes"
wp option update woocommerce_coming_soon "no"
wp option update woocommerce_allow_tracking "no"
wp option update woocommerce_show_marketplace_suggestions "no"
wp option update woocommerce_task_list_hidden "yes" || true
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json || true
wp option update woocommerce_analytics_enabled "yes"

echo "==> Permalinks"
wp rewrite structure '/%postname%/'
# WP-CLI can't detect mod_rewrite from the CLI, so write the Apache rules ourselves.
cat > /var/www/html/.htaccess <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
wp option update woocommerce_permalinks '{"product_base":"/product","category_base":"product-category","tag_base":"product-tag","attribute_base":"","use_verbose_page_rules":false}' --format=json

echo "==> Store pages"
wp wc tool run install_pages --user="$ADMIN_USER" >/dev/null

echo "==> Theme colors (warm & feminine)"
wp eval '
set_theme_mod( "colorPalette", [
  "color1" => [ "color" => "#c2727f" ],  // primary: dusty rose
  "color2" => [ "color" => "#a85562" ],  // hover
  "color3" => [ "color" => "#5a4a4c" ],  // text
  "color4" => [ "color" => "#3b2a2d" ],  // headings
  "color5" => [ "color" => "#ebd9d6" ],  // borders
  "color6" => [ "color" => "#f8efec" ],  // soft bg
  "color7" => [ "color" => "#fffaf7" ],  // site bg
  "color8" => [ "color" => "#ffffff" ],
  "current_palette" => "palette-1",
] );
'

echo "==> Seeding store"
wp eval-file /scripts/store.php --user="$ADMIN_USER"
wp eval-file /scripts/delivery.php
if [ -f /scripts/import/products.json ]; then
  echo "==> Real products from the shop's Telegram channel"
  wp eval-file /scripts/import/import-products.php replace --user="$ADMIN_USER"
fi
wp eval-file /scripts/pages.php --user="$ADMIN_USER"
wp eval-file /scripts/theme.php

echo "==> Shop admin plugin + manager account"
wp plugin activate boutique-admin
MANAGER_USER="${MANAGER_USER:-manager}"
MANAGER_PASS="${MANAGER_PASS:-manager_local_pw}"
if ! wp user get "$MANAGER_USER" >/dev/null 2>&1; then
  wp user create "$MANAGER_USER" manager@example.com --role=shop_manager \
    --user_pass="$MANAGER_PASS" --display_name="مدیر فروشگاه"
fi
wp language theme install blocksy fa_IR || true

echo "==> Site icon"
if [ -z "$(wp option get site_icon 2>/dev/null)" ] || [ "$(wp option get site_icon)" = "0" ]; then
  ICON_ID=$(wp media import /var/www/html/wp-content/themes/blocksy-child/brand/site-icon.png --title="آیکون سایت" --porcelain)
  wp option update site_icon "$ICON_ID"
fi

wp rewrite flush
wp cache flush
wp transient delete --all   # Blocksy caches its generated CSS (palette) in a transient
echo
echo "Done. Site:  $SITE_URL"
echo "      Admin: $SITE_URL/wp-admin  ($ADMIN_USER / $ADMIN_PASS)"
echo "      Shop manager: ${MANAGER_USER:-manager} / ${MANAGER_PASS:-manager_local_pw}"
