<?php
/*!
* HybridAuth
* http://hybridauth.sourceforge.net | http://github.com/hybridauth/hybridauth
* (c) 2009-2012, HybridAuth authors | http://hybridauth.sourceforge.net/licenses.html
*/

// ----------------------------------------------------------------------------------------
//	HybridAuth Config file: http://hybridauth.sourceforge.net/userguide/Configuration.html
// ----------------------------------------------------------------------------------------

return
	array(
		"base_url" => "http://bagshop.com.ua/index.php?route=hybrid/process",

		"providers" => array (
			// openid providers

			"Google" => array (
				"enabled" => true,
				"keys"    => array ( "id" => "882463562448-qjr85dar8f3t4poa9dgke23leb36q2po.apps.googleusercontent.com", "secret" => "YZQRn7gxIamwfuCtQ-nmHPT6" ),
			),

			"Facebook" => array (
				"enabled" => true,
				"keys"    => array ( "id" => "", "secret" => "" ),
			),

			"Twitter" => array (
				"enabled" => true,
				"keys"    => array ( "key" => "3YVsLzawYgSxnepYbyC0UVL0l", "secret" => "I4TMlWh9VrBi6yZ1VtYeOePhUyT6K9nmeuKANL41zorjHKWULj" )
			),
		),

		// if you want to enable logging, set 'debug_mode' to true  then provide a writable file by the web server on "debug_file"
		"debug_mode" => false,

		"debug_file" => "",
	);
