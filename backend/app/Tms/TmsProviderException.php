<?php

namespace App\Tms;

/**
 * A TMS provider exception.
 *
 * The provider code is a stable machine-readable string the
 * service and the API surface to the caller: "live_unavailable"
 * means the provider is modelled but no live TMS can be
 * reached from here, "invalid_credentials" means the stored
 * credentials are missing or malformed, and "provider_error"
 * is any other failure the provider reports.
 */
class TmsProviderException extends \RuntimeException
{
    public const CODE_LIVE_UNAVAILABLE = 'live_unavailable';

    public const CODE_INVALID_CREDENTIALS = 'invalid_credentials';

    public const CODE_PROVIDER_ERROR = 'provider_error';

    public readonly string $providerCode;

    public function __construct(
        string $providerCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->providerCode = $providerCode;
    }
}
