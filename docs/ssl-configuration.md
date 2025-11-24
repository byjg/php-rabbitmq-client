---
sidebar_position: 3
title: SSL/TLS Configuration
---

# SSL/TLS Configuration (AMQPS)

The RabbitMQ connector supports secure connections using TLS/SSL encryption via the AMQPS protocol. This ensures that all communication between your application and RabbitMQ is encrypted and secure.

## Overview

AMQPS connections use SSL/TLS encryption and require additional configuration parameters compared to standard AMQP connections. The connector supports mutual TLS authentication (mTLS) where both client and server verify each other's identity.

## Connection URI Format

AMQPS connections use a different protocol scheme and default port:

```php
$uri = "amqps://user:pass@host:5671/vhost?capath=/path/to/ca&param=value";
```

| Protocol | Default Port | Encryption |
|----------|--------------|------------|
| AMQP     | 5672         | None       |
| AMQPS    | 5671         | TLS/SSL    |

:::warning Required Parameter
The `capath` parameter is **mandatory** for AMQPS connections. The connector will throw an `InvalidArgumentException` if it's missing.
:::

## SSL/TLS Parameters

### capath (Required)

**Type:** String (file path)
**Required:** Yes
**Description:** Path to the CA (Certificate Authority) certificate directory or file. This is used to verify the server's certificate.

```php
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs";
```

:::info Certificate Formats
The CA certificate can be in PEM format. On most Linux systems, system CA certificates are located in `/etc/ssl/certs`.
:::

### local_cert

**Type:** String (file path)
**Required:** No (required for mutual TLS)
**Description:** Path to the client certificate file used for client authentication.

```php
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs&local_cert=/path/to/client-cert.pem";
```

When using mutual TLS (mTLS), the client must present a certificate to the server for authentication.

### local_pk

**Type:** String (file path)
**Required:** No (required for mutual TLS)
**Description:** Path to the client private key file.

```php
$uri = "amqps://user:pass@host:5671/" .
       "?capath=/etc/ssl/certs" .
       "&local_cert=/path/to/client-cert.pem" .
       "&local_pk=/path/to/client-key.pem";
```

:::warning Private Key Security
Keep your private key file secure with appropriate file permissions (e.g., `chmod 600`). Never commit private keys to version control.
:::

### passphrase

**Type:** String
**Required:** No
**Description:** The passphrase for the private key if it's encrypted.

```php
$uri = "amqps://user:pass@host:5671/" .
       "?capath=/etc/ssl/certs" .
       "&local_cert=/path/to/client-cert.pem" .
       "&local_pk=/path/to/client-key.pem" .
       "&passphrase=your-secret-passphrase";
```

:::danger Security Warning
Avoid hardcoding passphrases in your code. Use environment variables or secure configuration management systems.
:::

### verify_peer

**Type:** String (true/false)
**Default:** true
**Description:** Enable or disable verification of the peer's (server's) certificate.

```php
// Enable peer verification (recommended for production)
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs&verify_peer=true";

// Disable peer verification (only for development/testing)
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs&verify_peer=false";
```

:::danger Production Warning
Never disable peer verification in production environments. This makes your connection vulnerable to man-in-the-middle attacks.
:::

### verify_peer_name

**Type:** String (true/false)
**Default:** true
**Description:** Enable or disable verification of the peer's certificate name against the hostname.

```php
// Enable peer name verification (recommended)
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs&verify_peer_name=true";

// Disable peer name verification (use with caution)
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs&verify_peer_name=false";
```

This is useful when the certificate's Common Name (CN) or Subject Alternative Names (SAN) don't match the hostname you're connecting to.

### ciphers

**Type:** String
**Required:** No
**Description:** A list of ciphers to use for the encryption.

```php
$uri = "amqps://user:pass@host:5671/" .
       "?capath=/etc/ssl/certs" .
       "&ciphers=ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256";
```

:::tip Modern Ciphers
Use modern, secure cipher suites. Avoid deprecated ciphers like RC4, DES, or MD5-based ciphers.
:::

## Configuration Examples

### Basic AMQPS Connection

The simplest secure connection with server certificate verification:

```php
<?php
use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;

ConnectorFactory::registerConnector(RabbitMQConnector::class);

$uri = "amqps://user:password@rabbitmq.example.com:5671/production" .
       "?capath=/etc/ssl/certs";

$connector = ConnectorFactory::create(new Uri($uri));
```

### Mutual TLS (mTLS) Connection

Both client and server authenticate each other:

```php
<?php
$uri = "amqps://user:password@rabbitmq.example.com:5671/production" .
       "?capath=/etc/ssl/certs/ca-bundle.crt" .
       "&local_cert=/app/certs/client-cert.pem" .
       "&local_pk=/app/certs/client-key.pem" .
       "&verify_peer=true" .
       "&verify_peer_name=true";

$connector = ConnectorFactory::create(new Uri($uri));
```

### Development/Testing (Self-Signed Certificates)

For local development with self-signed certificates:

```php
<?php
$uri = "amqps://guest:guest@localhost:5671/" .
       "?capath=/path/to/self-signed-ca.crt" .
       "&verify_peer=true" .
       "&verify_peer_name=false";  // Disable if hostname doesn't match

$connector = ConnectorFactory::create(new Uri($uri));
```

:::warning Development Only
Only use relaxed verification settings in development. Production should always use proper certificate verification.
:::

### Encrypted Private Key

When your private key is password-protected:

```php
<?php
$passphrase = getenv('CLIENT_KEY_PASSPHRASE'); // Load from environment

$uri = "amqps://user:password@rabbitmq.example.com:5671/production" .
       "?capath=/etc/ssl/certs/ca-bundle.crt" .
       "&local_cert=/app/certs/client-cert.pem" .
       "&local_pk=/app/certs/client-key.pem" .
       "&passphrase=" . urlencode($passphrase);

$connector = ConnectorFactory::create(new Uri($uri));
```

### Custom Cipher Suites

Specify strong cipher suites for enhanced security:

```php
<?php
$ciphers = "ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-SHA384";

$uri = "amqps://user:password@rabbitmq.example.com:5671/production" .
       "?capath=/etc/ssl/certs" .
       "&verify_peer=true" .
       "&verify_peer_name=true" .
       "&ciphers=" . urlencode($ciphers);

$connector = ConnectorFactory::create(new Uri($uri));
```

## Certificate Setup

### Generating Self-Signed Certificates (Development Only)

For local development and testing:

```bash
# Generate CA private key
openssl genrsa -out ca-key.pem 4096

# Generate CA certificate
openssl req -new -x509 -days 3650 -key ca-key.pem -out ca-cert.pem

# Generate server private key
openssl genrsa -out server-key.pem 4096

# Generate server certificate signing request
openssl req -new -key server-key.pem -out server-csr.pem

# Sign server certificate with CA
openssl x509 -req -in server-csr.pem -CA ca-cert.pem -CAkey ca-key.pem \
  -CAcreateserial -out server-cert.pem -days 365

# Generate client private key
openssl genrsa -out client-key.pem 4096

# Generate client certificate signing request
openssl req -new -key client-key.pem -out client-csr.pem

# Sign client certificate with CA
openssl x509 -req -in client-csr.pem -CA ca-cert.pem -CAkey ca-key.pem \
  -CAcreateserial -out client-cert.pem -days 365
```

### Using System CA Certificates

On most Linux systems, you can use the system's CA certificates:

```php
// Debian/Ubuntu
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs";

// RHEL/CentOS
$uri = "amqps://user:pass@host:5671/?capath=/etc/pki/tls/certs";
```

## Security Best Practices

### ✅ Do's

1. **Always verify peer certificates in production**
   ```php
   verify_peer=true&verify_peer_name=true
   ```

2. **Use strong, modern cipher suites**
   ```php
   &ciphers=ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256
   ```

3. **Store credentials securely**
   ```php
   $username = getenv('RABBITMQ_USER');
   $password = getenv('RABBITMQ_PASSWORD');
   ```

4. **Protect private keys**
   ```bash
   chmod 600 /path/to/client-key.pem
   ```

5. **Use certificate expiration monitoring**
   ```bash
   openssl x509 -in client-cert.pem -noout -enddate
   ```

### ❌ Don'ts

1. **Never disable verification in production**
   ```php
   // NEVER DO THIS IN PRODUCTION
   verify_peer=false
   ```

2. **Never commit private keys to version control**
   ```gitignore
   # Add to .gitignore
   *.pem
   *.key
   ```

3. **Never hardcode passphrases**
   ```php
   // BAD: Hardcoded passphrase
   $uri = "...&passphrase=secret123";

   // GOOD: Use environment variables
   $uri = "...&passphrase=" . urlencode(getenv('KEY_PASSPHRASE'));
   ```

4. **Never use weak ciphers**
   ```php
   // NEVER use deprecated ciphers like RC4, DES, MD5
   ```

## Troubleshooting

### Certificate Verification Failed

```
SSL: certificate verify failed
```

**Solution:** Ensure the `capath` points to the correct CA certificate and that the server's certificate is signed by that CA.

```php
// Verify CA certificate path
$uri = "amqps://user:pass@host:5671/?capath=/correct/path/to/ca-cert.pem";
```

### Hostname Mismatch

```
SSL: certificate subject name does not match target host name
```

**Solution:** Either fix the certificate's CN/SAN to match the hostname, or disable peer name verification (not recommended for production):

```php
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs&verify_peer_name=false";
```

### Missing capath Parameter

```
InvalidArgumentException: The 'capath' parameter is required for AMQPS
```

**Solution:** Add the `capath` parameter to your connection URI:

```php
$uri = "amqps://user:pass@host:5671/?capath=/etc/ssl/certs";
```

### Private Key Passphrase Required

```
Unable to load private key
```

**Solution:** Add the passphrase parameter:

```php
$uri = "amqps://user:pass@host:5671/" .
       "?capath=/etc/ssl/certs" .
       "&local_cert=/path/to/cert.pem" .
       "&local_pk=/path/to/key.pem" .
       "&passphrase=" . urlencode(getenv('KEY_PASSPHRASE'));
```

## Testing Your SSL Connection

Test your AMQPS connection before deploying:

```php
<?php
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;

$uri = "amqps://user:password@rabbitmq.example.com:5671/production" .
       "?capath=/etc/ssl/certs" .
       "&local_cert=/app/certs/client-cert.pem" .
       "&local_pk=/app/certs/client-key.pem" .
       "&verify_peer=true" .
       "&verify_peer_name=true";

$connector = new RabbitMQConnector();
$connector->setUp(new Uri($uri));

if ($connector->testConnection()) {
    echo "✓ AMQPS connection successful!\n";
    echo "✓ Certificate verification passed\n";
    echo "✓ Encrypted connection established\n";
} else {
    echo "✗ Failed to establish AMQPS connection\n";
    echo "Check your certificates and configuration\n";
}
```

## See Also

- [Connection Parameters](connection-parameters.md) - General connection configuration
- [Robust Connection Setup](robust-connections.md) - Production-ready configurations
