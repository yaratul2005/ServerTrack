You changed the plugin display name to "Ratuls- Ads Conversion Tracker".

Continuing with the plugin review for "ratul727". Let’s dive in!

Your plugin is not yet ready to be approved, you are receiving this email because the volunteers have manually checked it and have found some issues in the code / functionality of your plugin.

Please check this email thoroughly, address any issues listed, test your changes, and upload a corrected version of your code if all is well.

List of issues found


## Not permitted files

A plugin typically consists of files related to the plugin functionality (php, js, css, txt, md) and maybe some multimedia files (png, svg, jpg) and / or data files (json, xml).

We have detected files that are not among of the files normally found in a plugin, are they necessary? If not, then those won't be allowed.

Optionally, you can use the wp dist-archive command from WP-CLI in conjunction with a .distignore file. This prevents unwanted files from being included in the distribution archive.

Example(s) from your plugin:
ServerTrack-6Typhon/false



## No publicly documented resource for your generated/compressed content

In reviewing your plugin, we cannot find a non-compiled version of your javascript and/or css related source code.

In order to comply with our guidelines of human-readable code, we require you to include the source code and / or a link to the source code, this is true for your own code and for developer libraries you’ve included in your plugin. If you include a link, this may be in your source code, however we require you to also have it in your readme.

https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#4-code-must-be-mostly-human-readable

We strongly feel that one of the strengths of open source is the ability to review, observe, and adapt code. By maintaining a public directory of freely available code, we encourage and welcome future developers to engage with WordPress and push it forward.

That said, with the advent of larger and larger plugins using more complex libraries, people are making good use of build tools (such as composer or npm) to generate their distributed production code. In order to balance the need to keep plugin sizes smaller while still encouraging open source development, we require plugins to make the source code to any compressed files available to the public in an easy to find location, by documenting it in the readme.

For example, if you’ve made a Gutenberg plugin and used npm and webpack to compress and minify it, you must either include the source code within the published plugin or provide access to a public maintained source that can be reviewed, studied, and yes, forked.

🔗 If you choose to add a link to a repository, please make sure that the repository exists and is publicly accessible. We will check those links in the next review.

We strongly recommend you include directions on the use of any build tools to encourage future developers.

From your plugin:
frontend/assets/servertrack-pixel.js:92  ...w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];ttq.setAndDefer=fun... 



## Use wp_enqueue commands

Your plugin is not correctly including JS and/or CSS. You should be using the built in functions for this:

When including JavaScript code you can use:
wp_register_script() and wp_enqueue_script() to add JavaScript code from a file.
wp_add_inline_script() to add inline JavaScript code to previous declared scripts.

When including CSS you can use:
wp_register_style() and wp_enqueue_style() to add CSS from a file.
wp_add_inline_style() to add inline CSS to previously declared CSS.

Note that as of WordPress 6.3, you can easily pass attributes like defer or async: https://make.wordpress.org/core/2023/07/14/registering-scripts-with-async-and-defer-attributes-in-wordpress-6-3/

Also, as of WordPress 5.7, you can pass other attributes by using this functions and filters: https://make.wordpress.org/core/2021/02/23/introducing-script-attributes-related-functions-in-wordpress-5-7/

If you're trying to enqueue on the admin pages you'll want to use the admin enqueues.

https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
https://developer.wordpress.org/reference/hooks/admin_print_scripts/
https://developer.wordpress.org/reference/hooks/admin_print_styles/

Example(s) from your plugin:
admin/class-servertrack-admin.php:298 echo '<style>.wc-action-button-st-manual-purchase::after { font-family: dashicons; content: "\f502"; color: #0ea5a0; }</style>';
admin/views/settings-google.php:233 <script>
includes/class-servertrack-pixel-dedup.php:182 echo "<script>\n";
includes/class-servertrack-pixel-dedup.php:203 echo "<script>\n";
admin/class-servertrack-dashboard.php:381 <script>



## Calling files remotely

Offloading images, js, css, and other scripts to your servers or any remote service (like Google, MaxCDN, jQuery.com etc) is disallowed. When you call remote data you introduce an unnecessary dependency on another site. If the file you're calling isn't a part of WordPress Core, then you should include it -locally- in your plugin, not remotely. If the file IS included in WordPress core, please call that instead.

