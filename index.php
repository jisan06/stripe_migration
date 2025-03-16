<?php
require_once 'package/stripe/init.php';

$host = "localhost";  // Change if needed
$username = "root";   // Default for local setup
$password = "mysql";       // Empty for XAMPP/Laragon (modify if needed)
$database = "ait_system"; // Your database name

\Stripe\Stripe::setApiKey('sk_test_2jmwp9D5RaBaGEKT2gs9UpTL00l1usSfAl');

$product_id = 'prod_RxCXozfAQKeR8W';

// Create a connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get table data
$sql = "SELECT 
    s.id, 
       s.user_id, 
       s.gateway_customer_id, 
       s.gateway_product_id,
       s.gateway_price_id,
       s.subscription_type_id, 
       s.payment_type_id, 
       s.product_id, 
       s.bundle_id, 
       s.active, 
       s.upgrade, 
       s.registered, 
       s.`from`, 
       s.`to`, 
       s.duration, 
       s.transaction_id, 
       s.price, 
       s.price_calculation, 
       s.renewed, 
       s.psd_included, 
       s.in_bundle, 
       s.from_support, 
       s.agency, 
       s.autorenew, 
       s.autorenew_canceled_at, 
       s.autorenewed_from, 
       s.licenses,
        user.email,            -- Customer's email (needed for Stripe)
        user.user_name,            -- Customer's email (needed for Stripe)
       user.address,          -- Customer's address (needed for Stripe Tax)
       user.city,             -- Customer's city (needed for Stripe Tax)
       user.state,
       country.name as country_name
FROM subscription as s
JOIN user ON s.user_id = user.id 
JOIN country ON user.country_id = country.id 
         where 
        `to` < CURDATE()  -- Subscription expired
        AND active = 1    -- Only active subscriptions
        AND renewed = 0
        AND payment_type_id = 5 
        LIMIT 1 , 1
        ";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    // Set the headers to force the file download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscriptions.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Open output stream to PHP's output
    $output = fopen('php://output', 'w');

    // Define the header of the CSV file
    $header = [
        'customer',                       // Stripe Customer ID
        'start_date',                      // Unix timestamp for start date
        'items.0.price',                           // Stripe Price ID
//        'items.0.quantity',                        // Default quantity (1 for one subscription)
        'add_invoice_items.0.product',     // Product ID for added invoice item
//        'add_invoice_items.0.amount',      // Amount to add to invoice (in cents)
        'add_invoice_items.0.currency',     // Currency (e.g., usd)
//        'automatic_tax',                   // Stripe tax (true/false)
        'cancel_at_period_end',            // If true, the subscription will cancel at the end of the period
//        'billing_cycle_anchor',            // Unix timestamp for billing cycle anchor
//        'coupon',                          // Coupon ID if applicable
//        'trial_end',                       // Unix timestamp for trial end (0 if no trial)
        'proration_behavior',              // Proration behavior (create_prorations or none)
        'collection_method',               // Method of collection (charge_automatically or send_invoice)
//        'default_tax_rate',                // Stripe Tax Rate ID
        'backdate_start_date',             // Optional backdate start date (if needed)
        'metadata.third_party_sub_id',     // Unique subscription ID (from your system)
    ];

    // Write the header to the CSV
    fputcsv($output, $header);

    // Fetch the rows and write each to the CSV
    while ($row = $result->fetch_assoc()) {

        // Calculate amount in cents
        $amount = $row['price'] * 100;

        // Set currency (USD as default, change if necessary)
        $currency = 'usd';  // Adjust if you need a different currency
        $backdate_start_date = strtotime($row['from']);
        // Ensure the start date is at least 1 hour into the future
        $start_date = time() + 7200;
        $end_date = strtotime($row['to']);
        $cancel_at_period_end = $end_date;
//        $trial_end = isset($row['trial_end']) ? strtotime($row['trial_end']) : 0;
//        $trial_end = ($trial_end > $start_date) ? $trial_end : ($start_date + 86400 * 7);  // 7 days later if no trial

        // Ensure billing_cycle_anchor is after trial_end
//        $billing_cycle_anchor = ($trial_end > 0) ? ($trial_end + 3600) : $start_date;

        // Set collection_method to 'charge_automatically' or 'send_invoice'
        $collection_method = 'charge_automatically';  // Adjust as needed

        // Set proration_behavior
        $proration_behavior = 'none';  // Adjust if you want to create prorations

        // Set days_until_due (only if collection_method is 'send_invoice')
        $days_until_due = '';  // Empty if using 'charge_automatically'

        // Metadata third_party_sub_id
        $third_party_sub_id = $row['transaction_id'];  // Assuming this maps to subscription ID

    // Use the new customer ID
        $stripe_customer_id = get_customer_id($row);

        $strip_price_id = get_price_id($product_id, $row);
        $strip_price_id = !$strip_price_id ? $row['gateway_price_id'] : $strip_price_id;

        $updates = [];
        if ($stripe_customer_id !== $row['gateway_customer_id']) {
            $updates[] = "gateway_customer_id = '{$stripe_customer_id}'";
        }
        if ($product_id !== $row['gateway_product_id']) {
            $updates[] = "gateway_product_id = '{$product_id}'";
        }
        if ($strip_price_id !== $row['gateway_price_id']) {
            $updates[] = "gateway_price_id = '{$strip_price_id}'";
        }

        if (!empty($updates)) {
            $update_query = "UPDATE subscription SET " . implode(', ', $updates) . " WHERE id = {$row['id']}";
            $conn->query($update_query);
        }

        // Prepare the data row
        $data = [
            $stripe_customer_id,                     // Stripe Customer ID (not user_id)
            $start_date,                          // Unix timestamp for start date
            $strip_price_id,                            // The Stripe Price ID
//            '1',                                    // Default quantity is 1 for a single subscription
            $product_id,                          // The product ID in Stripe
//            $amount,                              // Amount to charge (in cents)
            $currency,                            // Currency
//            'false',                              // Set to 'true' if Stripe tax is enabled
            $cancel_at_period_end,                // If the subscription will cancel at the end
//            $billing_cycle_anchor,                 // Use 'from' as the billing cycle anchor
//            '',                                   // Coupon ID (if applicable, can be left empty)
//            $trial_end,                           // Trial end (set to 0 if no trial)
            $proration_behavior,                  // Proration behavior (none or create_prorations)
            $collection_method,                   // Collection method (charge_automatically or send_invoice)
//            '',                                   // Default tax rate (if applicable, can be left empty)
            $backdate_start_date,                                   // Backdate start date (if applicable)
            $third_party_sub_id,                  // Unique subscription ID (metadata.third_party_sub_id)
        ];

        // Write the row to the CSV
        fputcsv($output, $data);
    }

    // Close the output stream
    fclose($output);
    exit; // Ensure no further output is sent
} else {
    echo "No results found.";
}

