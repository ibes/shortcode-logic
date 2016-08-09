=== Shortcode Logic ===
Contributors: ib4s
Tags: plugin logic, plugin organizer, shortcode, performance
Requires at least: 4.5.3
Tested up to: 4.5.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

If you use plugin "plugin logic" or "plugin organizer" than this plugin will help you make sure that shortcodes will be executed even if their plugins are not set to "active" for all posts. 

== Description ==
The plugins "plugin logic" and "plugin organizer" can help you deactivate/activate plugins on certain pages. 
On normal WP installations all plugins are active on all pages and can harm you performance a lot. The both plugins make it possible to just activate the plugins that are needed for that page. Activate WooCommerce only on the shop page and Contact Form7 only on the contact page. 

In our case we use polls, recipe views, forms etc. on some of our blog posts. So this plugins would need to be activated for all blog post or activated by hand for only the few post shortcodes are used. 

Shortcode logic does this job for you. 
Specify with shortcodes should be watched. Whenever a shortcode is used in a blog post shortcode logic will make sure the related plugin will be active - no matter what plugin logic or plugin organizer say. 

== Installation ==
Install shortcode logic like you are used to with other plugins. 

Go to "plugins" > "shortcode logic"
Select with shortcodes should be watched
(Re)generate the rules file for the first time

If you have trouble, delete the "shortcode-logic-rules.php" in your mu-plugins folder. 

Please let me know if you have some issues or improvement wishes. 
This plugin is mostly written for our needs but would be great to make it useful for others. 

mail [at] sebastian-gaertner.com