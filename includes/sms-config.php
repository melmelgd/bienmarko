<?php
/**
 * includes/sms-config.php
 * -----------------------------------------------------------------------
 * Settings for the booking SMS messages (same iProg SMS account that
 * forgot-password.php already uses for password-reset codes).
 *
 *   1. Paste the SAME iProg API token that is in forgot-password.php
 *      (FP_IPROG_TOKEN) into 'api_token' below, OR set the IPROG_API_TOKEN
 *      environment variable on your server.
 *   2. Keep this file private - never commit a real token to Git.
 * -----------------------------------------------------------------------
 */
return [
    // Master switch. false = no booking SMS are sent (dashboard notifications still work).
    'enabled'    => true,

    // iProg API token (environment variable wins if it is set).
    'api_token'  => getenv('IPROG_API_TOKEN') ?: 'cc54e13d90c58456917e81bcac4c6c2a0c648e74',

    'api_url'    => 'https://sms.iprogtech.com/api/v1/sms_messages',

    // Set to false ONLY on local XAMPP if cURL has no CA bundle (same as FP_VERIFY_SSL).
    'verify_ssl' => true,

    'timeout'    => 15,

    // Name shown at the start of each text. Leave '' to use the property name
    // saved in Admin > Settings.
    'site_name'  => '',
];