An exception to this rule is if your plugin is performing a service. We will permit this on a case by case basis. Since this can be confusing we have some examples of what are not permitted:
Offloading jquery CSS files to Google - You should include the CSS in your plugin.
Inserting an iframe with a help doc - A link, or including the docs in your plugin is preferred.
Calling images from your own domain - They should be included in your plugin.
Here are some examples of what we would permit:
Calling font families from Google or their approved CDN (if GPL compatible)
API calls back to your server to process possible spam comments (like Akismet)
Offloading comments to your own servers (like Disqus)
oEmbed calls to a service provider (like Twitter or YouTube)
Please remove external dependencies from your plugin and, if possible, include all files within the plugin (that is not called remotely). If instead you feel you are providing a service, please re-write your readme.txt in a manner that explains the service, the servers being called, and if any account is needed to connect.

Example(s) from your plugin:
admin/class-servertrack-dashboard.php:113 wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true);



## Undocumented use of a 3rd Party / external service

Plugins are permitted to require the use of third party/external services as long as they are clearly documented.

When your plugin reach out to external services, you must disclose it. This is true even if you are the one providing that service.

You are required to document it in a clear and plain language, so users are aware of: what data is sent, why, where and under which conditions.

To do this, you must update your readme file to clearly explain that your plugin relies on third party/external services, and include at least the following information for each third party/external service that this plugin uses:
What the service is and what it is used for.
What data is sent and when.
Provide links to the service's terms of service and privacy policy.
Remember, this is for your own legal protection. Use of services must be upfront and well documented. This allows users to ensure that any legal issues with data transmissions are covered.

Example:
== External services ==

This plugin connects to an API to obtain weather information, it's needed to show the weather information and forecasts in the included widget.

It sends the user's location every time the widget is loaded (If the location isn't available and/or the user hasn't given their consent, it displays a configurable default location).
This service is provided by "PRT Weather INC": terms of use, privacy policy.

🔗 Please verify that the terms and privacy links exist and they have the proper content. We will check those links in the next review.

Example(s) from your plugin:
# Domain(s) not mentioned in the readme file.
platforms/class-servertrack-tiktok.php:149 wp_remote_post(self::API_ENDPOINT, ['method' => 'POST', 'timeout' => 15, 'headers' => ['Content-Type' => 'application/json', 'Access-Token' => $access_token], 'body' => $json]);
# ↳ Found: 'https://business-api.tiktok.com/open_api/v1.3/event/track/'
# ✨ Sends data to TikTok Events API, but the readme lacks the required TikTok service disclosure and Terms/Privacy link.
includes/class-servertrack-offline-conversion.php:248 wp_remote_post($url, ['body' => wp_json_encode(['data' => $events, 'access_token' => $access_token]), 'headers' => ['Content-Type' => 'application/json'], 'timeout' => 15]);
# ↳ Found: sprintf('https://graph.facebook.com/%s/%s/events', self::GRAPH_API_VERSION, $dataset_id)
# ✨ Calls Meta/Facebook Graph API for offline conversions, but the plugin readme does not document the service purpose together with a Terms/Privacy link  
platforms/class-servertrack-google.php:74 wp_remote_post($endpoint, ['method' => 'POST', 'timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json', 'developer-token' => get_option('servertrack_google_developer_token', ''), 'login-customer-id' => $customer_id], 'body' => $json]);
# ↳ Found: 'https://googleads.googleapis.com/' . 'v16' . '/customers/' . get_option('servertrack_google_customer_id', '') . ':uploadClickConversions'
# ✨ Sends conversion data to Google Ads API, but the readme lacks the required Google service disclosure and Terms/Privacy link.
includes/class-servertrack-license.php:13 const STORE_URL = 'https://great10.xyz';
# ✨ Uses an external licensing service at great10.xyz, but the readme does not disclose it or provide Terms/Privacy links.
includes/class-servertrack-health.php:73 wp_remote_get($url, ['headers' => ['Access-Token' => $token], 'timeout' => 10]);
# ↳ Found: 'https://business-api.tiktok.com/open_api/v1.3/pixel/list/'
# ✨ Performs a TikTok API health check, but the readme does not properly document the TikTok service with Terms/Privacy link.
platforms/class-servertrack-google.php:135 wp_remote_post(self::TOKEN_ENDPOINT, ['method' => 'POST', 'timeout' => 15, 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => ['client_id' => $client_id, 'client_secret' => $client_secret, 'refresh_token' => $refresh_token, 'grant_type' => 'refresh_token']]);
# ↳ Found: 'https://oauth2.googleapis.com/token'
# ✨ Uses Google OAuth token refresh endpoint, but the readme does not properly document the Google service with Terms/Privacy link.
... out of a total of 9 incidences.


