<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '<`&0UX9[#lp35|:k6#ZV<~U1MyB_LHIi[H|Y`?&2m!8lZcm@ma#{(.NjG+Bf?5Ob' );
define( 'SECURE_AUTH_KEY',  '%Y:da3_`z~8xA:iL$+=Aokk(x,9HM/y*]JZ)^hVKUq01xO`H>NLIi&T.x x:So#F' );
define( 'LOGGED_IN_KEY',    't5i<P?ZC^K@oC-tTPK%#@[>6ySI8IYpv_CR#;r*-Xasiwpsa[|C:04e6l=(S?=gH' );
define( 'NONCE_KEY',        'v?|!$_kf@0!L5jQq{[rDy[BmmA[,46]OM^;2Y|:R=co4#VRn09L!s]~64[)dTesU' );
define( 'AUTH_SALT',        'Q[n12Rd+)9=E+i!l>0vF.O}`Jq#vG2AAz[~k fJ7CBGq;<jns&94KLRtmNEqih;=' );
define( 'SECURE_AUTH_SALT', 'LP6{e8D-Y!:(6wqpqQj#2bNL>iSGKh3>MjEErx2.&[5l1Zj;pHY6kiXx%{]B;,3k' );
define( 'LOGGED_IN_SALT',   'k(+#}w{)7FVbX|PN^T9,Z^t)-={`XU~cD?j!D)D|-vh!1=.xvO.F+aioK^)*{UZ5' );
define( 'NONCE_SALT',       '8P!QiU}rhrgwsP.#WI0:%^4fiI;6E$?P$k`l@>-ms39YQ!D/-fvz$b_~%e!B;usa' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
