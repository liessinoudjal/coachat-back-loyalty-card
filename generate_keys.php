<?php
// Generate JWT keys without passphrase
$config = [
    "digest_alg" => "sha256",
    "private_key_bits" => 4096,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
];

$privateKey = openssl_pkey_new($config);
if (!$privateKey) {
    die("Failed to generate private key: " . openssl_error_string() . "\n");
}

if (!openssl_pkey_export($privateKey, $privateKeyPem)) {
    die("Failed to export private key: " . openssl_error_string() . "\n");
}

$details = openssl_pkey_get_details($privateKey);
if (!$details) {
    die("Failed to get key details: " . openssl_error_string() . "\n");
}

$publicKey = $details['key'];

file_put_contents('config/jwt/private.pem', $privateKeyPem);
file_put_contents('config/jwt/public.pem', $publicKey);

echo "JWT keys generated successfully\n";