## Check permission_callback in REST API Route

When using register_rest_route() or wp_register_ability() to define custom REST API endpoints, it is crucial to include a proper permission_callback .

🔒 This callback function ensures that only authorized users can access or modify data through your endpoint.

Code example, checking that the user can change options:
register_rest_route( 'servertrack/v1', '/my-endpoint', array(
    'methods' => 'GET',
    'callback' => 'servertrack_callback_function',
    'permission_callback' => function() {
        return current_user_can( 'manage_options' );
    }
) );

Please check the register_rest_route() documentation and the current_user_can() documentation.

✅ When a permission_callback is NOT Required:

There are valid use cases for public endpoints, such as publicly available data (e.g., posts, public metadata) or endpoints designed for unauthenticated access (e.g., fetching public stats or information).

In these cases, you should use __return_true as the permission_callback to indicate that the endpoint is intentionally public.

🔒 When a permission_callback IS Required:

For endpoints that involve sensitive data or actions (e.g., getting not public data, creating, updating, or deleting content).

In these cases, you should always implement proper permission checks.

Possible cases found on this plugin's code:
frontend/class-servertrack-frontend.php:150 register_rest_route('servertrack/v1', '/custom-event', ['methods' => 'POST', 'callback' => [self::class, 'rest_custom_event'], 'permission_callback' => '__return_true', 'args' => ['event_name' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'], 'event_id' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'], 'params' => ['required' => false, 'type' => 'object'], 'is_custom' => ['required' => false, 'type' => 'boolean'], 'url' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'esc_url_raw'], 'fbc' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'], 'fbp' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'], 'ttclid' => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field']]]);
# ✨ Public custom-event POST endpoint performs side effects but uses __return_true without any real auth or secret verification; rate limiting and allowlists are not sufficient permission checks.
includes/class-servertrack-proxy.php:22 register_rest_route('servertrack/v1', '/pixel/(?P<platform>[a-zA-Z0-9-]+)', [
    'methods' => 'POST',
    'callback' => [self::class, 'handle_pixel_payload'],
    'permission_callback' => '__return_true',
    // Public endpoint
    'args' => ['platform' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field']],
]);
# ✨ Public pixel proxy POST endpoint sends server-side events but is exposed with __return_true and lacks any real authorization or shared-secret verification.
includes/class-servertrack-pixel-dedup.php:226 register_rest_route('servertrack/v1', '/event-id', [
    'methods' => WP_REST_Server::READABLE,
    'callback' => [__CLASS__, 'rest_get_event_id'],
    /**
     * M-2 FIX (v2.2):
     *   Previously __return_true — any unauthenticated visitor could enumerate
     *   order event IDs by passing ?order_id=N, leaking order existence and
     *   event correlation data.
     *   Fix: shop_manager/administrator may call without a nonce (server-side
     *   use). Browser callers must supply a valid 'servertrack_event_id' nonce
     *   via the X-WP-Nonce header or _wpnonce query param.
     */
    'permission_callback' => [__CLASS__, 'rest_permission_check'],
    'args' => ['event' => ['required' => false, 'default' => 'generic', 'sanitize_callback' => 'sanitize_key'], 'order_id' => ['required' => false, 'default' => 0, 'sanitize_callback' => 'absint']],
]);
# ↳ Detected: rest_permission_check
# ✨ The permission callback allows access to order event IDs with only a nonce and no order-ownership/capability check, which is insufficient for sensitive non-public data.



## Data Must be Sanitized, Escaped, and Validated

When you include POST/GET/REQUEST/FILE calls in your plugin, it's important to sanitize, validate, and escape them. The goal here is to prevent a user from accidentally sending trash data through the system, as well as protecting them from potential security issues.

SANITIZE: Data that is input (either by a user or automatically) must be sanitized as soon as possible. This lessens the possibility of XSS vulnerabilities and MITM attacks where posted data is subverted.

VALIDATE: All data should be validated, no matter what. Even when you sanitize, remember that you don’t want someone putting in ‘dog’ when the only valid values are numbers.

ESCAPE: Data that is output must be escaped properly when it is echo'd, so it can't hijack admin screens. There are many esc_*() functions you can use to make sure you don't show people the wrong data.

To help you with this, WordPress comes with a number of sanitization and escaping functions. You can read about those here:

https://developer.wordpress.org/apis/security/sanitizing/
https://developer.wordpress.org/apis/security/escaping/

Remember: You must use the most appropriate functions for the context. If you’re sanitizing email, use sanitize_email() , if you’re outputting HTML, use wp_kses_post() , and so on.

An easy mantra here is this:

Sanitize early
Escape Late
Always Validate

Clean everything, check everything, escape everything, and never trust the users to always have input sane data. After all, users come from all walks of life.

Example(s) from your plugin:
includes/class-servertrack-cookiehelper.php:110 $host = $_SERVER['HTTP_HOST'] ?? '';
# ↳ Line 121: return '.' . $host;
# ✨ HTTP_HOST fallback is not sanitized before being used as the cookie domain in setcookie.
includes/class-servertrack-dispatcher.php:69 $event_data = isset( $_POST['event'] ) ? json_decode( wp_unslash( $_POST['event'] ), true ) : [];
# ↳ Line 77: $event = new ServerTrack_Event( $event_data['event_name'] ?? '', $event_data['event_id'] ?? '' );
# ↳ Line 77: $event = new ServerTrack_Event( $event_data['event_name'] ?? '', $event_data['event_id'] ?? '' );
# ↳ Line 78: $event->set_user_data( $event_data['user_data'] ?? [] )
# ↳ Line 79: ->set_custom_data( $event_data['custom_data'] ?? [] );
# ↳ Line 82: $event->set_source_url( $event_data['event_source_url'] );
# ↳ Line 128: ServerTrack_Retry::maybe_queue( $platform, $result, $event_data );
tests/CookieHelperTest.php:101 $this->assertStringContainsString('.test_gclid_id', $_COOKIE['_gcl_aw']);
tests/CookieHelperTest.php:116 $this->assertEquals('fb.1.12345.existing_fbp', $_COOKIE['_fbp']);
tests/CookieHelperTest.php:66 $this->assertStringContainsString('fb.1.', $_COOKIE['_fbc']);
tests/CookieHelperTest.php:65 $this->assertArrayHasKey('_fbc', $_COOKIE);



Note: $_SERVER, $_COOKIE and $_SESSION inputs must be sanitized as well.

Although this might be counterintuitive, some or all of its included data can be manipulated by the sender of the request. So it needs to be sanitized just like any other input.

Example(s) from your plugin:
tests/CookieHelperTest.php:100 $this->assertStringContainsString('GCL.', $_COOKIE['_gcl_aw']);
tests/CookieHelperTest.php:77 $this->assertStringContainsString('fb.1.', $_COOKIE['_fbp']);
frontend/class-servertrack-frontend.php:515 . '|' . wp_hash( $_SERVER['HTTP_USER_AGENT'] ?? '', 'auth' )
tests/CookieHelperTest.php:78 $this->assertStringContainsString('.1234567890', $_COOKIE['_fbp']); // uses mocked wp_rand
... out of a total of 18 incidences.


Note: There are simple ways to sanitize arrays, in case you need to do so, you can do the following:
An array of post IDs: array_unique(array_map('absint', $_POST['post_ids']))
An array of emails: array_map('sanitize_email', $_POST['user_emails'])
A multidimensional array, being all the elements texts: map_deep( $_POST['arrays_of_texts'], 'sanitize_text_field' )
Sometimes you'll have an array that contains different types of data inside, which would require different types of sanitization.
$sanitized_orders = $_POST['orders']; // Sanitized below.
array_walk_recursive( $sanitized_orders, 'ratuadco_sanitize_orders' );
function ratuadco_sanitize_orders( &$item , $key ){
  switch ($key){
    case 'locator':
      $item = sanitize_key($item);
      break;
    case 'name':
      $item = sanitize_text_field($item);
      break;
    case 'price':
    case 'priceDiscounted':
      $item = (float)$item;
      break;
    default:
      $item = NULL;
  }
}

We have heuristically detected these cases of your plugin that might need array sanitization (might be false positives, please check them out):
includes/class-servertrack-custom-events.php:236 $ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
sources/class-servertrack-woo-abandonment.php:377 $tokens = array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
frontend/class-servertrack-frontend.php:294 $tokens = array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );



