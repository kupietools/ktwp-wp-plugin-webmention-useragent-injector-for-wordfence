# ktwp-wp-plugin-webmention-useragent-injector-for-wordfence

**Use of this plugin is entirely at your own risk.** This plugin modifies your apache .htaccess file. This plugin can destroy your site if something goes wrong, and is not guaranteed to work properly. Make sure you have adequate backups to fully restore your site to working order. Plugin's author is not liable for any consequences resulting from your choice to install or use this plugin. This plugin is provided as-is. 

This is a WordPress plugin to modify your .htaccess file inject a useragent into incoming webmention requests, so as not to trip WordFence's IP block against POST requests without a useragent or referrer.

This plugin requires you to hardcode your webmention endpoint into the plugin before activation. In the future, an admin settings screen might be added, but probably not. Owing to the security risk, better to have it hard-coded in the plugin. 

If you need to change the webmention endpoint, deactivating the plugin, updating the endpoint specified in the code, and reactivating it should suffice. 

This plugin makes every attempt to clean up after itself on deactivation. 

As in life, nothing about this plugin or its associated files is guaranteed. 

Requires Apache 2.4+ with mod_headers enabled.
