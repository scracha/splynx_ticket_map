<?php
// --- Splynx API Configuration ---

// Replace with your Splynx API base URL
$splynxBaseUrl = 'https://{SPLYNX_BASE_FQDN}/api/2.0';

// --- Splynx Admin URL ---
// You MUST set this to your Splynx Admin Portal URL for the Customer Name link to work.
$splynxAdminUrL = 'https://{SPLYNX_ADMIN_FQDN}';

// The base URL for the Splynx Customer/Admin portal
$splynxCustomerURL = "https://{SPLYNX_CUSTOMER_FQDN}";

// --- Dashboard Configuration ---
$dashboardTitle = '{YOUR_BUSINESS} NOC Dispatch';

// Replace with your Splynx API Key
$apiKey = '{SPLYNX_API_KEY}';

// Replace with your Splynx API Secret
$apiSecret = '{SPLYNX_API_SECRET}';

$googleApiKey = '{GOOGLE_API_KEY}' ;  // For geocoding should openstreetmap nominatim fail.


// --- Additional Customer Attributes Configuration ---
/**
 * Defines the customer additional attributes to be extracted from Splynx.
 * Key: The Splynx 'key' name of the additional attribute.
 * Value: The desired JSON key/label for the output data store.
 */
const CUSTOM_ATTRIBUTES = [
    '2ndcontactname'  => 'contact_2_name',
    '2ndcontactphone' => 'contact_2_phone',
];


// --- Daemon/Service Settings ---
// The hour (0-23) when the cron job should run. Default is 1 AM.
// If you set this via crontab, you can ignore this variable.
const UPDATE_TIME_HOUR = 1;

// Path to the shared memory file (fast, in-memory access)
const DATA_STORE_PATH = '/dev/shm/splynx_active_services.json';


?>