Note: While the json_decode() function in PHP is useful for decoding JSON strings, it does not sanitize the input. Sanitization refers to the process of cleaning or filtering the input data to ensure that it is safe and secure to use.

The json_decode() function simply transforms a JSON string into a PHP array or object. Any potentially malicious data or scripts may persist after json_decode().
Example(s) from your plugin:
includes/class-servertrack-dispatcher.php:69 $event_data = isset( $_POST['event'] ) ? json_decode( wp_unslash( $_POST['event'] ), true ) : [];
 -----> json_decode(wp_unslash($_POST['event']), true)
includes/class-servertrack-dispatcher.php:70 $platforms  = isset( $_POST['platforms'] ) ? json_decode( wp_unslash( $_POST['platforms'] ), true ) : [];
 -----> json_decode(wp_unslash($_POST['platforms']), true)


✔️ You can check this using Plugin Check.


## Variables and options must be escaped when echo'd

Much related to sanitizing everything, all variables that are echoed need to be escaped when they're echoed, so it can't hijack users or (worse) admin screens. There are many esc_*() functions you can use to make sure you don't show people the wrong data, as well as some that will allow you to echo HTML safely.

At this time, we ask you escape all $-variables, options, and any sort of generated data when it is being echoed. That means you should not be escaping when you build a variable, but when you output it at the end. We call this 'escaping late.'

