<?php
/**
 * Bootstrap the native-process booking-mysql host proof.
 *
 * Chains the same WordPress-core-plus-DME boot `host-wordpress-bootstrap.php`
 * already provides (extrachill-events#882) with the multisite blog-7 booking
 * and promoter-authority schema provisioning `booking-schema-multisite-bootstrap.php`
 * provides — this suite's `BookingSchemaMultisiteTest` and
 * `PromoterAuthoritySchemaMultisiteTest` both assert against tables already
 * installed on blog 7 before their own test bodies run, the same precondition
 * the managed sandbox's `preload_files` already gives them there
 * (extrachill-events#870/#884).
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

require_once __DIR__ . '/host-wordpress-bootstrap.php';
require_once __DIR__ . '/booking-schema-multisite-bootstrap.php';