// Close the database connection
$conn->close();

// Main function to get or create a customer ID
function get_customer_id($row)
{
    // Check if gateway_customer_id is empty
    if (empty($row['gateway_customer_id'])) {
        // Search for an existing customer by email
        $existingCustomerId = findCustomerByEmail($row['email']);

        // If a customer with the same email exists, return their ID
        if ($existingCustomerId) {
            return $existingCustomerId;
        }

        // If no customer exists, create a new one
        return createCustomer($row['email'], $row['user_name']);
    } else {
        try {
            // Retrieve the existing customer from Stripe
            $existing_customer = \Stripe\Customer::retrieve($row['gateway_customer_id']);

            // Check if the customer exists and has not been deleted
            if ($existing_customer && !isset($existing_customer->deleted)) {
                return $row['gateway_customer_id'];
            }

            // If the customer is deleted or invalid, search by email
            $existingCustomerId = findCustomerByEmail($row['email']);

            // If a customer with the same email exists, return their ID
            if ($existingCustomerId) {
                return $existingCustomerId;
            }

            // If no customer exists, create a new one
            return createCustomer($row['email'], $row['user_name']);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Handle the case where the customer ID is invalid or does not exist
            if ($e->getHttpStatus() === 404) {
                // Search for an existing customer by email
                $existingCustomerId = findCustomerByEmail($row['email']);

                // If a customer with the same email exists, return their ID
                if ($existingCustomerId) {
                    return $existingCustomerId;
                }

                // If no customer exists, create a new one
                return createCustomer($row['email'], $row['user_name']);
            } else {
                // Re-throw the exception if it's not a 404 error
                throw $e;
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Handle other Stripe API errors
            throw $e;
        }
    }
}

// Helper function to search for a customer by email
function findCustomerByEmail($email) {
    try {
        $existingCustomers = \Stripe\Customer::all([
            'email' => $email,
            'limit' => 1,
        ]);
        return !empty($existingCustomers->data) ? $existingCustomers->data[0]->id : null;
    } catch (\Stripe\Exception\ApiErrorException $e) {
        // Handle Stripe API errors
        throw $e;
    }
}

// Helper function to create a new customer
function createCustomer($email, $name) {
    try {
        $customer = \Stripe\Customer::create([
            'email' => $email,
            'name' => $name,
        ]);
        return $customer->id;
    } catch (\Stripe\Exception\ApiErrorException $e) {
        // Handle Stripe API errors
        throw $e;
    }
}

function get_price_id($product_id, $row)
{
    $unit_amount = $row['price'] * 100;
    $prices = \Stripe\Price::all([
        'product' => $product_id, // Product ID
        'active' => true, // Only active Prices
    ]);

    // Step 2: Check if a Price with the same amount, currency, and interval exists
    foreach ($prices->data as $price) {
        if ($price->unit_amount == $unit_amount &&
            $price->currency == 'usd' &&
            $price->recurring->interval == 'year') {
            return $price->id; // Return existing Price ID
        }
    }

    $price = \Stripe\Price::create([
        'product' => $product_id, // Replace with your Product ID
        'unit_amount' => $unit_amount, // Price in cents (e.g., $149.00)
        'currency' => 'usd', // Currency
        'recurring' => [
            'interval' => 'year', // Billing interval (e.g., 'month' or 'year')
        ],
    ]);

    if($price->id) {
        return $price->id;
    }
}

?>