Besides protecting yourself from a possible XSS vulnerability, escaping late makes sure that you're keeping the future you safe. While today your code may be only outputted hardcoded content, that may not be true in the future. By taking the time to properly escape when you echo, you prevent a mistake in the future from becoming a critical security issue.

This remains true of options you've saved to the database. Even if you've properly sanitized when you saved, the tools for sanitizing and escaping aren't interchangeable. Sanitizing makes sure it's safe for processing and storing in the database. Escaping makes it safe to output.

Also keep in mind that sometimes a function is echoing when it should really be returning content instead. This is a common mistake when it comes to returning JSON encoded content. Very rarely is that actually something you should be echoing at all. Echoing is because it needs to be on the screen, read by a human. Returning (which is what you would do with an API) can be json encoded, though remember to sanitize when you save to that json object!

There are a number of options to secure all types of content (html, email, etc). Yes, even HTML needs to be properly escaped.

https://developer.wordpress.org/apis/security/escaping/

Remember: You must use the most appropriate functions for the context. There is pretty much an option for everything you could echo. Even echoing HTML safely.

Example(s) from your plugin:
admin/class-servertrack-admin.php:341 echo '<div style="color:#10b981; font-weight:600; padding:10px 0;"><span class="dashicons dashicons-yes-alt"></span> ' . __( 'Purchase event successfully synced.', 'ratul-ads-conversion-tracker' ) . '</div>';
# ✨ Issue: translated text is output with __() without escaping; use esc_html__() before concatenating into HTML.
admin/class-servertrack-admin.php:344 echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center; margin-top:8px;">' . __( 'Fire Purchase Event anyway', 'ratul-ads-conversion-tracker' ) . '</a>';
# ✨ Issue: link text uses __() without escaping; href is escaped but visible text should use esc_html__().
admin/class-servertrack-admin.php:348 echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center;">' . __( 'Fire Purchase Event', 'ratul-ads-conversion-tracker' ) . '</a>';
# ✨ Issue: anchor text is not escaped because __() is used directly instead of esc_html__().
admin/class-servertrack-admin.php:343 echo '<div style="color:#ef4444; font-weight:600; padding:10px 0;"><span class="dashicons dashicons-warning"></span> ' . __( 'Order marked as fraud. Sync ignored.', 'ratul-ads-conversion-tracker' ) . '</div>';
# ✨ Issue: translated message is output with __() without escaping; use esc_html__() in HTML content.
admin/class-servertrack-admin.php:349 echo '<a href="' . esc_url( $fraud_url ) . '" class="button" style="width:100%; text-align:center; color:#ef4444; border-color:#ef4444;">' . __( 'Mark as Fraud', 'ratul-ads-conversion-tracker' ) . '</a>';
# ✨ Issue: button/link label uses __() without escaping; href is escaped but text should use esc_html__().
admin/class-servertrack-admin.php:346 echo '<p>' . __( 'Manual purchase mode is active. This order has not been synced to advertising platforms yet.', 'ratul-ads-conversion-tracker' ) . '</p>';
# ✨ Issue: paragraph text is output with __() without escaping; use esc_html__() for safe HTML text output.



