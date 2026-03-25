# payroad/coingate-provider

CoinGate crypto payment provider for the [Payroad](https://github.com/payroad/payroad-core) platform.

## Features

- Crypto payment order creation via CoinGate API
- Webhook signature verification
- Status mapping (paid, expired, canceled, invalid)

> **Note:** CoinGate does not support refunds via API. Refunds must be handled manually through the CoinGate merchant dashboard.

## Requirements

- PHP 8.2+
- `payroad/payroad-core`

## Installation

```bash
composer require payroad/coingate-provider
```

## Configuration

```yaml
# config/packages/payroad.yaml
payroad:
  providers:
    coingate:
      factory: Payroad\Provider\CoinGate\CoinGateProviderFactory
      api_key:      '%env(COINGATE_API_KEY)%'
      callback_url: '%env(COINGATE_IPN_CALLBACK_URL)%'
      base_url:     '%env(COINGATE_BASE_URL)%'
```

## Payment flow

```
Customer                                Backend                   CoinGate
─────────────────────────────────────────────────────────────────────────────
POST /api/payments/crypto/initiate
  ← { paymentUrl }
Customer redirected to CoinGate
Customer pays in chosen crypto
                                                          POST /webhooks/coingate
                                                            status: paid
                                                              → Payment SUCCEEDED
```

## Implemented interfaces

| Interface | Description |
|-----------|-------------|
| `CryptoProviderInterface` | Payment order creation, webhook parsing |