Note: The function __ retrieves the translation without escaping, please either:
Use an alternative function that escapes the resulting value such as esc_html__ or esc_attr__ .
Or wrap the __ function with a proper escaping function such as esc_html , esc_attr , wp_kses_post , etc.
Examples:
<h2><?php echo esc_html__('Settings page', 'ratul-ads-conversion-tracker'); ?></h2>

<h2><?php echo esc_html(__('Settings page', 'ratul-ads-conversion-tracker')); ?></h2>

Example(s) from your plugin:
admin/class-servertrack-admin.php:348 echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center;">' . __( 'Fire Purchase Event', 'ratul-ads-conversion-tracker' ) . '</a>';
 -----> __('Fire Purchase Event', 'ratul-ads-conversion-tracker')
admin/class-servertrack-admin.php:346 echo '<p>' . __( 'Manual purchase mode is active. This order has not been synced to advertising platforms yet.', 'ratul-ads-conversion-tracker' ) . '</p>';
admin/class-servertrack-admin.php:341 echo '<div style="color:#10b981; font-weight:600; padding:10px 0;"><span class="dashicons dashicons-yes-alt"></span> ' . __( 'Purchase event successfully synced.', 'ratul-ads-conversion-tracker' ) . '</div>';
 -----> __('Purchase event successfully synced.', 'ratul-ads-conversion-tracker')
admin/class-servertrack-admin.php:344 echo '<a href="' . esc_url( $url ) . '" class="button button-primary" style="width:100%; text-align:center; margin-top:8px;">' . __( 'Fire Purchase Event anyway', 'ratul-ads-conversion-tracker' ) . '</a>';
 -----> __('Fire Purchase Event anyway', 'ratul-ads-conversion-tracker')
... out of a total of 6 incidences.

✔️ You can check this using Plugin Check.


## Do not use HEREDOC syntax in your plugins

While valid in PHP, these structures prevent automated code scanners from reliably detecting unescaped variables. This creates "blind spots" where security vulnerabilities (such as XSS) can easily go unnoticed. We feel the risk here is much higher than the benefits, which is why we don't permit its use.

Please use standard string concatenation (for short strings) or output buffering using ob_start() and ob_get_clean() (for long blocks). This approach preserves readability, enables proper syntax highlighting in your IDE, and ensures scanners can verify that your data is correctly escaped.

Example(s) from your plugin:
includes/class-servertrack-clickcapture.php:179 return <<<JS
(function(){
var p = new URLSearchParams(window.location.search);
var fbclid = p.get('fbclid') || '';
var ttclid = p.get('ttclid') || '';
var gclid  = p.get('gclid')  || '';
var fbc    = '';
var fbp    = '';
// Read cookies
document.cookie.split(';').forEach(function(c){
var kv = c.trim().split('=');
if(kv[0]==='_fbc')  fbc = decodeURIComponent(kv[1]||'');
if(kv[0]==='_fbp')  fbp = decodeURIComponent(kv[1]||'');
if(kv[0]==='ttclid'&&!ttclid) ttclid = decodeURIComponent(kv[1]||'');
});
// Build fbc from fbclid if cookie not set
if(fbclid && !fbc){
fbc = 'fb.1.' + Date.now() + '.' + fbclid;
}
if(!fbclid && !fbc && !fbp && !ttclid && !gclid) return;
var sid = '';
try{ sid = document.cookie.match(/wp_woocommerce_session_([^=]+)=([^;]+)/)||[]; sid = sid[2]||''; }catch(e){}
fetch('{$endpoint}', {
method: 'POST',
credentials: 'same-origin',
headers: {'Content-Type':'application/json'},
body: JSON.stringify({fbclid:fbclid,fbc:fbc,fbp:fbp,ttclid:ttclid,gclid:gclid,session_id:sid})
}).catch(function(){});
})();
JS;


✔️ You can check this using Plugin Check.


## Generic function/class/define/namespace/option names

All plugins must have unique function names, namespaces, defines, class and option names. This prevents your plugin from conflicting with other plugins or themes. We need you to update your plugin to use more unique and distinct names.

A good way to do this is with a prefix. For example, if your plugin is called "Ratuls- Ads Conversion Tracker" then you could use names like these:
function ratuadco_save_post(){ ... }
class RATUADCO_Admin { ... }
update_option( 'ratuadco_options', $options );
add_shortcode( 'ratuadco_shortcode', $callback );
register_setting( 'ratuadco_settings', 'ratuadco_user_id', ... );
define( 'RATUADCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
global $ratuadco_options;
add_action('wp_ajax_ratuadco_save_data', ... );
namespace ratul727\servertrack;

Disclaimer: These are just examples that may have been self-generated from your plugin name, we trust you can find better options. If you have a good alternative, please use it instead, this is just an example.

The prefix should be at least four (4) characters long (don't try to use two- or three-letter prefixes anymore). We host almost 100,000 plugins on WordPress.org alone. There are tens of thousands more outside our servers. Believe us, you're likely to encounter conflicts.

You also need to avoid the use of __ (double underscores), wp_ , or _ (single underscore) as a prefix. Those are reserved for WordPress itself. You can use them inside your classes, but not as stand-alone function.

Please remember, if you're using _n() or __() for translation, that's fine. We're only talking about functions you've created for your plugin, not the core functions from WordPress. In fact, those core features are why you need to not use those prefixes in your own plugin! You don't want to break WordPress for your users.

Related to this, using if (!function_exists('NAME')) { around all your functions and classes sounds like a great idea until you realize the fatal flaw. If something else has a function with the same name and their code loads first, your plugin will break. Using if-exists should be reserved for shared libraries only.

Remember: Good prefix names are unique and distinct to your plugin. This will help you and the next person in debugging, as well as prevent conflicts.

Analysis result:
# This plugin is using the prefixes "ratul-ads-conversion-tracker", "server_track" for 102 element(s).

# Using the common word "wc" as a prefix.
tests/bootstrap.php:64 class WC_Order
tests/bootstrap.php:82 class WC_Product
tests/bootstrap.php:217 class WCMock
tests/bootstrap.php:223 class WCSessionMock
# Using the common word "woo" as a prefix.
tests/bootstrap.php:54 class WooCommerce
tests/WooCommerceAddToCartTest.php:15 class WooCommerceAddToCartTest
tests/WooCommercePartialRefundTest.php:19 class WooCommercePartialRefundTest
tests/WooCommerceOrderStatusTest.php:17 class WooCommerceOrderStatusTest
tests/WooCommerceWishlistTest.php:18 class WooCommerceWishlistTest
# Using the common word "add" as a prefix.
tests/bootstrap.php:35 function add_action
tests/bootstrap.php:36 function add_filter
# Using the common word "check" as a prefix.
tests/bootstrap.php:43 function check_ajax_referer
# Using the common word "wp" as a prefix.
tests/bootstrap.php:44 function wp_send_json_success
tests/bootstrap.php:45 function wp_send_json_error
# Using the common word "test" as a prefix.
tests/Test_ServerTrack_License.php:5 class Test_ServerTrack_License

# Looks like there are elements not using common prefixes.
frontend/class-servertrack-frontend.php:199 set_transient($rate_key, $rate_count, MINUTE_IN_SECONDS);
# ↳ Detected name: st_rl_
tests/HasherTest.php:7 class HasherTest
tests/RetryTest.php:22 class RetryTest
tests/EnrichmentTest.php:7 class EnrichmentTest
tests/EventTest.php:13 class EventTest
tests/bootstrap.php:228 $wc_mock;
tests/CookieHelperTest.php:27 class CookieHelperTest
includes/class-servertrack-consent.php:155 set_transient('st_consent_' . $session_token, $consent, 24 * HOUR_IN_SECONDS);
includes/class-servertrack-proxy.php:63 set_transient($rate_key, ['tokens' => $refilled - 1, 'last_refill' => $now], MINUTE_IN_SECONDS);
# ↳ Detected name: st_proxy_rl_
includes/class-servertrack-enrichment.php:90 set_transient('st_session_ip_' . $session_id, $ip, DAY_IN_SECONDS);
includes/class-servertrack-enrichment.php:91 set_transient('st_session_ua_' . $session_id, $ua, DAY_IN_SECONDS);
includes/class-servertrack-attribution.php:98 set_transient(self::HISTORY_KEY . '_' . $session_id, $history, 30 * DAY_IN_SECONDS);
# ↳ Detected name: st_utm_history_
includes/class-servertrack-dedup-engine.php:46 set_transient($transient_key, true, self::BUCKET_SIZE * 2);
# ↳ Detected name: st_dedup__


Note: Options and Transients must be prefixed.

This is really important because the options are stored in a shared location and under the name you have set. If two plugins use the same name for options, they will find an interesting conflict when trying to read information introduced by the other plugin.

Also, once your plugin has active users, changing the name of an option is going to be really tricky, so let's make it robust from the very beginning.

Example(s) from your plugin:
frontend/class-servertrack-frontend.php:199 set_transient($rate_key, $rate_count, MINUTE_IN_SECONDS);
includes/class-servertrack-enrichment.php:91 set_transient('st_session_ua_' . $session_id, $ua, DAY_IN_SECONDS);
includes/class-servertrack-dedup-engine.php:46 set_transient($transient_key, true, self::BUCKET_SIZE * 2);
includes/class-servertrack-attribution.php:98 set_transient(self::HISTORY_KEY . '_' . $session_id, $history, 30 * DAY_IN_SECONDS);
... out of a total of 7 incidences.


## Allowing direct file access to plugin files

Direct file access occurs when someone directly queries a PHP file. This can be done by entering the complete path to the file in the browser's URL bar or by sending a POST request directly to the file.

For files that only contain class or function definitions, the risk of something funky happening when accessed directly is minimal. However, for files that contain executable code (e.g., function calls, class instance creation, class method calls, or inclusion of other PHP files), the risk of security issues is hard to predict because it depends on the specific case, but it can exist and it can be high.

You can easily prevent this by adding the following code at the beginning of all PHP files that could potentially execute code if accessed directly:
    if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
Add it after the <?php opening tag and after the namespace declaration, if any, but before any other code.

Example(s) from your plugin:
tests/EventTest.php:5 
tests/bootstrap.php:199 
tests/CookieHelperTest.php:5 
tests/EnrichmentTest.php:5 
tests/HasherTest.php:5 



👉 Continue with the review process.

Read this email thoroughly.

Please, take the time to fully understand the issues we've raised. Review the examples provided, read the relevant documentation, and research as needed. Our goal is for you to gain a clear understanding of the problems so you can address them effectively and avoid similar issues when maintaining your plugin in the future.
Note that there may be false positives - we are humans and make mistakes, we apologize if there is anything we have gotten wrong. If you have doubts you can ask us for clarification, when asking us please be clear, concise, direct and include an example.

📋 Complete your checklist.

✔️ I fixed all the issues in my plugin based on the feedback I received and my own review, as I know that the Plugins Team may not share all cases of the same issue. I am familiar with tools such as Plugin Check, PHPCS + WPCS, and similar utilities to help me identify problems in my code.
✔️ I tested my updated plugin on a clean WordPress installation with WP_DEBUG set to true.
⚠️ Do not skip this step. Testing is essential to make sure your fixes actually work and that you haven’t introduced new issues.

✔️ I acknowledge that this review will be rejected if I overlook the issues or fail to test my code.
✔️ I went to "Add your plugin" and uploaded the updated version. I can continue updating the code there throughout the review process — the team will always check the latest version.
✔️ I replied to this email. I was concise and shared any clarifications or important context that the team needed to know.
I didn't list all the changes, as the team will review the entire plugin again and that is not necessary at all.

ℹ️ To make this process as quick as possible and to avoid burden on the volunteers devoting their time to review this plugin's code, we ask you to thoroughly check all shared issues and fix them before sending the code back to us. I know we already asked you to do so, and it is because we are really trying to make it very clear.

While we try to make our reviews as exhaustive as possible we, like you, are humans and may have missed things. We appreciate your patience and understanding.

Review ID: R servertrack/ratul727/11May26/T3 8Jul26/3.9 (P0TDX311246HGN)


--
WordPress Plugins Team | plugins@wordpress.org
https://make.wordpress.org/plugins/
https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
https://wordpress.org/plugins/plugin-check/